<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Models\AffiliateCommission;
use App\Services\AffiliateCommissionNotifier;
use App\Services\AffiliateCommissionRecorder;

class RecordAffiliateCommissionOnOrderCompleted
{
    public function __construct(
        protected AffiliateCommissionNotifier $notifier,
    ) {}

    public function handle(OrderCompleted $event): void
    {
        try {
            $hadCommission = AffiliateCommission::query()
                ->where('order_id', $event->order->id)
                ->exists();

            $commission = AffiliateCommissionRecorder::recordForOrder($event->order);

            if ($commission !== null && ! $hadCommission) {
                $this->notifier->notifyNewCommission($commission);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
