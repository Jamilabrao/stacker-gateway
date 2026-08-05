<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\MemberAccessGrantService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class ManualMemberAccessGrantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
        ]);
    }

    private function createSeller(): User
    {
        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
        ]);
        $owner->forceFill(['tenant_id' => $owner->id])->save();

        return $owner->fresh();
    }

    private function createSubscriptionProduct(User $owner): array
    {
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'mag'.substr(uniqid('', true), -8),
            'slug' => 'mag-'.substr(uniqid('', true), -8),
        ]);

        $monthly = SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 29.90,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => 'pm-'.substr(uniqid('', true), -8),
            'position' => 1,
        ]);

        $lifetime = SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Vitalício',
            'price' => 297,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_LIFETIME,
            'checkout_slug' => 'pl-'.substr(uniqid('', true), -8),
            'position' => 2,
        ]);

        return [$product->fresh(), $monthly, $lifetime];
    }

    public function test_manual_aluno_store_creates_lifetime_subscription_and_grants_access(): void
    {
        $owner = $this->createSeller();
        [$product, $monthly, $lifetime] = $this->createSubscriptionProduct($owner);

        $email = 'aluno.manual.'.uniqid().'@example.com';

        $resp = $this->actingAs($owner)->postJson(route('alunos.store'), [
            'name' => 'Aluno Manual',
            'email' => $email,
            'password' => 'senha123',
            'product_ids' => [$product->id],
            'send_access_email' => false,
        ]);

        $resp->assertOk()->assertJsonFragment(['success' => true]);

        $aluno = User::where('email', $email)->first();

        $this->assertNotNull($aluno);
        $this->assertTrue($product->users()->where('user_id', $aluno->id)->exists());
        $this->assertTrue($product->hasMemberAreaAccess($aluno));

        $subscription = Subscription::query()
            ->where('user_id', $aluno->id)
            ->where('product_id', $product->id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->first();

        $this->assertNotNull($subscription);
        $this->assertSame($lifetime->id, $subscription->subscription_plan_id);
        $this->assertNull($subscription->current_period_end);
        $this->assertNotSame($monthly->id, $subscription->subscription_plan_id);
    }

    public function test_member_access_grant_service_prefers_lifetime_plan(): void
    {
        $owner = $this->createSeller();
        [$product, , $lifetime] = $this->createSubscriptionProduct($owner);

        $aluno = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);

        app(MemberAccessGrantService::class)->grant($aluno, $product);

        $this->assertTrue($product->hasMemberAreaAccess($aluno));
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $aluno->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $lifetime->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_member_builder_store_new_aluno_grants_subscription_access(): void
    {
        $owner = $this->createSeller();
        [$product, , $lifetime] = $this->createSubscriptionProduct($owner);

        $email = 'builder.aluno.'.uniqid().'@example.com';

        $resp = $this->actingAs($owner)->postJson(
            route('member-builder.alunos.store', $product),
            [
                'name' => 'Aluno Builder',
                'email' => $email,
                'password' => 'senha123',
            ]
        );

        $resp->assertOk();

        $aluno = User::where('email', $email)->first();
        $this->assertNotNull($aluno);
        $this->assertTrue($product->hasMemberAreaAccess($aluno));
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $aluno->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $lifetime->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_one_time_product_grant_does_not_create_subscription(): void
    {
        $owner = $this->createSeller();
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_ONE_TIME,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'ot'.substr(uniqid('', true), -8),
            'slug' => 'ot-'.substr(uniqid('', true), -8),
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);

        app(MemberAccessGrantService::class)->grant($aluno, $product);

        $this->assertTrue($product->hasMemberAreaAccess($aluno));
        $this->assertDatabaseMissing('subscriptions', [
            'user_id' => $aluno->id,
            'product_id' => $product->id,
        ]);
    }
}
