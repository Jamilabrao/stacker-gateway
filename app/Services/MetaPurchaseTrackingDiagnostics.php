<?php

namespace App\Services;

use App\Models\MetaTrackingEvent;
use App\Models\Order;
use Illuminate\Support\Facades\Queue;

class MetaPurchaseTrackingDiagnostics
{
    /**
     * @return array<string, mixed>
     */
    public function diagnose(Order $order): array
    {
        $order->loadMissing(['product', 'user', 'checkoutSession']);
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $pixels = AffiliateConversionPixels::forOrder($order);
        $metaPixels = is_array($pixels['meta'] ?? null) ? $pixels['meta'] : [];
        $entries = isset($metaPixels['entries']) && is_array($metaPixels['entries']) ? $metaPixels['entries'] : [];

        $capiEntries = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $pixelId = trim((string) ($entry['pixel_id'] ?? ''));
            $hasToken = trim((string) ($entry['access_token'] ?? '')) !== '';
            $capiEntries[] = [
                'pixel_id' => $pixelId !== '' ? $pixelId : null,
                'has_access_token' => $hasToken,
                'fire_purchase_on_pix' => $entry['fire_purchase_on_pix'] ?? true,
                'fire_purchase_on_boleto' => $entry['fire_purchase_on_boleto'] ?? true,
            ];
        }

        $purchaseEventId = 'order:'.$order->id;
        $trackingEvents = MetaTrackingEvent::query()
            ->where(function ($q) use ($order, $purchaseEventId) {
                $q->where(function ($q2) use ($order) {
                    $q2->where('context_type', MetaTrackingEvent::CONTEXT_ORDER)
                        ->where('context_id', $order->id);
                })->orWhere('event_id', $purchaseEventId);
            })
            ->orderBy('event_name')
            ->get()
            ->map(fn (MetaTrackingEvent $e) => [
                'event_name' => $e->event_name,
                'event_id' => $e->event_id,
                'pixel_id' => $e->pixel_id,
                'status' => $e->status,
                'attempts' => $e->attempts,
                'sent_at' => $e->sent_at?->toIso8601String(),
                'last_error' => $e->last_error,
            ])
            ->all();

        $session = $order->checkoutSession;

        return [
            'order_id' => $order->id,
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'gateway' => $order->gateway,
            'amount' => (float) $order->amount,
            'meta_capi_sent_purchase' => (bool) ($meta['meta_capi_sent_purchase'] ?? false),
            'meta_capi_sent_purchase_at' => $meta['meta_capi_sent_purchase_at'] ?? null,
            'meta_capi_failed' => (bool) ($meta['meta_capi_failed'] ?? false),
            'meta_capi_failed_at' => $meta['meta_capi_failed_at'] ?? null,
            'meta_capi_last_error' => $meta['meta_capi_last_error'] ?? null,
            'meta_capi_skipped_reason' => $meta['meta_capi_skipped_reason'] ?? null,
            'browser_purchase_ack_at' => $meta['browser_purchase_ack_at'] ?? null,
            'browser_purchase_ack_trigger' => $meta['browser_purchase_ack_trigger'] ?? null,
            'has_fbp' => ! empty($meta['fbp']),
            'has_fbc' => ! empty($meta['fbc']),
            'has_user_agent' => ! empty($meta['user_agent']),
            'session_has_fbp' => ! empty($session?->meta_fbp),
            'session_has_fbc' => ! empty($session?->meta_fbc),
            'meta_enabled' => (bool) ($metaPixels['enabled'] ?? false),
            'meta_pixel_entries' => count($capiEntries),
            'meta_pixels_ready_for_capi' => count(array_filter($capiEntries, fn ($e) => $e['pixel_id'] && $e['has_access_token'])),
            'meta_pixel_entries_detail' => $capiEntries,
            'meta_tracking_events' => $trackingEvents,
            'meta_tracking_queue' => (string) config('meta_tracking.queue', 'meta-tracking'),
            'queue_connection' => (string) config('queue.default'),
            'queue_driver_sync' => config('queue.default') === 'sync',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function formatReport(array $data): string
    {
        $lines = [
            'Pedido #'.($data['order_id'] ?? '?'),
            'Status: '.($data['status'] ?? '—').' | Pagamento: '.($data['payment_method'] ?? '—').' | Gateway: '.($data['gateway'] ?? '—'),
            'Valor: R$ '.number_format((float) ($data['amount'] ?? 0), 2, ',', '.'),
            '',
            'CAPI (servidor):',
            '  Enviado: '.(($data['meta_capi_sent_purchase'] ?? false) ? 'sim' : 'não'),
            '  Em: '.($data['meta_capi_sent_purchase_at'] ?? '—'),
            '  Falhou (definitivo): '.(($data['meta_capi_failed'] ?? false) ? 'sim' : 'não'),
            '  Skip: '.($data['meta_capi_skipped_reason'] ?? '—'),
            '  Último erro: '.($data['meta_capi_last_error'] ?? '—'),
            '  Pixels Meta ativos: '.($data['meta_pixels_ready_for_capi'] ?? 0).' / '.($data['meta_pixel_entries'] ?? 0),
            '  Fila Meta: '.($data['meta_tracking_queue'] ?? 'meta-tracking'),
            '',
            'Browser (cliente):',
            '  ACK registrado: '.($data['browser_purchase_ack_at'] ?? '—').' ('.($data['browser_purchase_ack_trigger'] ?? '—').')',
            '  fbp (pedido): '.(($data['has_fbp'] ?? false) ? 'sim' : 'não').' | fbc: '.(($data['has_fbc'] ?? false) ? 'sim' : 'não').' | UA: '.(($data['has_user_agent'] ?? false) ? 'sim' : 'não'),
            '  fbp/fbc (sessão): '.(($data['session_has_fbp'] ?? false) ? 'sim' : 'não').' / '.(($data['session_has_fbc'] ?? false) ? 'sim' : 'não'),
            '',
            'Eventos meta_tracking_events:',
        ];

        $events = $data['meta_tracking_events'] ?? [];
        if ($events === []) {
            $lines[] = '  (nenhum)';
        } else {
            foreach ($events as $evt) {
                $lines[] = sprintf(
                    '  - %s [%s] pixel %s → %s (tentativas: %s)',
                    $evt['event_name'] ?? '?',
                    $evt['event_id'] ?? '?',
                    $evt['pixel_id'] ?? '?',
                    $evt['status'] ?? '?',
                    $evt['attempts'] ?? 0
                );
            }
        }

        $lines[] = '';
        $lines[] = 'Fila app: '.($data['queue_connection'] ?? '—').(($data['queue_driver_sync'] ?? false) ? ' (sync)' : ' (requer worker meta-tracking)');

        return implode(PHP_EOL, $lines);
    }

    public function logQueueHintOnDispatch(int $orderId): void
    {
        $driver = (string) config('queue.default');
        if ($driver === 'sync') {
            return;
        }

        try {
            $size = Queue::size();
        } catch (\Throwable) {
            $size = null;
        }

        \Illuminate\Support\Facades\Log::debug('Meta CAPI job dispatched', [
            'order_id' => $orderId,
            'queue_connection' => $driver,
            'queue_size' => $size,
        ]);
    }
}
