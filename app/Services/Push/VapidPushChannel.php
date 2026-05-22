<?php

namespace App\Services\Push;

use App\Models\PanelPushSubscription;
use App\Services\Push\Contracts\PanelPushChannel;
use App\Support\PanelPwaIconUrls;
use App\Support\VapidEnvKeys;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

class VapidPushChannel implements PanelPushChannel
{
    /**
     * @param  Collection<int, PanelPushSubscription>  $subscriptions
     */
    public function send(Collection $subscriptions, string $title, string $body, ?string $url = null): array
    {
        $vapidSubs = $subscriptions->filter(fn (PanelPushSubscription $s) => ! $s->isFcm());
        $total = $vapidSubs->count();

        $vapidPublic = VapidEnvKeys::normalize(config('getfy.pwa.vapid_public'));
        $vapidPrivate = VapidEnvKeys::normalize(config('getfy.pwa.vapid_private'));

        if (! $vapidPublic || ! $vapidPrivate) {
            Log::warning('VapidPushChannel: VAPID não configurado');

            return ['sent' => 0, 'failed' => 0, 'invalid' => 0, 'expired' => 0, 'total' => $total];
        }

        $subject = 'mailto:'.(config('mail.from.address') ?: 'noreply@'.parse_url((string) config('app.url'), PHP_URL_HOST));

        try {
            VAPID::validate([
                'subject' => $subject,
                'publicKey' => $vapidPublic,
                'privateKey' => $vapidPrivate,
            ]);
        } catch (\Throwable $e) {
            Log::error('VapidPushChannel: par VAPID inválido', ['message' => $e->getMessage()]);

            return ['sent' => 0, 'failed' => 0, 'invalid' => 0, 'expired' => 0, 'total' => $total];
        }

        $auth = [
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => $vapidPublic,
                'privateKey' => $vapidPrivate,
            ],
        ];

        $icon = PanelPwaIconUrls::primaryNotificationIconUrl();
        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'icon' => $icon,
            'badge' => $icon,
        ]);

        $sent = 0;
        $invalidCount = 0;
        $failedCount = 0;
        $expiredCount = 0;

        try {
            $webPush = new WebPush($auth);
            foreach ($vapidSubs as $sub) {
                $keys = $sub->keys ?? [];
                $authKey = trim((string) ($keys['auth'] ?? ''));
                $p256dh = trim((string) ($keys['p256dh'] ?? ''));
                if (! $sub->endpoint || $authKey === '' || $p256dh === '') {
                    $invalidCount++;
                    continue;
                }
                $subscription = Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'keys' => [
                        'auth' => $this->normalizeBase64KeyForPush($authKey),
                        'p256dh' => $this->normalizeBase64KeyForPush($p256dh),
                    ],
                ]);
                try {
                    $report = $webPush->sendOneNotification($subscription, $payload);
                    if ($report->isSuccess()) {
                        $sent++;
                        $sub->update(['last_used_at' => now()]);
                    } elseif ($report->isSubscriptionExpired()) {
                        $expiredCount++;
                        $sub->delete();
                    } else {
                        $failedCount++;
                    }
                } catch (\Throwable $e) {
                    $failedCount++;
                    Log::warning('VapidPushChannel: falha no envio', [
                        'subscription_id' => $sub->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('VapidPushChannel: erro geral', ['message' => $e->getMessage()]);
        }

        return [
            'sent' => $sent,
            'failed' => $failedCount,
            'invalid' => $invalidCount,
            'expired' => $expiredCount,
            'total' => $total,
        ];
    }

    private function normalizeBase64KeyForPush(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return $key;
        }
        if (str_contains($key, '+') || str_contains($key, '/')) {
            return strtr($key, ['+' => '-', '/' => '_']);
        }

        return $key;
    }
}
