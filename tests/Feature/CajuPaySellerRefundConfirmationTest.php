<?php

namespace Tests\Feature;

use App\Events\OrderRefunded;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Jobs\PollCajuPayPixRefundJob;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\CajuPay\CajuPayPixRefundConfirmationService;
use App\Services\PlatformOrderAdminService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CajuPaySellerRefundConfirmationTest extends TestCase
{
    private const PAYMENT_ID = '550e8400-e29b-41d4-a716-446655440099';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
        config(['services.cajupay.base_url' => 'https://api.cajupay.com.br']);
        Event::fake([OrderRefunded::class]);
    }

    public function test_seller_refund_debits_immediately_and_stays_pending_while_caju_still_paid(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        Queue::fake();
        $this->fakeCajuPayHttp(paymentStatus: 'paid', refundStatus: 'submitted');

        $merchant = $this->createMerchantWithWallet(95.0);
        $this->connectCajuPay();
        $order = $this->createCajuPixOrder($merchant);
        $this->creditSale($merchant, $order);

        $this->actingAs($merchant)->postJson(route('vendas.refund-manually', $order), [
            'reason' => 'Cliente pediu estorno',
        ])->assertOk()->assertJson(['success' => true]);

        $order->refresh();
        $this->assertSame('refund_pending', $order->status);
        $this->assertTrue((bool) ($order->metadata['cajupay_pix_refund_pending'] ?? false));

        $wallet = TenantWallet::query()->where('tenant_id', $merchant->id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(0.0, (float) $wallet->available_pix);
        $this->assertTrue(
            WalletTransaction::query()
                ->where('order_id', $order->id)
                ->where('type', WalletTransaction::TYPE_DEBIT_REFUND)
                ->exists()
        );

        Queue::assertPushed(PollCajuPayPixRefundJob::class);
    }

    public function test_seller_refund_is_finalized_when_caju_already_cancelled(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        Queue::fake();
        $this->fakeCajuPayHttp(paymentStatus: 'cancelled', refundStatus: 'submitted');

        $merchant = $this->createMerchantWithWallet(95.0);
        $this->connectCajuPay();
        $order = $this->createCajuPixOrder($merchant);
        $this->creditSale($merchant, $order);

        $this->actingAs($merchant)->postJson(route('vendas.refund-manually', $order), [
            'reason' => 'Estorno na CajuPay',
        ])->assertOk()->assertJson(['success' => true]);

        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $this->assertSame('cancelled', $order->metadata['cajupay_pix_refund_status'] ?? null);
        $this->assertArrayNotHasKey('cajupay_pix_refund_pending', $order->metadata ?? []);

        $wallet = TenantWallet::query()->where('tenant_id', $merchant->id)->first();
        $this->assertEquals(0.0, (float) $wallet->available_pix);
        $this->assertSame(
            1,
            WalletTransaction::query()
                ->where('order_id', $order->id)
                ->where('type', WalletTransaction::TYPE_DEBIT_REFUND)
                ->count()
        );
    }

    public function test_poll_job_finalizes_refund_pending_when_caju_status_is_cancelled(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        $this->fakeCajuPayHttp(paymentStatus: 'cancelled', refundStatus: 'submitted');

        $merchant = $this->createMerchantWithWallet(95.0);
        $this->connectCajuPay();
        $order = $this->createCajuPixOrder($merchant);
        $this->creditSale($merchant, $order);

        PlatformOrderAdminService::beginPendingGatewayRefund($order, null, 'seller_manual_refund');
        $order->refresh();
        $this->assertSame('refund_pending', $order->status);

        (new PollCajuPayPixRefundJob($order->id))->handle(app(CajuPayPixRefundConfirmationService::class));

        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $this->assertSame(
            1,
            WalletTransaction::query()
                ->where('order_id', $order->id)
                ->where('type', WalletTransaction::TYPE_DEBIT_REFUND)
                ->count()
        );
    }

    public function test_reconcile_command_finalizes_refund_pending_when_caju_is_cancelled(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        $this->fakeCajuPayHttp(paymentStatus: 'cancelled', refundStatus: 'submitted');

        $merchant = $this->createMerchantWithWallet(95.0);
        $this->connectCajuPay();
        $order = $this->createCajuPixOrder($merchant);
        $this->creditSale($merchant, $order);
        PlatformOrderAdminService::beginPendingGatewayRefund($order, null, 'seller_manual_refund');

        $this->artisan('payments:reconcile-cajupay-refunds')->assertSuccessful();

        $this->assertSame('refunded', $order->fresh()->status);
    }

    public function test_seller_refund_locks_wallet_when_caju_post_fails_but_payment_is_already_cancelled(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        Queue::fake();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/pix-refund') && $request->method() === 'POST') {
                return Http::response(['error' => 'refund_not_eligible:already_cancelled'], 409);
            }
            if (str_contains($url, '/pix-refund')) {
                return Http::response(['status' => 'devolvido', 'payment_id' => self::PAYMENT_ID], 200);
            }
            if (str_contains($url, '/api/payments/')) {
                return Http::response([
                    'payment_id' => self::PAYMENT_ID,
                    'status' => 'cancelled',
                ], 200);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });

        $merchant = $this->createMerchantWithWallet(95.0);
        $this->connectCajuPay();
        $order = $this->createCajuPixOrder($merchant);
        $this->creditSale($merchant, $order);

        $this->actingAs($merchant)->postJson(route('vendas.refund-manually', $order), [
            'reason' => 'Já estornado na Caju',
        ])->assertOk()->assertJson(['success' => true]);

        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $wallet = TenantWallet::query()->where('tenant_id', $merchant->id)->first();
        $this->assertEquals(0.0, (float) $wallet->available_pix);
    }

    private function connectCajuPay(): void
    {
        $cred = new GatewayCredential([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
            'is_connected' => true,
            'is_enabled' => true,
        ]);
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
        ]);
        $cred->save();
    }

    private function fakeCajuPayHttp(string $paymentStatus, string $refundStatus): void
    {
        Http::fake(function ($request) use ($paymentStatus, $refundStatus) {
            $url = $request->url();
            if (str_contains($url, '/pix-refund')) {
                return Http::response([
                    'status' => $refundStatus,
                    'payment_id' => self::PAYMENT_ID,
                ], 200);
            }
            if (str_contains($url, '/api/payments/')) {
                return Http::response([
                    'payment_id' => self::PAYMENT_ID,
                    'status' => $paymentStatus,
                ], 200);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });
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

        return $merchant;
    }

    private function createCajuPixOrder(User $merchant): Order
    {
        $product = $this->createTestProduct(['tenant_id' => $merchant->id]);

        return Order::create([
            'tenant_id' => $merchant->id,
            'user_id' => $merchant->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100.0,
            'gateway' => 'cajupay',
            'gateway_id' => self::PAYMENT_ID,
            'payment_method' => 'pix',
            'email' => 'buyer@test.com',
            'metadata' => [
                'source' => 'pixgo',
                'cajupay_payment_id' => self::PAYMENT_ID,
            ],
        ]);
    }

    private function creditSale(User $merchant, Order $order): void
    {
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
}
