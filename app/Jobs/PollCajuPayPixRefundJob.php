<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\CajuPay\CajuPayPixRefundConfirmationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PollCajuPayPixRefundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 24;

    public int $backoff = 5;

    public function __construct(
        public int $orderId,
        public int $attempt = 1
    ) {}

    public function handle(CajuPayPixRefundConfirmationService $confirmation): void
    {
        $order = Order::query()->find($this->orderId);
        if ($order === null || $order->status === 'refunded') {
            return;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $awaiting = $order->status === 'refund_pending' || ! empty($meta['cajupay_pix_refund_pending']);
        if (! $awaiting) {
            return;
        }

        try {
            if ($confirmation->confirmIfRemoteCancelled($order)) {
                return;
            }
        } catch (\Throwable $e) {
            Log::debug('PollCajuPayPixRefundJob: confirmação falhou', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }

        if ($this->attempt < 24) {
            self::dispatch($this->orderId, $this->attempt + 1)->delay(now()->addSeconds(5));
        }
    }
}
