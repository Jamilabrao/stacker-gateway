<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Services\ReferralCommissionRecorder;

class RecordReferralCommissionOnOrderCompleted
{
    public function handle(OrderCompleted $event): void
    {
        try {
            ReferralCommissionRecorder::recordForOrder($event->order);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
