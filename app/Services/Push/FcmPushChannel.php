<?php

namespace App\Services\Push;

use App\Models\PanelPushSubscription;
use App\Services\Push\Contracts\PanelPushChannel;
use App\Support\PanelPwaIconUrls;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;

class FcmPushChannel implements PanelPushChannel
{
    /**
     * @param  Collection<int, PanelPushSubscription>  $subscriptions
     */
    public function send(Collection $subscriptions, string $title, string $body, ?string $url = null): array
    {
        $fcmSubs = $subscriptions->filter(fn (PanelPushSubscription $s) => $s->isFcm() && $s->fcm_token);
        $total = $fcmSubs->count();

        $serviceAccountJson = config('getfy.pwa.firebase_service_account');
        if (! is_string($serviceAccountJson) || trim($serviceAccountJson) === '') {
            Log::warning('FcmPushChannel: service account não configurado');

            return ['sent' => 0, 'failed' => 0, 'invalid' => 0, 'expired' => 0, 'total' => $total];
        }

        try {
            $factory = (new Factory)->withServiceAccount($serviceAccountJson);
            $messaging = $factory->createMessaging();
        } catch (\Throwable $e) {
            Log::error('FcmPushChannel: falha ao inicializar Firebase', ['message' => $e->getMessage()]);

            return ['sent' => 0, 'failed' => 0, 'invalid' => 0, 'expired' => 0, 'total' => $total];
        }

        $icon = PanelPwaIconUrls::primaryNotificationIconUrl();
        $sent = 0;
        $failedCount = 0;
        $invalidCount = 0;
        $expiredCount = 0;

        foreach ($fcmSubs as $sub) {
            $token = trim((string) $sub->fcm_token);
            if ($token === '') {
                $invalidCount++;
                continue;
            }

            try {
                $message = CloudMessage::fromArray([
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'data' => array_filter([
                        'title' => $title,
                        'body' => $body,
                        'url' => $url ?? '',
                        'icon' => $icon,
                        'badge' => $icon,
                    ], fn ($v) => $v !== null && $v !== ''),
                ]);

                $messaging->send($message);
                $sent++;
                $sub->update(['last_used_at' => now()]);
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                if ($this->isExpiredToken($e)) {
                    $expiredCount++;
                    $sub->delete();
                    Log::info('FcmPushChannel: token inválido removido', ['subscription_id' => $sub->id]);
                } else {
                    $failedCount++;
                    Log::warning('FcmPushChannel: falha no envio', [
                        'subscription_id' => $sub->id,
                        'message' => $message,
                    ]);
                }
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failedCount,
            'invalid' => $invalidCount,
            'expired' => $expiredCount,
            'total' => $total,
        ];
    }

    private function isExpiredToken(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'not-found')
            || str_contains($msg, 'not found')
            || str_contains($msg, 'invalid-registration')
            || str_contains($msg, 'unregistered')
            || str_contains($msg, 'requested entity was not found');
    }
}
