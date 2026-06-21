<?php

namespace App\Services\Meta;

use App\Models\CheckoutSession;
use App\Models\MetaTrackingEvent;
use App\Models\Product;
use App\Services\AffiliateConversionPixels;
use Illuminate\Support\Facades\Queue;

class MetaCheckoutSessionDiagnostics
{
    public function __construct(
        private MetaTrackingService $trackingService,
    ) {}

    public function findByTokenOrId(string $tokenOrId): ?CheckoutSession
    {
        if (ctype_digit($tokenOrId)) {
            return CheckoutSession::query()->find((int) $tokenOrId);
        }

        return CheckoutSession::query()->where('session_token', $tokenOrId)->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnose(CheckoutSession $session): array
    {
        $session->loadMissing('product');
        $product = $session->product;

        $pixels = $product
            ? AffiliateConversionPixels::forProductAndRef($product, $session->affiliate_ref)
            : Product::defaultConversionPixels();

        $meta = is_array($pixels['meta'] ?? null) ? $pixels['meta'] : [];
        $rawEntries = isset($meta['entries']) && is_array($meta['entries']) ? $meta['entries'] : [];

        $pixelEntries = [];
        foreach ($rawEntries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $pixelId = trim((string) ($entry['pixel_id'] ?? ''));
            $hasToken = trim((string) ($entry['access_token'] ?? '')) !== '';
            $pixelEntries[] = [
                'pixel_id' => $pixelId !== '' ? $pixelId : null,
                'has_access_token' => $hasToken,
            ];
        }

        $trackingEvents = MetaTrackingEvent::query()
            ->where('context_type', MetaTrackingEvent::CONTEXT_SESSION)
            ->where('context_id', $session->id)
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

        return [
            'checkout_session_id' => $session->id,
            'session_token' => $session->session_token,
            'product_id' => $session->product_id,
            'checkout_slug' => $session->checkout_slug,
            'step' => $session->step,
            'order_id' => $session->order_id,
            'affiliate_ref' => $session->affiliate_ref,
            'utm_source' => $session->utm_source,
            'utm_medium' => $session->utm_medium,
            'utm_campaign' => $session->utm_campaign,
            'meta_fbclid' => $session->meta_fbclid,
            'meta_fbp' => $session->meta_fbp,
            'meta_fbc' => $session->meta_fbc,
            'meta_page_url' => $session->meta_page_url,
            'meta_enabled' => (bool) ($meta['enabled'] ?? false),
            'meta_pixel_entries' => count($pixelEntries),
            'meta_pixels_ready_for_capi' => count(array_filter($pixelEntries, fn ($e) => $e['pixel_id'] && $e['has_access_token'])),
            'meta_pixel_entries_detail' => $pixelEntries,
            'meta_tracking_events' => $trackingEvents,
            'landing_backup_eligible' => $this->trackingService->shouldQueueCheckoutLandingBackup($session),
            'meta_tracking_queue' => (string) config('meta_tracking.queue', 'meta-tracking'),
            'queue_connection' => (string) config('queue.default'),
            'queue_driver_sync' => config('queue.default') === 'sync',
            'queue_size' => $this->queueSize(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function formatReport(array $data): string
    {
        $lines = [
            'Sessão checkout #'.($data['checkout_session_id'] ?? '?'),
            'Token: '.($data['session_token'] ?? '—'),
            'Produto: '.($data['product_id'] ?? '—').' | Slug: '.($data['checkout_slug'] ?? '—'),
            'Step: '.($data['step'] ?? '—').' | Pedido: '.($data['order_id'] ?? 'null'),
            '',
            'Tráfego / atribuição:',
            '  affiliate_ref: '.($data['affiliate_ref'] ?? '—'),
            '  utm_source: '.($data['utm_source'] ?? '—'),
            '  utm_medium: '.($data['utm_medium'] ?? '—'),
            '  utm_campaign: '.($data['utm_campaign'] ?? '—'),
            '  meta_fbclid: '.($data['meta_fbclid'] ?? '—'),
            '  meta_fbp: '.($data['meta_fbp'] ?? '—'),
            '  meta_fbc: '.($data['meta_fbc'] ?? '—'),
            '  meta_page_url: '.($data['meta_page_url'] ?? '—'),
            '  backup GET elegível: '.(($data['landing_backup_eligible'] ?? false) ? 'sim' : 'não'),
            '',
            'Meta pixel (produto/afiliado):',
            '  enabled: '.(($data['meta_enabled'] ?? false) ? 'sim' : 'não'),
            '  entries: '.($data['meta_pixel_entries'] ?? 0),
            '  prontos CAPI (pixel+token): '.($data['meta_pixels_ready_for_capi'] ?? 0),
        ];

        foreach ($data['meta_pixel_entries_detail'] ?? [] as $entry) {
            $lines[] = sprintf(
                '    pixel %s token=%s',
                $entry['pixel_id'] ?? '?',
                ($entry['has_access_token'] ?? false) ? 'sim' : 'não'
            );
        }

        $lines[] = '';
        $lines[] = 'Eventos meta_tracking_events:';
        $events = $data['meta_tracking_events'] ?? [];
        if ($events === []) {
            $lines[] = '  (nenhum)';
        } else {
            foreach ($events as $e) {
                $lines[] = sprintf(
                    '  %s %s pixel=%s status=%s attempts=%s',
                    $e['event_name'] ?? '?',
                    $e['event_id'] ?? '?',
                    $e['pixel_id'] ?? '?',
                    $e['status'] ?? '?',
                    $e['attempts'] ?? 0
                );
                if (! empty($e['last_error'])) {
                    $lines[] = '    erro: '.$e['last_error'];
                }
            }
        }

        $lines[] = '';
        $lines[] = 'Fila: '.($data['meta_tracking_queue'] ?? 'meta-tracking').' | connection: '.($data['queue_connection'] ?? '—');
        if ($data['queue_driver_sync'] ?? false) {
            $lines[] = '  AVISO: QUEUE_CONNECTION=sync — jobs rodam inline.';
        } else {
            $lines[] = '  Worker: php artisan queue:work --queue='.($data['meta_tracking_queue'] ?? 'meta-tracking');
            $lines[] = '  Tamanho fila: '.($data['queue_size'] ?? 'n/a');
        }

        return implode("\n", $lines);
    }

    private function queueSize(): ?int
    {
        try {
            return Queue::size((string) config('meta_tracking.queue', 'meta-tracking'));
        } catch (\Throwable) {
            return null;
        }
    }
}
