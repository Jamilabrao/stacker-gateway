<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\AffiliateCommission;
use App\Models\Order;
use App\Models\PanelNotification;
use App\Models\Product;
use App\Models\ProductAffiliateEnrollment;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\AffiliateCommissionRecorder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AffiliateCommissionRecordingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
    }

    private function createVerifiedInfoprodutor(): User
    {
        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
            'email_verified_at' => now(),
        ]);
        $user->forceFill(['tenant_id' => $user->id])->save();

        return $user;
    }

    private function createAffiliateSaleFixture(array $productOverrides = []): array
    {
        if (! Schema::hasTable('affiliate_commissions')) {
            $this->markTestSkipped('affiliate_commissions');
        }

        $seller = $this->createVerifiedInfoprodutor();
        $affiliate = $this->createVerifiedInfoprodutor();

        $product = $this->createTestProduct(array_merge([
            'tenant_id' => $seller->id,
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => 20,
            'affiliate_manual_approval' => false,
        ], $productOverrides));

        $enrollment = ProductAffiliateEnrollment::query()->create([
            'product_id' => $product->id,
            'affiliate_user_id' => $affiliate->id,
            'status' => ProductAffiliateEnrollment::STATUS_APPROVED,
            'public_ref' => 'rectest'.random_int(100000, 999999),
        ]);

        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO, 'name' => 'Comprador Teste']);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50.00,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'metadata' => [
                'affiliate_user_id' => $affiliate->id,
                'affiliate_enrollment_id' => $enrollment->id,
                'affiliate_ref' => $enrollment->public_ref,
                'sale_origin' => 'affiliate_link',
            ],
        ]);

        event(new OrderCompleted($order->fresh()));
        AffiliateCommissionRecorder::recordForOrder($order->fresh());

        return compact('seller', 'affiliate', 'product', 'enrollment', 'buyer', 'order');
    }

    public function test_order_completed_creates_affiliate_commission_record(): void
    {
        if (! Schema::hasTable('affiliate_commissions') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('affiliate_commissions or wallet');
        }

        $fixture = $this->createAffiliateSaleFixture();
        $order = $fixture['order'];
        $affiliate = $fixture['affiliate'];

        $commission = AffiliateCommission::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($commission);
        $this->assertSame($affiliate->id, $commission->affiliate_user_id);
        $this->assertSame(20.0, (float) $commission->commission_percent);
        $this->assertSame(50.0, (float) $commission->sale_gross);
        $this->assertSame(10.0, (float) $commission->commission_gross);

        $affiliateWallet = WalletTransaction::query()
            ->where('order_id', $order->id)
            ->where('tenant_id', $affiliate->id)
            ->first();
        $this->assertNotNull($affiliateWallet);
    }

    public function test_affiliate_sales_panel_lists_commission_for_affiliate(): void
    {
        if (! Schema::hasTable('affiliate_commissions')) {
            $this->markTestSkipped('affiliate_commissions');
        }

        $fixture = $this->createAffiliateSaleFixture(['affiliate_commission_percent' => 15]);
        $affiliate = $fixture['affiliate'];

        $response = $this->actingAs($affiliate)->get(route('produtos.afiliados.vendas'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Produtos/Afiliados/Vendas')
            ->has('vendas.data', 1)
        );
    }

    public function test_vendas_affiliate_tab_lists_commission_for_affiliate(): void
    {
        if (! Schema::hasTable('affiliate_commissions')) {
            $this->markTestSkipped('affiliate_commissions');
        }

        $fixture = $this->createAffiliateSaleFixture();
        $affiliate = $fixture['affiliate'];

        $response = $this->actingAs($affiliate)->get(route('vendas.index', ['view' => 'affiliate']));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Vendas/Index')
            ->where('view', 'affiliate')
            ->has('vendas.data', 1)
        );
    }

    public function test_affiliate_cannot_see_other_affiliate_commissions_in_vendas_tab(): void
    {
        if (! Schema::hasTable('affiliate_commissions')) {
            $this->markTestSkipped('affiliate_commissions');
        }

        $fixture = $this->createAffiliateSaleFixture();
        $otherAffiliate = $this->createVerifiedInfoprodutor();

        ProductAffiliateEnrollment::query()->create([
            'product_id' => $fixture['product']->id,
            'affiliate_user_id' => $otherAffiliate->id,
            'status' => ProductAffiliateEnrollment::STATUS_APPROVED,
            'public_ref' => 'otheraff'.random_int(100000, 999999),
        ]);

        $response = $this->actingAs($otherAffiliate)->get(route('vendas.index', ['view' => 'affiliate']));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Vendas/Index')
            ->where('view', 'affiliate')
            ->has('vendas.data', 0)
        );
    }

    public function test_hide_customer_data_omits_buyer_fields_for_affiliate(): void
    {
        if (! Schema::hasTable('affiliate_commissions')) {
            $this->markTestSkipped('affiliate_commissions');
        }

        $fixture = $this->createAffiliateSaleFixture(['affiliate_hide_customer_data' => true]);
        $affiliate = $fixture['affiliate'];

        $response = $this->actingAs($affiliate)->get(route('vendas.index', ['view' => 'affiliate']));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('vendas.data', 1)
            ->where('vendas.data.0.customer_hidden', true)
            ->missing('vendas.data.0.customer_name')
            ->missing('vendas.data.0.customer_email')
        );
    }

    public function test_order_completed_creates_panel_notification_for_affiliate(): void
    {
        if (! Schema::hasTable('affiliate_commissions') || ! Schema::hasTable('panel_notifications')) {
            $this->markTestSkipped('affiliate_commissions or panel_notifications');
        }

        $fixture = $this->createAffiliateSaleFixture();
        $affiliate = $fixture['affiliate'];
        $order = $fixture['order'];

        $this->assertTrue(
            PanelNotification::query()
                ->where('user_id', $affiliate->id)
                ->where('type', 'affiliate_sale_approved')
                ->where('event_key', 'affiliate_sale_'.$order->id)
                ->exists()
        );
    }

    public function test_dashboard_includes_affiliate_stats_when_enrolled(): void
    {
        if (! Schema::hasTable('affiliate_commissions')) {
            $this->markTestSkipped('affiliate_commissions');
        }

        $fixture = $this->createAffiliateSaleFixture();
        $affiliate = $fixture['affiliate'];

        $response = $this->actingAs($affiliate)->get(route('dashboard'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->where('has_affiliate_enrollments', true)
            ->has('affiliate_stats')
            ->has('affiliate_recent_sales', 1)
        );
    }

    public function test_producer_vendas_includes_affiliate_fields(): void
    {
        if (! Schema::hasTable('affiliate_commissions')) {
            $this->markTestSkipped('affiliate_commissions');
        }

        $seller = $this->createVerifiedInfoprodutor();
        $affiliate = $this->createVerifiedInfoprodutor();
        $affiliate->forceFill(['name' => 'Afiliado Teste'])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => 10,
        ]);

        $enrollment = ProductAffiliateEnrollment::query()->create([
            'product_id' => $product->id,
            'affiliate_user_id' => $affiliate->id,
            'status' => ProductAffiliateEnrollment::STATUS_APPROVED,
            'public_ref' => 'prodvendatest1',
        ]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 80,
            'email' => 'x@test.com',
            'payment_method' => 'pix',
            'affiliate_user_id' => $affiliate->id,
            'affiliate_enrollment_id' => $enrollment->id,
            'sale_origin' => 'affiliate_link',
            'metadata' => [
                'affiliate_user_id' => $affiliate->id,
                'affiliate_enrollment_id' => $enrollment->id,
                'sale_origin' => 'affiliate_link',
            ],
        ]);

        event(new OrderCompleted($order->fresh()));
        AffiliateCommissionRecorder::recordForOrder($order->fresh());

        $response = $this->actingAs($seller)->get(route('vendas.index'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Vendas/Index')
            ->has('vendas.data', 1)
            ->where('vendas.data.0.is_affiliate_sale', true)
            ->where('vendas.data.0.affiliate_name', 'Afiliado Teste')
        );
    }
}
