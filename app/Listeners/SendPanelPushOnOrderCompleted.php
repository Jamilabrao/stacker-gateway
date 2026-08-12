<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Models\PanelPushSubscription;
use App\Services\PanelPushService;
use Illuminate\Support\Facades\Log;

class SendPanelPushOnOrderCompleted
{
    public function __construct(
        protected PanelPushService $panelPushService
    ) {}

    public function handle(OrderCompleted $event): void
    {
        $order = $event->order;

        try {
            $order->loadMissing(['product', 'orderItems.product']);
            $url = url('/vendas?order='.$order->id);
            $messages = $order->saleApprovedPushMessages();
            $sentTotal = 0;

            foreach ($messages as $message) {
                $sent = $this->panelPushService->sendAndPersistToTenant(
                    $order->tenant_id,
                    'sale_approved',
                    $message['title'],
                    $message['body'],
                    $url,
                    $message['event_key']
                );
                $sentTotal += $sent;

                Log::info('SendPanelPushOnOrderCompleted: push de venda aprovada', [
                    'order_id' => $order->id,
                    'tenant_id' => $order->tenant_id,
                    'event_key' => $message['event_key'],
                    'is_order_bump' => $message['is_order_bump'],
                    'sent' => $sent,
                ]);
            }

            if ($sentTotal === 0) {
                $subscriptionCount = PanelPushSubscription::query()
                    ->where('tenant_id', $order->tenant_id)
                    ->count();

                if ($subscriptionCount > 0) {
                    Log::warning('SendPanelPushOnOrderCompleted: nenhum push entregue apesar de inscrições ativas', [
                        'order_id' => $order->id,
                        'tenant_id' => $order->tenant_id,
                        'subscription_count' => $subscriptionCount,
                        'messages' => count($messages),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('SendPanelPushOnOrderCompleted: falha ao enviar push', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
