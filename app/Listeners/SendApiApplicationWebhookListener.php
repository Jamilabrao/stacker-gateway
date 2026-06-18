<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Events\OrderCancelled;
use App\Events\OrderPending;
use App\Events\OrderRefunded;
use App\Events\PixGenerated;
use App\Models\Order;
use App\Services\Api\ApiWebhookDeliveryService;
use App\Support\ApiWebhookPayloadBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class SendApiApplicationWebhookListener
{
    public function __construct(
        private ApiWebhookDeliveryService $webhookDelivery,
    ) {}

    public function handleOrderCompleted(OrderCompleted $event): void
    {
        $this->dispatchOrderEvent($event->order, 'order.completed', true);
    }

    public function handleOrderPending(OrderPending $event): void
    {
        $this->dispatchOrderEvent($event->order, 'order.pending', false);
    }

    public function handleOrderRefunded(OrderRefunded $event): void
    {
        $this->dispatchOrderEvent($event->order, 'order.refunded', false);
    }

    public function handleOrderCancelled(OrderCancelled $event): void
    {
        $this->dispatchOrderEvent($event->order, 'order.cancelled', false);
    }

    public function handlePixGenerated(PixGenerated $event): void
    {
        $order = $event->order;
        if ($order->api_application_id === null) {
            return;
        }

        $order->loadMissing('apiApplication');
        $app = $order->apiApplication;
        if (! $app) {
            return;
        }

        $this->webhookDelivery->dispatch($app, 'pix.generated', [
            'order_id' => $order->id,
            'transaction_id' => $event->pixData['transaction_id'] ?? $order->gateway_id,
            'qrcode' => $event->pixData['qrcode'] ?? null,
            'copy_paste' => $event->pixData['copy_paste'] ?? null,
            'status' => $order->status,
            'amount' => (float) $order->amount,
        ]);
    }

    public function subscribe($events): array
    {
        return [
            OrderCompleted::class => 'handleOrderCompleted',
            OrderPending::class => 'handleOrderPending',
            OrderRefunded::class => 'handleOrderRefunded',
            OrderCancelled::class => 'handleOrderCancelled',
            PixGenerated::class => 'handlePixGenerated',
        ];
    }

    private function dispatchOrderEvent(Order $order, string $eventName, bool $allowSyncFallback): void
    {
        if ($order->api_application_id === null) {
            return;
        }

        $order->loadMissing('apiApplication');
        $app = $order->apiApplication;
        if (! $app) {
            return;
        }

        $payload = ApiWebhookPayloadBuilder::orderPayload($order, $eventName);

        if ($allowSyncFallback && $this->shouldDispatchSyncForCompleted()) {
            $this->webhookDelivery->dispatchSync($app, $eventName, $payload);

            return;
        }

        $this->webhookDelivery->dispatch($app, $eventName, $payload);
    }

    private function shouldDispatchSyncForCompleted(): bool
    {
        if (config('queue.default') === 'sync') {
            return true;
        }

        $heartbeat = Cache::get('queue_heartbeat');
        if (! is_string($heartbeat) || $heartbeat === '') {
            return true;
        }

        try {
            $last = \Illuminate\Support\Carbon::parse($heartbeat);
            if ($last->lt(now()->subMinutes(3))) {
                return true;
            }
        } catch (\Throwable) {
            return true;
        }

        if (! class_exists(Redis::class)) {
            return false;
        }

        try {
            $queueName = (string) config('queue.webhooks_outbound_queue', 'webhooks-outbound');
            $depth = (int) Redis::connection()->llen('queues:'.$queueName);

            return $depth >= 50;
        } catch (\Throwable) {
            return false;
        }
    }
}
