<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $triggerType = $this->triggerTypeForOrder($order);
        $eligibleEntries = [];
        $hasPixelWithoutToken = false;

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $pixelId = trim((string) ($entry['pixel_id'] ?? ''));
            if ($pixelId === '') {
                continue;
            }
            $accessToken = trim((string) ($entry['access_token'] ?? ''));
            if ($accessToken === '') {
                $hasPixelWithoutToken = true;

                continue;
            }
            if (! $this->shouldSendForEntry($entry, $triggerType)) {
                continue;
            }
            $eligibleEntries[] = $entry;
        }

        if ($eligibleEntries === []) {
            if ($hasPixelWithoutToken) {
                $this->recordSkippedReason($order, 'missing_access_token');
            }

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
            'em' => $email ? [hash('sha256', strtolower(trim((string) $email)))] : null,
            'ph' => $phone ? [hash('sha256', preg_replace('/\D/', '', (string) $phone) ?? '')] : null,
            'client_ip_address' => $ip,
            'client_user_agent' => $ua,
            'fbp' => $fbp ?: null,
            'fbc' => $fbc ?: null,
        ]);

        $out = [];
        foreach ($eligibleEntries as $entry) {
            $pixelId = trim((string) ($entry['pixel_id'] ?? ''));
            $accessToken = trim((string) ($entry['access_token'] ?? ''));

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

    /**
     * Alinhado a ConversionPixels.vue shouldFireForEntry (triggerType pix/boleto/approved).
     *
     * @param  array<string, mixed>  $entry
     */
    private function shouldSendForEntry(array $entry, string $triggerType): bool
    {
        if ($triggerType === 'pix' && ($entry['fire_purchase_on_pix'] ?? true) === false) {
            return false;
        }
        if ($triggerType === 'boleto' && ($entry['fire_purchase_on_boleto'] ?? true) === false) {
            return false;
        }

        return true;
    }

    private function triggerTypeForOrder(Order $order): string
    {
        $method = (string) ($order->payment_method ?? '');
        if ($method === 'boleto') {
            return 'boleto';
        }
        if (in_array($method, ['pix', 'pix_auto'], true)) {
            return 'pix';
        }

        return 'approved';
    }

    private function recordSkippedReason(Order $order, string $reason): void
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        if (($meta['meta_capi_skipped_reason'] ?? null) === $reason) {
            return;
        }

        $meta['meta_capi_skipped_reason'] = $reason;
        $meta['meta_capi_skipped_at'] = now()->toIso8601String();
        $order->update(['metadata' => $meta]);

        Log::warning('Meta CAPI purchase skipped', [
            'order_id' => $order->id,
            'tenant_id' => $order->tenant_id,
            'reason' => $reason,
        ]);
    }
}
