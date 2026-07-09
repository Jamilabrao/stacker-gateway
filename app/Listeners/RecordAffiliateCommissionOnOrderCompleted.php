<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Services\AffiliateCommissionRecorder;

class RecordAffiliateCommissionOnOrderCompleted
{
    public function handle(OrderCompleted $event): void
    {
        try {
            AffiliateCommissionRecorder::recordForOrder($event->order);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
