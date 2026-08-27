<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Tests\TestCase;

class BackfillMemberAccessSubscriptionsTest extends TestCase
{
    private function createSubscriptionProduct(): Product
    {
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'checkout_slug' => 'bf'.substr(uniqid('', true), -8),
        ]);

        SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 29.90,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => 'pm-'.substr(uniqid('', true), -8),
            'position' => 1,
        ]);

        SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Vitalício',
            'price' => 297,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_LIFETIME,
            'checkout_slug' => 'pl-'.substr(uniqid('', true), -8),
            'position' => 2,
        ]);

        return $product->fresh();
    }

    public function test_backfill_creates_subscription_for_legacy_product_user_only(): void
    {
        $product = $this->createSubscriptionProduct();
        $aluno = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product->users()->syncWithoutDetaching([(string) $aluno->id]);

        $this->assertFalse($product->hasMemberAreaAccess($aluno));

        $this->artisan('members:backfill-subscriptions')
            ->assertSuccessful();

        $product->refresh();
        $this->assertTrue($product->hasMemberAreaAccess($aluno));

        $subscription = Subscription::query()
            ->where('user_id', $aluno->id)
            ->where('product_id', $product->id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->first();

        $this->assertNotNull($subscription);
        $this->assertTrue($subscription->subscriptionPlan->isLifetime());
    }

    public function test_backfill_skips_when_active_subscription_exists(): void
    {
        $product = $this->createSubscriptionProduct();
        $aluno = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product->users()->syncWithoutDetaching([(string) $aluno->id]);

        $lifetime = $product->subscriptionPlans()->where('interval', SubscriptionPlan::INTERVAL_LIFETIME)->first();
        [$start, $end] = $lifetime->getCurrentPeriod();

        Subscription::create([
            'tenant_id' => $product->tenant_id,
            'user_id' => $aluno->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $lifetime->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => $start,
            'current_period_end' => $end,
        ]);

        $this->artisan('members:backfill-subscriptions')
            ->assertSuccessful();

        $this->assertSame(1, Subscription::query()
            ->where('user_id', $aluno->id)
            ->where('product_id', $product->id)
            ->count());
    }

    public function test_backfill_dry_run_does_not_create_subscription(): void
    {
        $product = $this->createSubscriptionProduct();
        $aluno = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product->users()->syncWithoutDetaching([(string) $aluno->id]);

        $this->artisan('members:backfill-subscriptions', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0, Subscription::query()
            ->where('user_id', $aluno->id)
            ->where('product_id', $product->id)
            ->count());
        $this->assertFalse($product->hasMemberAreaAccess($aluno));
    }

    public function test_backfill_skips_product_without_plans(): void
    {
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'checkout_slug' => 'np'.substr(uniqid('', true), -8),
        ]);
        $aluno = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product->users()->syncWithoutDetaching([(string) $aluno->id]);

        $this->artisan('members:backfill-subscriptions')
            ->assertSuccessful();

        $this->assertSame(0, Subscription::query()
            ->where('user_id', $aluno->id)
            ->where('product_id', $product->id)
            ->count());
    }

    public function test_backfill_include_inactive_processes_expired_subscription(): void
    {
        $product = $this->createSubscriptionProduct();
        $aluno = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product->users()->syncWithoutDetaching([(string) $aluno->id]);

        $monthly = $product->subscriptionPlans()->where('interval', SubscriptionPlan::INTERVAL_MONTHLY)->first();

        Subscription::create([
            'tenant_id' => $product->tenant_id,
            'user_id' => $aluno->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $monthly->id,
            'status' => Subscription::STATUS_CANCELLED,
            'current_period_start' => now()->subMonths(2)->toDateString(),
            'current_period_end' => now()->subMonth()->toDateString(),
        ]);

        $this->assertFalse($product->hasMemberAreaAccess($aluno));

        $this->artisan('members:backfill-subscriptions', ['--include-inactive' => true])
            ->assertSuccessful();

        $product->refresh();
        $this->assertTrue($product->hasMemberAreaAccess($aluno));

        $active = Subscription::query()
            ->where('user_id', $aluno->id)
            ->where('product_id', $product->id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->first();

        $this->assertNotNull($active);
        $this->assertTrue($active->subscriptionPlan->isLifetime());
    }
}
