<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Models\AffiliateCommission;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAffiliateEnrollment;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AffiliateCommissionRecordingTest extends TestCase
{
    public function test_order_completed_creates_affiliate_commission_record(): void
    {
        if (! Schema::hasTable('affiliate_commissions') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('affiliate_commissions or wallet');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $affiliate = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $affiliate->forceFill(['tenant_id' => $affiliate->id])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => 20,
            'affiliate_manual_approval' => false,
        ]);

        $enrollment = ProductAffiliateEnrollment::query()->create([
            'product_id' => $product->id,
            'affiliate_user_id' => $affiliate->id,
            'status' => ProductAffiliateEnrollment::STATUS_APPROVED,
            'public_ref' => 'rectest123456',
        ]);

        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50.00,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'affiliate_user_id' => $affiliate->id,
            'affiliate_enrollment_id' => $enrollment->id,
            'sale_origin' => 'affiliate_link',
            'metadata' => [
                'affiliate_user_id' => $affiliate->id,
                'affiliate_enrollment_id' => $enrollment->id,
                'affiliate_ref' => $enrollment->public_ref,
                'sale_origin' => 'affiliate_link',
            ],
        ]);

        event(new OrderCompleted($order->fresh()));

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

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $affiliate = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $affiliate->forceFill(['tenant_id' => $affiliate->id])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => 15,
        ]);

        $enrollment = ProductAffiliateEnrollment::query()->create([
            'product_id' => $product->id,
            'affiliate_user_id' => $affiliate->id,
            'status' => ProductAffiliateEnrollment::STATUS_APPROVED,
            'public_ref' => 'paneltest12345',
        ]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100,
            'email' => 'buyer@test.com',
            'payment_method' => 'pix',
            'affiliate_user_id' => $affiliate->id,
            'affiliate_enrollment_id' => $enrollment->id,
            'metadata' => [
                'affiliate_user_id' => $affiliate->id,
                'affiliate_enrollment_id' => $enrollment->id,
            ],
        ]);

        event(new OrderCompleted($order->fresh()));

        $response = $this->actingAs($affiliate)->get(route('produtos.afiliados.vendas'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Produtos/Afiliados/Vendas')
            ->has('vendas.data', 1)
        );
    }

    public function test_producer_vendas_includes_affiliate_fields(): void
    {
        if (! Schema::hasTable('affiliate_commissions')) {
            $this->markTestSkipped('affiliate_commissions');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $affiliate = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'name' => 'Afiliado Teste']);
        $affiliate->forceFill(['tenant_id' => $affiliate->id])->save();

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
