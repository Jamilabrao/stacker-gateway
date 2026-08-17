<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\Order;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\OrderRefundGatewayBridge;
use App\Services\Platform\PlatformTotpService;
use App\Support\OrderManualRefund;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\GeneratesTotpCodes;
use Tests\TestCase;

class ManualOrderRefundTest extends TestCase
{
    use GeneratesTotpCodes;
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

    public function test_manual_refund_revokes_member_access_and_appears_in_reembolsos(): void
    {
        if (! Schema::hasTable('product_user') || ! Schema::hasTable('refund_requests')) {
            $this->markTestSkipped('product_user/refund_requests');
        }

        $merchant = $this->createMerchantWithWallet(95.0);
        $buyer = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'email' => 'aluno-refund@test.com',
        ]);
        $product = $this->createTestProduct(['tenant_id' => $merchant->id]);

        $order = Order::create([
            'tenant_id' => $merchant->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100.0,
            'gateway' => 'stripe',
            'payment_method' => 'pix',
            'approved_manually' => false,
            'email' => $buyer->email,
        ]);
        $order->grantPurchasedProductAccessToBuyer();
        $this->assertTrue($product->fresh()->hasMemberAreaAccess($buyer));

        if (Schema::hasTable('tenant_wallets') && Schema::hasTable('wallet_transactions')) {
            WalletTransaction::create([
                'tenant_id' => $merchant->id,
                'order_id' => $order->id,
                'bucket' => 'pix',
                'type' => WalletTransaction::TYPE_CREDIT_SALE,
                'amount_gross' => 100.00,
                'amount_fee' => 5.00,
                'amount_net' => 95.00,
            ]);
        }

        $response = $this->actingAs($merchant)->postJson(route('vendas.refund-manually', $order), [
            'reason' => 'Cliente pediu cancelamento',
        ]);
        $response->assertOk()->assertJson(['success' => true]);

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertFalse($product->fresh()->hasMemberAreaAccess($buyer->fresh()));
        $this->assertFalse(
            $product->users()->where('user_id', $buyer->id)->exists()
        );

        $this->assertDatabaseHas('refund_requests', [
            'order_id' => $order->id,
            'user_id' => $buyer->id,
            'tenant_id' => $merchant->id,
            'status' => 'approved',
        ]);

        $page = $this->actingAs($merchant)->get(route('reembolsos.index', ['status' => 'approved']));
        $page->assertOk()->assertInertia(fn ($assert) => $assert
            ->component('Reembolsos/Index')
            ->has('requests.data', 1)
            ->where('requests.data.0.order_id', $order->id)
        );
    }

    public function test_refund_keeps_access_when_buyer_has_another_paid_order(): void
    {
        if (! Schema::hasTable('product_user')) {
            $this->markTestSkipped('product_user');
        }

        $merchant = $this->createMerchantWithWallet(200.0);
        $buyer = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'email' => 'aluno-keep@test.com',
        ]);
        $product = $this->createTestProduct(['tenant_id' => $merchant->id]);

        $keep = Order::create([
            'tenant_id' => $merchant->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100.0,
            'gateway' => 'stripe',
            'payment_method' => 'pix',
            'approved_manually' => false,
            'email' => $buyer->email,
        ]);
        $keep->grantPurchasedProductAccessToBuyer();

        $refund = Order::create([
            'tenant_id' => $merchant->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100.0,
            'gateway' => 'stripe',
            'payment_method' => 'pix',
            'approved_manually' => false,
            'email' => $buyer->email,
        ]);
        $refund->grantPurchasedProductAccessToBuyer();

        if (Schema::hasTable('wallet_transactions')) {
            WalletTransaction::create([
                'tenant_id' => $merchant->id,
                'order_id' => $refund->id,
                'bucket' => 'pix',
                'type' => WalletTransaction::TYPE_CREDIT_SALE,
                'amount_gross' => 100.00,
                'amount_fee' => 5.00,
                'amount_net' => 95.00,
            ]);
        }

        $this->actingAs($merchant)->postJson(route('vendas.refund-manually', $refund), [
            'reason' => 'Duplicidade',
        ])->assertOk();

        $this->assertTrue($product->fresh()->hasMemberAreaAccess($buyer->fresh()));
        $this->assertTrue($product->users()->where('user_id', $buyer->id)->exists());
    }

    private function platformAdminWithTotp(): array
    {
        $admin = $this->platformAdmin();
        $setup = PlatformTotpService::beginEnrollment($admin->fresh());
        PlatformTotpService::confirmEnrollment(
            $admin->fresh(),
            $this->totpCodeForSecret($setup['secret'])
        );

        return ['admin' => $admin->fresh(), 'secret' => $setup['secret']];
    }

    public function test_offline_refund_does_not_require_totp_when_not_enabled(): void
    {
        $admin = $this->platformAdmin();
        $merchant = $this->createMerchantWithWallet();
        $order = $this->createCompletedOrder($merchant);

        $this->actingAs($admin)
            ->post(route('plataforma.transacoes.pedidos.refund-offline', $order), [
                'reason' => 'Estorno feito no painel do adquirente',
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $this->assertTrue(OrderManualRefund::isOffline($order));
    }

    public function test_offline_refund_requires_totp_code_when_enabled(): void
    {
        ['admin' => $admin] = $this->platformAdminWithTotp();
        $merchant = $this->createMerchantWithWallet();
        $order = $this->createCompletedOrder($merchant);

        $this->actingAs($admin)
            ->post(route('plataforma.transacoes.pedidos.refund-offline', $order), [
                'reason' => 'Estorno feito no painel do adquirente',
            ])
            ->assertSessionHasErrors('totp_code');

        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_offline_refund_requires_reason(): void
    {
        ['admin' => $admin, 'secret' => $secret] = $this->platformAdminWithTotp();
        $merchant = $this->createMerchantWithWallet();
        $order = $this->createCompletedOrder($merchant);

        $this->actingAs($admin)
            ->post(route('plataforma.transacoes.pedidos.refund-offline', $order), [
                'totp_code' => $this->totpCodeForSecret($secret),
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_offline_refund_rejects_invalid_totp(): void
    {
        ['admin' => $admin] = $this->platformAdminWithTotp();
        $merchant = $this->createMerchantWithWallet();
        $order = $this->createCompletedOrder($merchant);

        $this->actingAs($admin)
            ->post(route('plataforma.transacoes.pedidos.refund-offline', $order), [
                'reason' => 'Estorno feito no painel do adquirente',
                'totp_code' => '000000',
            ])
            ->assertSessionHasErrors('totp_code');

        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_offline_refund_skips_gateway_debits_wallet_revokes_access_and_sets_status_label(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        $this->mock(OrderRefundGatewayBridge::class, function ($mock) {
            $mock->shouldNotReceive('tryRefund');
        });

        $merchant = $this->createMerchantWithWallet(95.0);
        $buyer = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'email' => 'aluno-offline-refund@test.com',
        ]);
        $product = $this->createTestProduct(['tenant_id' => $merchant->id]);

        $order = Order::create([
            'tenant_id' => $merchant->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100.0,
            'gateway' => 'stripe',
            'payment_method' => 'pix',
            'approved_manually' => false,
            'email' => $buyer->email,
        ]);

        if (Schema::hasTable('product_user')) {
            $order->grantPurchasedProductAccessToBuyer();
            $this->assertTrue($product->fresh()->hasMemberAreaAccess($buyer));
        }

        WalletTransaction::create([
            'tenant_id' => $merchant->id,
            'order_id' => $order->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE,
            'amount_gross' => 100.00,
            'amount_fee' => 5.00,
            'amount_net' => 95.00,
        ]);

        ['admin' => $admin, 'secret' => $secret] = $this->platformAdminWithTotp();

        $this->actingAs($admin)
            ->post(route('plataforma.transacoes.pedidos.refund-offline', $order), [
                'reason' => 'Reembolso falhou no gateway; feito no painel do adquirente',
                'totp_code' => $this->totpCodeForSecret($secret),
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $this->assertTrue(OrderManualRefund::isOffline($order));
        $this->assertSame('Reembolso manual', OrderManualRefund::statusLabel($order));
        $this->assertSame('platform', $order->metadata['manual_refund']['initiated_by'] ?? null);
        $this->assertTrue((bool) ($order->metadata['manual_refund']['offline'] ?? false));

        $wallet = TenantWallet::query()->where('tenant_id', $merchant->id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(0.0, (float) $wallet->available_pix);

        $this->assertTrue(
            WalletTransaction::query()
                ->where('order_id', $order->id)
                ->where('type', WalletTransaction::TYPE_DEBIT_REFUND)
                ->exists()
        );

        if (Schema::hasTable('product_user')) {
            $this->assertFalse($product->fresh()->hasMemberAreaAccess($buyer->fresh()));
        }

        $this->actingAs($admin)
            ->get(route('plataforma.transacoes.index', ['q' => 'aluno-offline-refund@test.com']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Transactions/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.status_label', 'Reembolso manual')
                ->where('orders.data.0.manual_refund.offline', true)
            );
    }

    public function test_offline_refund_cannot_be_applied_to_pending_order(): void
    {
        ['admin' => $admin, 'secret' => $secret] = $this->platformAdminWithTotp();
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
            'email' => 'pending-offline@test.com',
        ]);

        $this->actingAs($admin)
            ->from(route('plataforma.transacoes.index'))
            ->post(route('plataforma.transacoes.pedidos.refund-offline', $order), [
                'reason' => 'Tentativa inválida em pedido pendente',
                'totp_code' => $this->totpCodeForSecret($secret),
            ])
            ->assertRedirect(route('plataforma.transacoes.index'))
            ->assertSessionHas('error');

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_seller_cannot_refund_when_wallet_balance_is_insufficient(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        $this->mock(OrderRefundGatewayBridge::class, function ($mock) {
            $mock->shouldNotReceive('tryRefund');
        });

        $merchant = $this->createMerchantWithWallet(0);
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
            'reason' => 'Cliente pediu estorno',
        ]);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'message' => \App\Services\SellerRefundBalanceGuard::denialMessage(0.0, 95.0),
        ]);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertFalse(
            WalletTransaction::query()
                ->where('order_id', $order->id)
                ->where('type', WalletTransaction::TYPE_DEBIT_REFUND)
                ->exists()
        );
    }

    public function test_seller_cannot_refund_when_wallet_has_partial_balance(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        $this->mock(OrderRefundGatewayBridge::class, function ($mock) {
            $mock->shouldNotReceive('tryRefund');
        });

        $merchant = $this->createMerchantWithWallet(40.0);
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
            'reason' => 'Cliente pediu estorno',
        ]);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'message' => \App\Services\SellerRefundBalanceGuard::denialMessage(40.0, 95.0),
        ]);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_seller_can_refund_using_pending_wallet_balance(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        $merchant = $this->createMerchantWithWallet(0);
        TenantWallet::query()->where('tenant_id', $merchant->id)->update([
            'pending_pix' => 95.0,
            'pending_balance' => 95.0,
        ]);
        $order = $this->createCompletedOrder($merchant, 100.0);

        WalletTransaction::create([
            'tenant_id' => $merchant->id,
            'order_id' => $order->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE_PENDING,
            'amount_gross' => 100.00,
            'amount_fee' => 5.00,
            'amount_net' => 95.00,
        ]);

        $response = $this->actingAs($merchant)->postJson(route('vendas.refund-manually', $order), [
            'reason' => 'Ainda em liquidação',
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame('refunded', $order->fresh()->status);

        $wallet = TenantWallet::query()->where('tenant_id', $merchant->id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(0.0, (float) $wallet->pending_pix);
        $this->assertEquals(0.0, (float) $wallet->available_pix);
    }

    public function test_platform_can_refund_when_seller_wallet_is_empty(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        $admin = $this->platformAdmin();
        $merchant = $this->createMerchantWithWallet(0);
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

        $response = $this->actingAs($admin)->post(route('plataforma.transacoes.pedidos.refund', $order), [
            'reason' => 'Chargeback com carteira zerada',
        ]);

        $response->assertRedirect();
        $this->assertSame('refunded', $order->fresh()->status);
    }

    public function test_seller_cannot_approve_refund_request_without_wallet_cover(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('refund_requests')) {
            $this->markTestSkipped('wallet/refund_requests');
        }

        $this->mock(OrderRefundGatewayBridge::class, function ($mock) {
            $mock->shouldNotReceive('tryRefund');
        });

        $merchant = $this->createMerchantWithWallet(0);
        $buyer = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'email' => 'aluno-refund-balance@test.com',
        ]);
        $order = $this->createCompletedOrder($merchant, 100.0);
        $order->update(['user_id' => $buyer->id, 'email' => $buyer->email]);

        WalletTransaction::create([
            'tenant_id' => $merchant->id,
            'order_id' => $order->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE,
            'amount_gross' => 100.00,
            'amount_fee' => 5.00,
            'amount_net' => 95.00,
        ]);

        $refundRequest = \App\Models\RefundRequest::query()->create([
            'order_id' => $order->id,
            'user_id' => $buyer->id,
            'tenant_id' => $merchant->id,
            'status' => \App\Models\RefundRequest::STATUS_PENDING,
            'customer_reason' => 'Quero o dinheiro de volta',
        ]);

        $this->actingAs($merchant)
            ->from(route('reembolsos.index'))
            ->post(route('reembolsos.approve', $refundRequest))
            ->assertRedirect(route('reembolsos.index'))
            ->assertSessionHas('error', \App\Services\SellerRefundBalanceGuard::denialMessage(0.0, 95.0));

        $this->assertSame('pending', $refundRequest->fresh()->status);
        $this->assertSame('completed', $order->fresh()->status);
    }
}
