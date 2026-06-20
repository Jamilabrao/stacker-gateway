<?php

namespace App\Services;

use App\Models\MetaTrackingEvent;
use App\Models\Order;
use App\Services\Meta\MetaTrackingService;

/**
 * Backward-compatible sync sender used in tests and diagnostics.
 * Production Purchase flow uses MetaConversionsSendPurchaseJob → SendMetaTrackingEventJob.
 */
class MetaConversionsService
{
    public function __construct(
        private MetaTrackingService $trackingService,
    ) {}

    /**
     * Envia evento Purchase via Meta Conversion API (síncrono, sem enfileirar).
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

        $triggerType = $this->trackingService->triggerTypeForOrder($order);
        $eligibleEntries = $this->trackingService->eligibleMetaEntries($pixels, 'Purchase', $triggerType);

        $hasPixelWithoutToken = false;
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $pixelId = trim((string) ($entry['pixel_id'] ?? ''));
            $accessToken = trim((string) ($entry['access_token'] ?? ''));
            if ($pixelId !== '' && $accessToken === '') {
                $hasPixelWithoutToken = true;
            }
        }

        if ($eligibleEntries === []) {
            if ($hasPixelWithoutToken) {
                $this->trackingService->recordOrderSkippedReason($order, 'missing_access_token');
            }

            return [];
        }

        $eventId = MetaTrackingService::eventId('purchase', ['order_id' => $order->id]);
        $out = [];

        foreach ($eligibleEntries as $entry) {
            $pixelId = $entry['pixel_id'];

            $record = MetaTrackingEvent::query()->firstOrCreate(
                [
                    'event_id' => $eventId,
                    'pixel_id' => $pixelId,
                ],
                [
                    'tenant_id' => $order->tenant_id ? (int) $order->tenant_id : null,
                    'event_name' => 'Purchase',
                    'context_type' => MetaTrackingEvent::CONTEXT_ORDER,
                    'context_id' => (int) $order->id,
                    'status' => MetaTrackingEvent::STATUS_PENDING,
                    'attempts' => 0,
                ]
            );

            if ($record->status === MetaTrackingEvent::STATUS_SENT) {
                $out[] = [
                    'pixel_id' => $pixelId,
                    'ok' => true,
                    'status' => 200,
                    'body' => null,
                    'error' => null,
                ];

                continue;
            }

            $record->increment('attempts');
            $result = $this->trackingService->sendTrackingEventRecord($record);

            if ($result['ok']) {
                $record->update([
                    'status' => MetaTrackingEvent::STATUS_SENT,
                    'sent_at' => now(),
                    'response_body' => isset($result['body']) ? mb_substr((string) $result['body'], 0, 2000) : null,
                    'last_error' => null,
                ]);
            } else {
                $record->update([
                    'last_error' => mb_substr((string) ($result['error'] ?? 'meta_api_error'), 0, 500),
                    'response_body' => isset($result['body']) ? mb_substr((string) $result['body'], 0, 2000) : null,
                ]);
            }

            $out[] = [
                'pixel_id' => $pixelId,
                'ok' => $result['ok'],
                'status' => $result['status'],
                'body' => $result['body'],
                'error' => $result['error'],
            ];
        }

        $okAny = count(array_filter($out, fn ($x) => ($x['ok'] ?? false) === true)) > 0;
        if ($okAny) {
            $this->trackingService->markOrderPurchaseSent($order);
        }

        return $out;
    }
}
