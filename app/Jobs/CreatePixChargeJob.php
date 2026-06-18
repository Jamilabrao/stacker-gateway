<?php

namespace App\Jobs;

use App\Events\PixGenerated;
use App\Models\Order;
use App\Services\Api\ApiWebhookDeliveryService;
use App\Services\PaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreatePixChargeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 15;

    public function __construct(
        public int $orderId,
    ) {
        $this->onQueue((string) config('queue.payments_queue', 'payments'));
    }

    public function handle(PaymentService $paymentService, ApiWebhookDeliveryService $webhookDelivery): void
    {
        $order = Order::with(['apiApplication', 'product'])->find($this->orderId);
        if (! $order || $order->status !== 'pending' || $order->payment_method !== 'pix') {
            return;
        }

        if ($order->gateway_id) {
            return;
        }

        $product = $order->product;
        $consumer = [
            'name' => $order->metadata['consumer_name'] ?? $order->email,
            'document' => preg_replace('/\D/', '', (string) ($order->cpf ?? '')),
            'email' => (string) $order->email,
        ];

        try {
            $result = $paymentService->createPixPayment($order, $product, $consumer, null);
            event(new PixGenerated($order, [
                'qrcode' => $result['qrcode'] ?? null,
                'copy_paste' => $result['copy_paste'] ?? null,
                'transaction_id' => $result['transaction_id'] ?? null,
            ]));

            $app = $order->apiApplication;
            if ($app) {
                $webhookDelivery->dispatch($app, 'pix.generated', [
                    'order_id' => $order->id,
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'qrcode' => $result['qrcode'] ?? null,
                    'copy_paste' => $result['copy_paste'] ?? null,
                    'status' => 'pending',
                    'amount' => (float) $order->amount,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('CreatePixChargeJob failed', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
            $order->delete();
            throw $e;
        }
    }
}
