<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\Order;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ManualOrderRefundTest extends TestCase
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
    private function createMerchantWithWallet(float $availablePix = 95.0): User
    {
        $merchant = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
            'email_verified_at' => now(),
        ]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();

        if (Schema::hasTable('tenant_wallets')) {
            TenantWallet::query()->create([
                'tenant_id' => $merchant->id,
                'available_balance' => $availablePix,
                'pending_balance' => 0,
                'currency' => 'BRL',
                'available_pix' => $availablePix,
                'available_card' => 0,
                'available_boleto' => 0,
                'pending_pix' => 0,
                'pending_card' => 0,
                'pending_boleto' => 0,
            ]);
        }

        return $merchant;
    }

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function createCompletedOrder(User $merchant, float $amount = 100.0): Order
    {
        $product = $this->createTestProduct(['tenant_id' => $merchant->id]);

        return Order::create([
            'tenant_id' => $merchant->id,
            'user_id' => $merchant->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => $amount,
            'gateway' => 'stripe',
            'payment_method' => 'pix',
            'approved_manually' => false,
            'email' => 'buyer@test.com',
        ]);
    }

    public function test_seller_can_refund_completed_order_and_debit_wallet(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        $merchant = $this->createMerchantWithWallet(95.0);
        $order = $this->createCompletedOrder($merchant, 100.0);

        WalletTransaction::create([
            'tenant_id' => $merchant->id,
            'order_id' => $order->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE,
            'amount_gross' => 100.00,
            'amount_fee' => 5.00,
            'amount_net' => 95.00,
        ]);

        $response = $this->actingAs($merchant)->postJson(route('vendas.refund-manually', $order), [
            'reason' => 'Cliente pediu por WhatsApp',
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $this->assertIsArray($order->metadata['manual_refund'] ?? null);
        $this->assertSame('seller', $order->metadata['manual_refund']['initiated_by'] ?? null);
        $this->assertSame('Cliente pediu por WhatsApp', $order->metadata['manual_refund']['reason'] ?? null);

        $wallet = TenantWallet::query()->where('tenant_id', $merchant->id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(0.0, (float) $wallet->available_pix);

        $this->assertTrue(
            WalletTransaction::query()
                ->where('order_id', $order->id)
                ->where('type', WalletTransaction::TYPE_DEBIT_REFUND)
                ->exists()
        );
    }

    public function test_admin_refund_requires_reason(): void
    {
        $admin = $this->platformAdmin();
        $merchant = $this->createMerchantWithWallet();
        $order = $this->createCompletedOrder($merchant);

        $response = $this->actingAs($admin)->post(route('plataforma.transacoes.pedidos.refund', $order));

        $response->assertSessionHasErrors('reason');
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_admin_refund_stores_reason_in_order_metadata(): void
    {
        $admin = $this->platformAdmin();
        $merchant = $this->createMerchantWithWallet();
        $order = $this->createCompletedOrder($merchant);

        $response = $this->actingAs($admin)->post(route('plataforma.transacoes.pedidos.refund', $order), [
            'reason' => 'Chargeback direto com a plataforma',
        ]);

        $response->assertRedirect();
        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $this->assertSame('platform', $order->metadata['manual_refund']['initiated_by'] ?? null);
        $this->assertSame('Chargeback direto com a plataforma', $order->metadata['manual_refund']['reason'] ?? null);
    }

    public function test_seller_sees_admin_refund_reason_on_vendas_index(): void
    {
        $merchant = $this->createMerchantWithWallet();
        $order = $this->createCompletedOrder($merchant);
        $order->update([
            'status' => 'refunded',
            'metadata' => [
                'manual_refund' => [
                    'initiated_by' => 'platform',
                    'initiated_by_user_id' => 1,
                    'initiated_by_name' => 'Admin Plataforma',
                    'reason' => 'Contato direto com suporte',
                    'refunded_at' => now()->toIso8601String(),
                    'gateway_refund' => ['status' => 'skipped', 'note' => null],
                ],
            ],
        ]);

        $response = $this->actingAs($merchant)->get(route('vendas.index'));

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Vendas/Index')
            ->has('vendas.data', 1)
            ->where('vendas.data.0.manual_refund.reason', 'Contato direto com suporte')
            ->where('vendas.data.0.manual_refund.initiated_by', 'platform')
        );
    }

    public function test_seller_cannot_refund_pending_order(): void
    {
        $merchant = $this->createMerchantWithWallet();
        $product = $this->createTestProduct(['tenant_id' => $merchant->id]);

        $order = Order::create([
            'tenant_id' => $merchant->id,
            'user_id' => $merchant->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 50.0,
            'gateway' => 'stripe',
            'approved_manually' => false,
            'email' => 'pending@test.com',
        ]);

        $response = $this->actingAs($merchant)->postJson(route('vendas.refund-manually', $order));

        $response->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame('pending', $order->fresh()->status);
    }
}
