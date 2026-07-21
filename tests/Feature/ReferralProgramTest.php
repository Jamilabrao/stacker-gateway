<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\Order;
use App\Models\ReferralCommission;
use App\Models\ReferralWallet;
use App\Models\ReferralWithdrawal;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\PlatformOrderAdminService;
use App\Services\ReferralAttributionService;
use App\Services\ReferralCommissionRecorder;
use App\Services\ReferralWithdrawalService;
use App\Support\ReferralProgramSettings;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReferralProgramTest extends TestCase
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

    private function enableProgram(array $overrides = []): void
    {
        ReferralProgramSettings::persistFromValidated(array_merge([
            'enabled' => true,
            'commission_percent' => 20,
            'eligibility_days' => 365,
            'rules_html' => 'Regras de teste',
            'min_withdrawal' => 10,
            'cookie_days' => 30,
        ], $overrides));
    }

    private function createSeller(array $attrs = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
            'email_verified_at' => now(),
        ], $attrs));
        $user->forceFill(['tenant_id' => $user->id])->save();

        return $user->fresh();
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
            'email' => 'buyer-ref@test.com',
        ]);
    }

    public function test_disabled_program_hides_seller_page(): void
    {
        Setting::set(ReferralProgramSettings::KEY_ENABLED, '0', null);
        $seller = $this->createSeller();

        $this->actingAs($seller)
            ->get('/indique-e-ganhe')
            ->assertRedirect('/dashboard');
    }

    public function test_enabled_program_shows_seller_page_and_generates_code(): void
    {
        if (! Schema::hasColumn('users', 'referral_code')) {
            $this->markTestSkipped('referral columns missing');
        }

        $this->enableProgram();
        $seller = $this->createSeller();

        $this->actingAs($seller)
            ->get('/indique-e-ganhe')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('IndiqueGanhe/Index')
                ->where('program.enabled', true)
                ->has('referral.code')
                ->has('referral.link'));

        $seller->refresh();
        $this->assertNotEmpty($seller->referral_code);
    }

    public function test_attach_on_registration_with_valid_ref(): void
    {
        if (! Schema::hasColumn('users', 'referred_by_user_id')) {
            $this->markTestSkipped('referral columns missing');
        }

        $this->enableProgram();
        $referrer = $this->createSeller();
        $code = ReferralCommissionRecorder::ensureReferralCode($referrer);

        $new = $this->createSeller(['email' => 'novo-indicado@test.com']);
        $ok = ReferralAttributionService::attachOnRegistration($new, $code);

        $this->assertTrue($ok);
        $new->refresh();
        $this->assertSame($referrer->id, (int) $new->referred_by_user_id);
        $this->assertNotNull($new->referred_at);
    }

    public function test_self_referral_is_rejected(): void
    {
        $this->enableProgram();
        $seller = $this->createSeller();
        $code = ReferralCommissionRecorder::ensureReferralCode($seller);

        $ok = ReferralAttributionService::attachOnRegistration($seller, $code);
        $this->assertFalse($ok);
        $this->assertNull($seller->fresh()->referred_by_user_id);
    }

    public function test_commission_credits_percent_of_platform_fee_idempotently(): void
    {
        if (! Schema::hasTable('referral_commissions')) {
            $this->markTestSkipped('referral tables missing');
        }

        $this->enableProgram(['commission_percent' => 20]);
        $referrer = $this->createSeller();
        ReferralCommissionRecorder::ensureReferralCode($referrer);

        $referred = $this->createSeller(['email' => 'indicado-venda@test.com']);
        $referred->forceFill([
            'referred_by_user_id' => $referrer->id,
            'referred_at' => now()->subDay(),
        ])->save();

        $order = $this->createCompletedOrder($referred, 100.0);
        WalletTransaction::create([
            'tenant_id' => $referred->id,
            'order_id' => $order->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE,
            'amount_gross' => 100.00,
            'amount_fee' => 5.00,
            'amount_net' => 95.00,
        ]);

        $first = ReferralCommissionRecorder::recordForOrder($order);
        $second = ReferralCommissionRecorder::recordForOrder($order);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertEquals(1.00, (float) $first->amount); // 20% of 5.00
        $this->assertSame(1, ReferralCommission::query()->where('order_id', $order->id)->count());

        $wallet = ReferralWallet::query()->where('user_id', $referrer->id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(1.00, (float) $wallet->available_balance);
        $this->assertEquals(1.00, (float) $wallet->lifetime_earned);
    }

    public function test_commission_skipped_outside_eligibility_window(): void
    {
        if (! Schema::hasTable('referral_commissions')) {
            $this->markTestSkipped('referral tables missing');
        }

        $this->enableProgram(['eligibility_days' => 30, 'commission_percent' => 50]);
        $referrer = $this->createSeller();
        $referred = $this->createSeller(['email' => 'fora-janela@test.com']);
        $referred->forceFill([
            'referred_by_user_id' => $referrer->id,
            'referred_at' => now()->subDays(60),
        ])->save();

        $order = $this->createCompletedOrder($referred, 100.0);
        WalletTransaction::create([
            'tenant_id' => $referred->id,
            'order_id' => $order->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE,
            'amount_gross' => 100.00,
            'amount_fee' => 10.00,
            'amount_net' => 90.00,
        ]);

        $this->assertNull(ReferralCommissionRecorder::recordForOrder($order));
        $this->assertSame(0, ReferralCommission::query()->count());
    }

    public function test_refund_reverses_referral_commission(): void
    {
        if (! Schema::hasTable('referral_commissions') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('tables missing');
        }

        $this->enableProgram(['commission_percent' => 20]);
        $referrer = $this->createSeller();
        $referred = $this->createSeller(['email' => 'refund-ref@test.com']);
        $referred->forceFill([
            'referred_by_user_id' => $referrer->id,
            'referred_at' => now(),
        ])->save();

        $order = $this->createCompletedOrder($referred, 100.0);
        WalletTransaction::create([
            'tenant_id' => $referred->id,
            'order_id' => $order->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE,
            'amount_gross' => 100.00,
            'amount_fee' => 5.00,
            'amount_net' => 95.00,
        ]);

        ReferralCommissionRecorder::recordForOrder($order);
        $this->assertEquals(1.0, (float) ReferralWallet::forUser($referrer->id)->available_balance);

        PlatformOrderAdminService::refundPaidOrDisputed($order->fresh());

        $commission = ReferralCommission::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($commission);
        $this->assertSame(ReferralCommission::STATUS_REVERSED, $commission->status);
        $this->assertEquals(0.0, (float) ReferralWallet::forUser($referrer->id)->fresh()->available_balance);
    }

    public function test_withdrawal_respects_minimum_and_balance(): void
    {
        if (! Schema::hasTable('referral_withdrawals')) {
            $this->markTestSkipped('referral withdrawals missing');
        }

        $this->enableProgram(['min_withdrawal' => 50]);
        $seller = $this->createSeller([
            'payout_settings' => [
                'payout_pix_key' => '11999999999',
                'payout_pix_key_type' => 'phone',
            ],
        ]);

        $wallet = ReferralWallet::forUser($seller->id);
        $wallet->forceFill(['available_balance' => 80])->save();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        ReferralWithdrawalService::request($seller, 10);
    }

    public function test_withdrawal_request_and_admin_reject_refunds_balance(): void
    {
        if (! Schema::hasTable('referral_withdrawals')) {
            $this->markTestSkipped('referral withdrawals missing');
        }

        $this->enableProgram(['min_withdrawal' => 10]);
        $seller = $this->createSeller([
            'payout_settings' => [
                'payout_pix_key' => '11988887766',
                'payout_pix_key_type' => 'phone',
            ],
        ]);
        ReferralWallet::forUser($seller->id)->forceFill(['available_balance' => 40])->save();

        $withdrawal = ReferralWithdrawalService::request($seller, 15);
        $this->assertSame(ReferralWithdrawal::STATUS_PENDING, $withdrawal->status);
        $this->assertEquals(25.0, (float) ReferralWallet::forUser($seller->id)->fresh()->available_balance);

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'tenant_id' => null,
        ]);

        ReferralWithdrawalService::reject($withdrawal, $admin, 'Documento pendente');
        $this->assertSame(ReferralWithdrawal::STATUS_REJECTED, $withdrawal->fresh()->status);
        $this->assertEquals(40.0, (float) ReferralWallet::forUser($seller->id)->fresh()->available_balance);
    }

    public function test_admin_can_assign_referrer(): void
    {
        if (! Schema::hasColumn('users', 'referred_by_user_id')) {
            $this->markTestSkipped('referral columns missing');
        }

        $this->enableProgram();
        $referrer = $this->createSeller();
        $referred = $this->createSeller(['email' => 'assign-me@test.com']);

        ReferralAttributionService::assignReferrer($referred, $referrer->id);
        $referred->refresh();
        $this->assertSame($referrer->id, (int) $referred->referred_by_user_id);

        ReferralAttributionService::assignReferrer($referred, null);
        $this->assertNull($referred->fresh()->referred_by_user_id);
    }

    public function test_disabled_program_does_not_credit(): void
    {
        Setting::set(ReferralProgramSettings::KEY_ENABLED, '0', null);
        $referrer = $this->createSeller();
        $referred = $this->createSeller(['email' => 'off@test.com']);
        $referred->forceFill([
            'referred_by_user_id' => $referrer->id,
            'referred_at' => now(),
        ])->save();

        $order = $this->createCompletedOrder($referred, 100.0);
        WalletTransaction::create([
            'tenant_id' => $referred->id,
            'order_id' => $order->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE,
            'amount_gross' => 100.00,
            'amount_fee' => 5.00,
            'amount_net' => 95.00,
        ]);

        $this->assertNull(ReferralCommissionRecorder::recordForOrder($order));
    }

    public function test_referrer_custom_commission_percent_overrides_global(): void
    {
        if (! Schema::hasTable('referral_commissions') || ! Schema::hasColumn('users', 'referral_commission_percent')) {
            $this->markTestSkipped('referral override column missing');
        }

        $this->enableProgram(['commission_percent' => 20]);
        $referrer = $this->createSeller([
            'referral_commission_percent' => 50,
        ]);
        $referred = $this->createSeller(['email' => 'custom-rate@test.com']);
        $referred->forceFill([
            'referred_by_user_id' => $referrer->id,
            'referred_at' => now(),
        ])->save();

        $order = $this->createCompletedOrder($referred, 100.0);
        WalletTransaction::create([
            'tenant_id' => $referred->id,
            'order_id' => $order->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE,
            'amount_gross' => 100.00,
            'amount_fee' => 10.00,
            'amount_net' => 90.00,
        ]);

        $commission = ReferralCommissionRecorder::recordForOrder($order);
        $this->assertNotNull($commission);
        $this->assertEquals(5.00, (float) $commission->amount); // 50% of 10
        $this->assertEquals(50.0, (float) $commission->commission_percent);
    }
}
