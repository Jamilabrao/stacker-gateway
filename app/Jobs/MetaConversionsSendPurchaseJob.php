<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\MetaConversionsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MetaConversionsSendPurchaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    public function backoff(): array
    {
        return [30, 60, 120, 300, 600, 1200, 1800];
    }

    public function __construct(public int $orderId) {}

    public function handle(MetaConversionsService $service): void
    {
        $order = Order::query()->find($this->orderId);
        if (! $order) {
            return;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $sent = isset($meta['meta_capi_sent_purchase']) ? (bool) $meta['meta_capi_sent_purchase'] : false;
        if ($sent) {
            return;
        }

        $results = $service->sendPurchaseForOrder($order);

        if ($results === []) {
            Log::info('Meta CAPI purchase skipped: no Meta pixel with access token configured for order', [
                'order_id' => $order->id,
                'tenant_id' => $order->tenant_id,
            ]);

            return;
        }

        $okAny = false;
        foreach ($results as $r) {
            if (($r['ok'] ?? false) === true) {
                $okAny = true;
                break;
            }
        }

        if ($okAny) {
            $meta['meta_capi_sent_purchase'] = true;
            $meta['meta_capi_sent_purchase_at'] = now()->toIso8601String();
            unset($meta['meta_capi_failed'], $meta['meta_capi_failed_at'], $meta['meta_capi_last_error']);
            $order->update(['metadata' => $meta]);

            Log::info('Meta CAPI purchase sent', [
                'order_id' => $order->id,
                'tenant_id' => $order->tenant_id,
                'pixels_ok' => count(array_filter($results, fn ($x) => ($x['ok'] ?? false) === true)),
            ]);

            return;
        }

        $summary = $this->summarizeResults($results);
        $meta['meta_capi_last_error'] = $summary;
        $meta['meta_capi_last_attempt_at'] = now()->toIso8601String();
        $order->update(['metadata' => $meta]);

        Log::warning('Meta CAPI purchase send failed', [
            'order_id' => $order->id,
            'tenant_id' => $order->tenant_id,
            'attempt' => $this->attempts(),
            'queue_connection' => (string) config('queue.default'),
            'results' => array_map(function ($x) {
                return [
                    'pixel_id' => $x['pixel_id'] ?? null,
                    'ok' => $x['ok'] ?? false,
                    'status' => $x['status'] ?? null,
                    'body' => isset($x['body']) && is_string($x['body']) ? mb_substr($x['body'], 0, 800) : null,
                    'error' => $x['error'] ?? null,
                ];
            }, $results),
        ]);

        throw new \RuntimeException('Meta CAPI send failed: '.$summary);
    }

    public function failed(?\Throwable $exception): void
    {
        $order = Order::query()->find($this->orderId);
        if (! $order) {
            return;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        if (! empty($meta['meta_capi_sent_purchase'])) {
            return;
        }

        $meta['meta_capi_failed'] = true;
        $meta['meta_capi_failed_at'] = now()->toIso8601String();
        if ($exception !== null) {
            $meta['meta_capi_last_error'] = mb_substr($exception->getMessage(), 0, 500);
        }
        $order->update(['metadata' => $meta]);

        Log::error('Meta CAPI purchase failed after retries', [
            'order_id' => $order->id,
            'tenant_id' => $order->tenant_id,
            'message' => $exception?->getMessage(),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function summarizeResults(array $results): string
    {
        $parts = [];
        foreach ($results as $r) {
            $pid = $r['pixel_id'] ?? '?';
            $status = $r['status'] ?? 'n/a';
            $err = $r['error'] ?? 'meta_api_error';
            $parts[] = "pixel {$pid}: {$err} (HTTP {$status})";
        }

        return implode('; ', $parts) ?: 'unknown';
    }
}
