<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Services\CouponCheckoutService;

class IncrementCouponUsageOnOrderCompleted
{
    public function __construct(
        protected CouponCheckoutService $couponCheckout
    ) {}

    public function handle(OrderCompleted $event): void
    {
        $this->couponCheckout->recordUsageFromCompletedOrder($event->order);
    }
}
