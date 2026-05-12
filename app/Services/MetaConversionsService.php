<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;

class MetaConversionsService
{
    /**
     * Envia evento Purchase via Meta Conversion API para todos os pixels configurados no pedido.
     *
     * @return array<int, array{pixel_id: string, ok: bool, status: int|null, body: string|null, error: string|null}>
     */
    public function sendPurchaseForOrder(Order $order): array
    {
        $order->loadMissing(['product', 'user', 'checkoutSession']);

        $pixels = AffiliateConversionPixels::forOrder($order);
        $meta = is_array($pixels['meta'] ?? null) ? $pixels['meta'] : [];
        $enabled = (bool) ($meta['enabled'] ?? false);
        $entries = isset($meta['entries']) && is_array($meta['entries']) ? $meta['entries'] : [];
        if (! $enabled || $entries === []) {
            return [];
        }

        $eventId = 'order:'.$order->id;
        $eventTime = (int) ($order->updated_at?->timestamp ?? time());

        $currency = 'BRL';
        $amount = (float) $order->amount;
        $customData = [
            'currency' => $currency,
            'value' => round(max(0, $amount), 2),
            'order_id' => (string) $order->id,
        ];

        $metaArr = is_array($order->metadata) ? $order->metadata : [];
        $fbp = isset($metaArr['fbp']) && is_string($metaArr['fbp']) ? trim($metaArr['fbp']) : null;
        $fbc = isset($metaArr['fbc']) && is_string($metaArr['fbc']) ? trim($metaArr['fbc']) : null;
        $ua = isset($metaArr['user_agent']) && is_string($metaArr['user_agent']) ? trim($metaArr['user_agent']) : null;

        $ip = $order->customer_ip ?: null;

        $email = $order->email ?: ($order->user?->email ?? null);
        $phone = $order->phone ?: null;

        $userData = array_filter([
            // Normalmente a Meta aceita unhashed e hasheia; para evitar inconsistências,
            // enviamos hashed quando possível.
            'em' => $email ? [hash('sha256', strtolower(trim((string) $email)))] : null,
            'ph' => $phone ? [hash('sha256', preg_replace('/\D/', '', (string) $phone) ?? '')] : null,
            'client_ip_address' => $ip,
            'client_user_agent' => $ua,
            'fbp' => $fbp ?: null,
            'fbc' => $fbc ?: null,
        ]);

        $out = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $pixelId = trim((string) ($entry['pixel_id'] ?? ''));
            $accessToken = trim((string) ($entry['access_token'] ?? ''));
            if ($pixelId === '' || $accessToken === '') {
                continue;
            }

            $payload = [
                'data' => [[
                    'event_name' => 'Purchase',
                    'event_time' => $eventTime,
                    'event_id' => $eventId,
                    'action_source' => 'website',
                    'user_data' => $userData,
                    'custom_data' => $customData,
                ]],
            ];

            $url = sprintf('https://graph.facebook.com/v20.0/%s/events', urlencode($pixelId));
            try {
                $resp = Http::timeout(12)->asJson()->post($url, $payload + [
                    'access_token' => $accessToken,
                ]);
                $out[] = [
                    'pixel_id' => $pixelId,
                    'ok' => $resp->successful(),
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                    'error' => $resp->successful() ? null : 'meta_api_error',
                ];
            } catch (\Throwable $e) {
                $out[] = [
                    'pixel_id' => $pixelId,
                    'ok' => false,
                    'status' => null,
                    'body' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $out;
    }
}

