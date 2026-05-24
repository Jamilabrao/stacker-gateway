<?php

namespace App\Jobs;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Gateways\GatewayRegistry;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Services\PlatformOrderAdminService;
use App\Support\CajuPayPaymentId;
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

    public function handle(): void
    {
        $order = Order::query()->find($this->orderId);
        if ($order === null || $order->status === 'refunded') {
            return;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        if (empty($meta['cajupay_pix_refund_pending'])) {
            return;
        }

        $paymentId = CajuPayPaymentId::fromOrder($order);
        if ($paymentId === null) {
            return;
        }

        $credential = GatewayCredential::resolveForPayment((int) $order->tenant_id, 'cajupay');
        if (! $credential) {
            return;
        }

        $driver = GatewayRegistry::driver('cajupay');
        if (! $driver instanceof CajuPayDriver) {
            return;
        }

        try {
            $body = $driver->getPixRefund($credential->getDecryptedCredentials(), $paymentId);
        } catch (\Throwable $e) {
            Log::debug('PollCajuPayPixRefundJob: consulta falhou', ['order_id' => $order->id, 'message' => $e->getMessage()]);
            if ($this->attempt < 24) {
                self::dispatch($this->orderId, $this->attempt + 1)->delay(now()->addSeconds(5));
            }

            return;
        }

        $status = strtolower(trim((string) ($body['status'] ?? '')));
        if ($status === 'devolvido') {
            unset($meta['cajupay_pix_refund_pending']);
            $meta['cajupay_pix_refund_status'] = 'devolvido';
            $order->update(['metadata' => $meta]);
            PlatformOrderAdminService::applyGatewayRefund($order->fresh());

            return;
        }

        if (in_array($status, ['submitted', 'pending_balance'], true) && $this->attempt < 24) {
            self::dispatch($this->orderId, $this->attempt + 1)->delay(now()->addSeconds(5));

            return;
        }

        if ($status === 'failed') {
            unset($meta['cajupay_pix_refund_pending']);
            $meta['cajupay_pix_refund_status'] = 'failed';
            $order->update(['metadata' => $meta]);
        }
    }
}
