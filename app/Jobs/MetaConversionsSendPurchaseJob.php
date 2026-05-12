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
            $order->update(['metadata' => $meta]);
            return;
        }

        // Falha: loga, mas não grava segredo (token). O retry da fila cuida do resto.
        Log::warning('Meta CAPI purchase send failed', [
            'order_id' => $order->id,
            'tenant_id' => $order->tenant_id,
            'results' => array_map(function ($x) {
                return [
                    'pixel_id' => $x['pixel_id'] ?? null,
                    'ok' => $x['ok'] ?? false,
                    'status' => $x['status'] ?? null,
                    // Limita body para não explodir logs
                    'body' => isset($x['body']) && is_string($x['body']) ? mb_substr($x['body'], 0, 800) : null,
                    'error' => $x['error'] ?? null,
                ];
            }, $results),
        ]);

        throw new \RuntimeException('Meta CAPI send failed');
    }
}

