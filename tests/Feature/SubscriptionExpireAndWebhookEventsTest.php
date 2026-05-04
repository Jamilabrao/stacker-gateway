<?php

namespace Tests\Feature;

use App\Events\SubscriptionCancelled;
use App\Events\SubscriptionPastDue;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionExpireAndWebhookEventsTest extends TestCase
{
    private function createSellerProductAndPlan(): array
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Produto teste',
            'slug' => 't-'.Str::lower(Str::random(8)),
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'price' => 10,
            'currency' => 'BRL',
            'is_active' => true,
        ]);

        $plan = SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 10,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => 'p-'.Str::lower(Str::random(8)),
            'position' => 1,
        ]);

        return [$seller, $product, $plan];
    }

    public function test_expire_due_emits_subscription_past_due(): void
    {
        Event::fake([SubscriptionPastDue::class, SubscriptionCancelled::class]);

        [$seller, $product, $plan] = $this->createSellerProductAndPlan();

        $buyer = User::factory()->create(['tenant_id' => $seller->id]);

        Subscription::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subDay(),
        ]);

        $this->artisan('subscriptions:expire-due')->assertSuccessful();

        Event::assertDispatched(SubscriptionPastDue::class);
    }

    public function test_expire_due_cancels_after_grace_and_emits_cancelled(): void
    {
        config(['getfy.subscriptions.cancel_grace_days_after_period_end' => 0]);

        Event::fake([SubscriptionPastDue::class, SubscriptionCancelled::class]);

        [$seller, $product, $plan] = $this->createSellerProductAndPlan();

        $buyer = User::factory()->create(['tenant_id' => $seller->id]);

        Subscription::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_PAST_DUE,
            'current_period_start' => now()->subMonths(2),
            'current_period_end' => now()->subDay(),
        ]);

        $this->artisan('subscriptions:expire-due')->assertSuccessful();

        Event::assertDispatched(SubscriptionCancelled::class);
    }
}
