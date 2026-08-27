<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\MemberAccessGrantService;
use Tests\TestCase;

class RepairLegacyMemberEnrollmentTest extends TestCase
{
    private function createSubscriptionProduct(): Product
    {
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'checkout_slug' => 'rp'.substr(uniqid('', true), -8),
        ]);

        SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Vitalício',
            'price' => 297,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_LIFETIME,
            'checkout_slug' => 'pl-'.substr(uniqid('', true), -8),
            'position' => 1,
        ]);

        return $product->fresh();
    }

    public function test_user_has_member_area_access_repairs_legacy_product_user_only(): void
    {
        $product = $this->createSubscriptionProduct();
        $aluno = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product->users()->syncWithoutDetaching([(string) $aluno->id]);

        $grant = app(MemberAccessGrantService::class);

        $this->assertFalse($product->hasMemberAreaAccess($aluno));
        $this->assertTrue($grant->userHasMemberAreaAccess($aluno, $product));
        $this->assertTrue($product->fresh()->hasMemberAreaAccess($aluno));

        $this->assertSame(1, Subscription::query()
            ->where('user_id', $aluno->id)
            ->where('product_id', $product->id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->count());
    }

    public function test_repair_does_not_grant_lifetime_to_expired_paid_subscription(): void
    {
        $product = $this->createSubscriptionProduct();

        SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 29.90,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => 'pm-'.substr(uniqid('', true), -8),
            'position' => 2,
        ]);

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

        $grant = app(MemberAccessGrantService::class);

        $this->assertFalse($grant->userHasMemberAreaAccess($aluno, $product));
        $this->assertSame(1, Subscription::query()
            ->where('user_id', $aluno->id)
            ->where('product_id', $product->id)
            ->count());
    }
}
