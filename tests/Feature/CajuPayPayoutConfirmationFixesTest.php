<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Jobs\ReconcileCajuPayWithdrawalJob;
use App\Models\CajuPayAccount;
use App\Models\GatewayCredential;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\Withdrawal\WithdrawalPolicyService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CajuPayPayoutConfirmationFixesTest extends TestCase
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

    public function test_payout_webhook_accepts_signing_secret_from_cajupay_account(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('cajupay_accounts')) {
            $this->markTestSkipped('withdrawals/cajupay_accounts');
        }

        GatewayCredential::query()->where('gateway_slug', 'cajupay')->delete();

        $signingSecret = 'account_only_whsec_abc';
        $account = CajuPayAccount::create([
            'name' => 'Conta webhook',
            'is_default' => true,
            'is_connected' => true,
            'is_enabled' => true,
        ]);
        $account->setEncryptedCredentials([
            'public_key' => 'pk_acc',
            'secret_key' => 'sk_acc',
            'checkout_webhook_signing_secret' => $signingSecret,
        ]);
        $account->save();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 90,
            'fee_amount' => 0,
            'net_amount' => 90,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
            'payout_provider' => 'cajupay',
            'payout_external_id' => 'payout-account-hmac-1',
        ]);

        $raw = json_encode([
            'type' => 'payout.paid',
            'data' => [
                'object' => [
                    'cajupay_payout_id' => 'payout-account-hmac-1',
                    'status' => 'paid',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $ts = time();
        $sig = hash_hmac('sha256', $ts.'.'.$raw, $signingSecret);

        $response = $this->call('POST', route('webhooks.cajupay.payout'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CAJUPAY_SIGNATURE' => 't='.$ts.',v1='.$sig,
            'HTTP_X_CAJUPAY_EVENT' => 'payout.paid',
        ], $raw);

        $response->assertOk();
        $this->assertSame('paid', $withdrawal->fresh()->status);
    }

    public function test_checkout_webhook_forwards_payout_paid_event(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals');
        }

        $signingSecret = 'checkout_forward_whsec';
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_fwd',
            'secret_key' => 'sk_fwd',
            'webhook_signing_secret' => $signingSecret,
        ]);
        $cred->save();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 55,
            'fee_amount' => 0,
            'net_amount' => 55,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
            'payout_provider' => 'cajupay',
            'payout_external_id' => 'payout-forward-1',
        ]);

        $raw = json_encode([
            'type' => 'payout.paid',
            'data' => [
                'object' => [
                    'cajupay_payout_id' => 'payout-forward-1',
                    'status' => 'paid',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $ts = time();
        $sig = hash_hmac('sha256', $ts.'.'.$raw, $signingSecret);

        // Delivered to checkout URL (misconfigured endpoint) — must still mark paid.
        $response = $this->call('POST', route('webhooks.cajupay'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CAJUPAY_SIGNATURE' => 't='.$ts.',v1='.$sig,
            'HTTP_X_CAJUPAY_EVENT' => 'payout.paid',
        ], $raw);

        $response->assertOk();
        $this->assertSame('paid', $withdrawal->fresh()->status);
    }

    public function test_reconcile_job_exhausted_unknown_status_does_not_refund(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('withdrawals/tenant_wallets');
        }

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_exh',
            'secret_key' => 'sk_exh',
        ]);
        $cred->save();

        Http::fake([
            'https://api.cajupay.com.br/*' => Http::response(['id' => 'payout-exh-1', 'status' => 'weird_unknown'], 200),
        ]);

        config(['queue.default' => 'redis']);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        TenantWallet::query()->updateOrCreate(
            ['tenant_id' => $seller->id],
            ['available_pix' => 0.0, 'available_balance' => 0.0]
        );

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 33,
            'fee_amount' => 0,
            'net_amount' => 33,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
            'payout_provider' => 'cajupay',
            'payout_external_id' => 'payout-exh-1',
        ]);

        $job = new class($withdrawal->id) extends ReconcileCajuPayWithdrawalJob
        {
            public function attempts(): int
            {
                return self::MAX_ATTEMPTS;
            }
        };

        $job->handle();

        $fresh = $withdrawal->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertTrue((bool) ($fresh->payout_meta['reconcile_exhausted'] ?? false));
        $this->assertSame(0.0, (float) TenantWallet::query()->where('tenant_id', $seller->id)->value('available_pix'));
    }

    public function test_admin_can_reconcile_awaiting_withdrawal(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals');
        }

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_adm',
            'secret_key' => 'sk_adm',
        ]);
        $cred->save();

        Http::fake([
            'https://api.cajupay.com.br/api/payouts/payout-admin-1' => Http::response([
                'id' => 'payout-admin-1',
                'status' => 'completed',
            ], 200),
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 70,
            'fee_amount' => 0,
            'net_amount' => 70,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
            'payout_provider' => 'cajupay',
            'payout_external_id' => 'payout-admin-1',
        ]);

        $response = $this->actingAs($admin)->post(
            route('plataforma.financeiro.saques.reconcile-cajupay', $withdrawal)
        );

        $response->assertRedirect(route('plataforma.saques.index'));
        $this->assertSame('paid', $withdrawal->fresh()->status);
    }

    public function test_admin_can_manually_confirm_awaiting_gateway_withdrawal(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('withdrawals/wallet_transactions');
        }

        WithdrawalPolicyService::setManualApprovalPin('1234');

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 44,
            'fee_amount' => 0,
            'net_amount' => 44,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
            'payout_provider' => 'cajupay',
            'payout_external_id' => 'payout-manual-confirm-1',
        ]);

        $response = $this->actingAs($admin)->post(
            route('plataforma.financeiro.saques.approve', $withdrawal),
            [
                'payout_manual' => true,
                'manual_confirm_external' => true,
                'manual_approval_pin' => '1234',
            ]
        );

        $response->assertRedirect(route('plataforma.saques.index'));
        $fresh = $withdrawal->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertTrue((bool) $fresh->payout_manual);
        $this->assertSame('cajupay', $fresh->payout_provider);
        $this->assertSame(1, WalletTransaction::query()
            ->where('withdrawal_id', $fresh->id)
            ->where('type', WalletTransaction::TYPE_WITHDRAWAL_COMPLETE)
            ->count());
    }
}
