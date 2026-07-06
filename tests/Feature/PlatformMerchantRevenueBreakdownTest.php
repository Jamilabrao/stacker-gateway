<?php

namespace Tests\Feature;

use App\Models\ApiApplication;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlatformMerchantRevenueBreakdownTest extends TestCase
{
    public function test_merchant_show_includes_revenue_breakdown_by_channel(): void
    {
        if (! Schema::hasColumn('orders', 'api_application_id')) {
            $this->markTestSkipped('orders.api_application_id');
        }

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $apiApp = ApiApplication::create([
            'tenant_id' => $seller->id,
            'name' => 'API Test',
            'slug' => ApiApplication::SLUG_GLOBAL_PIX_API,
            'api_key_hash' => ApiApplication::hashApiKey('test-key'),
            'is_active' => true,
        ]);

        $checkoutOrder = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100.00,
            'gateway' => 'stripe',
            'approved_manually' => false,
            'email' => 'checkout@test.com',
            'api_application_id' => null,
        ]);

        $apiOrder = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => null,
            'api_application_id' => $apiApp->id,
            'status' => 'completed',
            'amount' => 250.00,
            'gateway' => 'spacepag',
            'payment_method' => 'pix',
            'approved_manually' => false,
            'email' => 'api@test.com',
            'metadata' => ['source' => 'api'],
        ]);

        Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 99.00,
            'gateway' => 'manual',
            'approved_manually' => false,
            'email' => 'manual@test.com',
        ]);

        Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50.00,
            'gateway' => 'stripe',
            'approved_manually' => true,
            'email' => 'manual-approval@test.com',
        ]);

        if (Schema::hasTable('wallet_transactions')) {
            WalletTransaction::create([
                'tenant_id' => $seller->id,
                'order_id' => $checkoutOrder->id,
                'bucket' => 'pix',
                'type' => WalletTransaction::TYPE_CREDIT_SALE,
                'amount_gross' => 100.00,
                'amount_fee' => 5.00,
                'amount_net' => 95.00,
            ]);
            WalletTransaction::create([
                'tenant_id' => $seller->id,
                'order_id' => $apiOrder->id,
                'bucket' => 'pix',
                'type' => WalletTransaction::TYPE_CREDIT_SALE,
                'amount_gross' => 250.00,
                'amount_fee' => 12.50,
                'amount_net' => 237.50,
            ]);
        }

        $response = $this->actingAs($admin)->get(route('plataforma.usuarios.show', $seller));

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Platform/Users/Show')
            ->where('merchant.vendas_totais', 350)
            ->where('revenue_breakdown.checkout.gross', 100)
            ->where('revenue_breakdown.checkout.count', 1)
            ->where('revenue_breakdown.api_pix.gross', 250)
            ->where('revenue_breakdown.api_pix.count', 1)
            ->where('revenue_breakdown.total.gross', 350)
            ->where('revenue_breakdown.total.count', 2)
            ->when(
                Schema::hasTable('wallet_transactions'),
                fn ($p) => $p
                    ->where('revenue_breakdown.checkout.fees', 5)
                    ->where('revenue_breakdown.api_pix.fees', 12.5)
                    ->where('revenue_breakdown.total.fees', 17.5)
            )
        );
    }
}
