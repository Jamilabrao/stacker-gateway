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
            $order->loadMissing('product');
            $title = $order->saleApprovedPushTitle();
            $body = $order->saleApprovedPushBody();
            $url = url('/vendas?order=' . $order->id);

            $sent = $this->panelPushService->sendAndPersistToTenant(
                $order->tenant_id,
                'sale_approved',
                $title,
                $body,
                $url,
                'sale_' . $order->id
            );

            Log::info('SendPanelPushOnOrderCompleted: push de venda aprovada', [
                'order_id' => $order->id,
                'tenant_id' => $order->tenant_id,
                'sent' => $sent,
            ]);

            if ($sent === 0) {
                $subscriptionCount = PanelPushSubscription::query()
                    ->where('tenant_id', $order->tenant_id)
                    ->count();

                if ($subscriptionCount > 0) {
                    Log::warning('SendPanelPushOnOrderCompleted: nenhum push entregue apesar de inscrições ativas', [
                        'order_id' => $order->id,
                        'tenant_id' => $order->tenant_id,
                        'subscription_count' => $subscriptionCount,
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
