<?php

namespace App\Services;

use App\Events\OrderCompleted;
use App\Events\SubscriptionRenewed;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;

class SubscriptionRenewalService
{
    /**
     * Após pagamento confirmado de um pedido de renovação (is_renewal), estende o período da assinatura e dispara eventos.
     */
    public function applySuccessfulRenewal(Order $order, Subscription $subscription, SubscriptionPlan $plan): void
    {
        if (! $order->is_renewal) {
            return;
        }

        [$periodStart, $periodEnd] = $plan->getCurrentPeriod();

        $order->update(['status' => 'completed']);
        $order->grantPurchasedProductAccessToBuyer();

        $subscription->update([
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
        ]);

        event(new SubscriptionRenewed($subscription->fresh()));
        event(new OrderCompleted($order->fresh()));
    }
}
