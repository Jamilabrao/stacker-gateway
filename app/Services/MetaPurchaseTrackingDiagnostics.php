<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Queue;

class MetaPurchaseTrackingDiagnostics
{
    /**
     * @return array<string, mixed>
     */
    public function diagnose(Order $order): array
    {
        $order->loadMissing(['product', 'user']);
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
            'browser_purchase_ack_at' => $meta['browser_purchase_ack_at'] ?? null,
            'browser_purchase_ack_trigger' => $meta['browser_purchase_ack_trigger'] ?? null,
            'has_fbp' => ! empty($meta['fbp']),
            'has_fbc' => ! empty($meta['fbc']),
            'has_user_agent' => ! empty($meta['user_agent']),
            'meta_enabled' => (bool) ($metaPixels['enabled'] ?? false),
            'meta_pixel_entries' => count($capiEntries),
            'meta_pixels_ready_for_capi' => count(array_filter($capiEntries, fn ($e) => $e['pixel_id'] && $e['has_access_token'])),
            'meta_pixel_entries_detail' => $capiEntries,
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
            '  Último erro: '.($data['meta_capi_last_error'] ?? '—'),
            '  Pixels Meta ativos: '.($data['meta_pixels_ready_for_capi'] ?? 0).' / '.($data['meta_pixel_entries'] ?? 0),
            '',
            'Browser (cliente):',
            '  ACK registrado: '.($data['browser_purchase_ack_at'] ?? '—').' ('.($data['browser_purchase_ack_trigger'] ?? '—').')',
            '  fbp: '.(($data['has_fbp'] ?? false) ? 'sim' : 'não').' | fbc: '.(($data['has_fbc'] ?? false) ? 'sim' : 'não').' | UA: '.(($data['has_user_agent'] ?? false) ? 'sim' : 'não'),
            '',
            'Fila: '.($data['queue_connection'] ?? '—').(($data['queue_driver_sync'] ?? false) ? ' (sync — CAPI no mesmo request)' : ' (requer queue:work se redis/database)'),
        ];

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
