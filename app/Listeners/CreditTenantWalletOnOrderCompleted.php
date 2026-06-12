<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Services\OrderCompletedWalletCreditor;

class CreditTenantWalletOnOrderCompleted
{
    public function handle(OrderCompleted $event): void
    {
        try {
            OrderCompletedWalletCreditor::credit($event->order);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
