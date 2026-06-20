<?php

namespace App\Services;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\UtmifyIntegration;
use App\Models\UtmifyOrderDispatch;

class UtmifyTrackingDiagnostics
{
    public function __construct(
        private UtmifyService $utmifyService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function diagnose(Order $order): array
    {
        $order->loadMissing(['product']);
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $session = $this->utmifyService->resolveCheckoutSessionForOrder($order);

        $integrations = UtmifyIntegration::forTenant($order->tenant_id)
            ->with('products:id')
            ->get()
            ->map(fn (UtmifyIntegration $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'is_active' => $i->is_active,
                'has_api_key' => ! empty($i->api_key),
                'applies_to_order' => $i->appliesToOrder($order),
                'product_filter_count' => $i->products->count(),
            ])
            ->all();

        $dispatches = UtmifyOrderDispatch::query()
            ->where('order_id', $order->id)
            ->orderBy('utmify_status')
            ->get()
            ->map(fn (UtmifyOrderDispatch $d) => [
                'integration_id' => $d->utmify_integration_id,
                'utmify_status' => $d->utmify_status,
                'dispatch_status' => $d->dispatch_status,
                'attempts' => $d->attempts,
                'sent_at' => $d->sent_at?->toIso8601String(),
                'last_error' => $d->last_error,
            ])
            ->all();

        $trackingKeys = ['src', 'sck', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
        $orderUtms = [];
        $sessionUtms = [];
        foreach ($trackingKeys as $key) {
            $orderUtms[$key] = isset($meta[$key]) && is_string($meta[$key]) ? $meta[$key] : null;
            $sessionUtms[$key] = $session instanceof CheckoutSession && isset($session->{$key})
                ? (is_string($session->{$key}) ? $session->{$key} : null)
                : null;
        }

        $payloadPreview = null;
        try {
            $payloadPreview = $this->utmifyService->buildPayload($order, 'paid')['trackingParameters'] ?? [];
        } catch (\Throwable) {
            $payloadPreview = ['error' => 'failed to build payload'];
        }

        return [
            'order_id' => $order->id,
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'gateway' => $order->gateway,
            'amount' => (float) $order->amount,
            'checkout_session_token' => $meta['checkout_session_token'] ?? null,
            'session_resolved' => $session !== null,
            'session_id' => $session?->id,
            'session_order_id' => $session?->order_id,
            'order_utms' => $orderUtms,
            'session_utms' => $sessionUtms,
            'payload_tracking_parameters' => $payloadPreview,
            'utmify_waiting_sent_at' => $meta['utmify_waiting_sent_at'] ?? null,
            'utmify_paid_sent_at' => $meta['utmify_paid_sent_at'] ?? null,
            'utmify_failed_at' => $meta['utmify_failed_at'] ?? null,
            'utmify_last_error' => $meta['utmify_last_error'] ?? null,
            'integrations' => $integrations,
            'dispatches' => $dispatches,
            'utmify_queue' => (string) config('utmify.queue', 'utmify-tracking'),
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
            'Sessão checkout:',
            '  Token no pedido: '.($data['checkout_session_token'] ?? '—'),
            '  Sessão resolvida: '.($data['session_resolved'] ? 'sim (#'.($data['session_id'] ?? '?').', order_id='.($data['session_order_id'] ?? 'null').')' : 'não'),
            '',
            'UTMs no pedido (metadata):',
        ];

        foreach ($data['order_utms'] ?? [] as $key => $value) {
            $lines[] = '  '.$key.': '.($value ?? '—');
        }

        $lines[] = '';
        $lines[] = 'UTMs na sessão:';
        foreach ($data['session_utms'] ?? [] as $key => $value) {
            $lines[] = '  '.$key.': '.($value ?? '—');
        }

        $lines[] = '';
        $lines[] = 'Payload Utmify (trackingParameters):';
        $tp = $data['payload_tracking_parameters'] ?? [];
        if (is_array($tp)) {
            foreach ($tp as $key => $value) {
                $lines[] = '  '.$key.': '.($value === null ? 'null' : (string) $value);
            }
        }

        $lines[] = '';
        $lines[] = 'Flags metadata:';
        $lines[] = '  utmify_waiting_sent_at: '.($data['utmify_waiting_sent_at'] ?? '—');
        $lines[] = '  utmify_paid_sent_at: '.($data['utmify_paid_sent_at'] ?? '—');
        $lines[] = '  utmify_failed_at: '.($data['utmify_failed_at'] ?? '—');
        $lines[] = '  utmify_last_error: '.($data['utmify_last_error'] ?? '—');

        $lines[] = '';
        $lines[] = 'Integrações Utmify (tenant):';
        foreach ($data['integrations'] ?? [] as $i) {
            $lines[] = sprintf(
                '  #%s %s — ativa=%s api_key=%s aplica_ao_pedido=%s produtos_filtrados=%s',
                $i['id'] ?? '?',
                $i['name'] ?? '—',
                ($i['is_active'] ?? false) ? 'sim' : 'não',
                ($i['has_api_key'] ?? false) ? 'sim' : 'não',
                ($i['applies_to_order'] ?? false) ? 'sim' : 'não',
                $i['product_filter_count'] ?? 0
            );
        }

        $lines[] = '';
        $lines[] = 'Dispatches (auditoria):';
        $dispatches = $data['dispatches'] ?? [];
        if ($dispatches === []) {
            $lines[] = '  (nenhum registro)';
        } else {
            foreach ($dispatches as $d) {
                $lines[] = sprintf(
                    '  integration=%s status=%s dispatch=%s attempts=%s sent_at=%s',
                    $d['integration_id'] ?? '?',
                    $d['utmify_status'] ?? '?',
                    $d['dispatch_status'] ?? '?',
                    $d['attempts'] ?? 0,
                    $d['sent_at'] ?? '—'
                );
                if (! empty($d['last_error'])) {
                    $lines[] = '    erro: '.$d['last_error'];
                }
            }
        }

        $lines[] = '';
        $lines[] = 'Fila: '.($data['utmify_queue'] ?? 'utmify-tracking').' | connection: '.($data['queue_connection'] ?? '—');
        if ($data['queue_driver_sync'] ?? false) {
            $lines[] = '  AVISO: QUEUE_CONNECTION=sync — jobs rodam inline (ok em dev/teste).';
        } else {
            $lines[] = '  Worker necessário: php artisan queue:work --queue='.($data['utmify_queue'] ?? 'utmify-tracking');
        }

        return implode("\n", $lines);
    }
}
