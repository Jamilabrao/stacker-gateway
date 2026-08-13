<?php

namespace App\Listeners;

use App\Events\BoletoGenerated;
use App\Models\PanelPushSubscription;
use App\Services\PanelPushService;
use Illuminate\Support\Facades\Log;

class SendPanelPushOnBoletoGenerated
{
    public function __construct(
        protected PanelPushService $panelPushService
    ) {}

    public function handle(BoletoGenerated $event): void
    {
        $order = $event->order;

        try {
            try {
                $order->loadMissing(['product']);
            } catch (\Throwable) {
                // Pedidos em memória (ex.: testes) podem não ter conexão.
            }
            $title = $order->boletoGeneratedPushTitle();
            $body = $order->boletoGeneratedPushBody();
            $url = url('/vendas');

            $sent = $this->panelPushService->sendAndPersistToTenant(
                $order->tenant_id,
                'boleto_generated',
                $title,
                $body,
                $url,
                'boleto_' . $order->id
            );

            if ($sent === 0) {
                $subscriptionCount = PanelPushSubscription::query()
                    ->where('tenant_id', $order->tenant_id)
                    ->count();

                if ($subscriptionCount > 0) {
                    Log::warning('SendPanelPushOnBoletoGenerated: nenhum push entregue apesar de inscrições ativas', [
                        'order_id' => $order->id,
                        'tenant_id' => $order->tenant_id,
                        'subscription_count' => $subscriptionCount,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('SendPanelPushOnBoletoGenerated: falha ao enviar push', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
