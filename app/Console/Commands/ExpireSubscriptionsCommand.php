<?php

namespace App\Console\Commands;

use App\Events\SubscriptionCancelled;
use App\Events\SubscriptionPastDue;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Console\Command;

class ExpireSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:expire-due';

    protected $description = 'Marca assinaturas vencidas como past_due (com webhook), depois cancela as que excederem o período de tolerância.';

    public function handle(): int
    {
        $today = now()->startOfDay()->toDateString();

        $toPastDue = Subscription::query()
            ->where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('current_period_end')
            ->whereDate('current_period_end', '<', $today)
            ->whereHas('subscriptionPlan', fn ($q) => $q->where('interval', '!=', SubscriptionPlan::INTERVAL_LIFETIME))
            ->with(['user', 'product', 'subscriptionPlan'])
            ->get();

        $pastDueCount = 0;
        foreach ($toPastDue as $subscription) {
            $subscription->update(['status' => Subscription::STATUS_PAST_DUE]);
            event(new SubscriptionPastDue($subscription->fresh()));
            $pastDueCount++;
        }
        $this->info("Assinaturas marcadas como past_due: {$pastDueCount}");

        $graceDays = (int) config('getfy.subscriptions.cancel_grace_days_after_period_end', 14);
        $cancelBefore = now()->startOfDay()->subDays($graceDays)->toDateString();

        $toCancel = Subscription::query()
            ->where('status', Subscription::STATUS_PAST_DUE)
            ->whereNotNull('current_period_end')
            ->whereDate('current_period_end', '<=', $cancelBefore)
            ->whereHas('subscriptionPlan', fn ($q) => $q->where('interval', '!=', SubscriptionPlan::INTERVAL_LIFETIME))
            ->with(['user', 'product', 'subscriptionPlan'])
            ->get();

        $cancelledCount = 0;
        foreach ($toCancel as $subscription) {
            $subscription->update(['status' => Subscription::STATUS_CANCELLED]);
            event(new SubscriptionCancelled($subscription->fresh()));
            $cancelledCount++;
        }
        $this->info("Assinaturas canceladas (após {$graceDays} dias do fim do período): {$cancelledCount}");

        return self::SUCCESS;
    }
}
