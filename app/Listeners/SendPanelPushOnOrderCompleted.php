<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
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

            $this->panelPushService->sendAndPersistToTenant(
                $order->tenant_id,
                'sale_approved',
                $title,
                $body,
                $url,
                'sale_' . $order->id
            );
        } catch (\Throwable $e) {
            Log::warning('SendPanelPushOnOrderCompleted: falha ao enviar push', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
