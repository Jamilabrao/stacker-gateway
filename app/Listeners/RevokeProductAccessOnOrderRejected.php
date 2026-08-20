<?php

namespace App\Listeners;

use App\Events\OrderRejected;

class RevokeProductAccessOnOrderRejected
{
    public function handle(OrderRejected $event): void
    {
        $event->order->revokePurchasedProductAccessFromBuyer();
    }
}
