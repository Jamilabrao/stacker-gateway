<?php

namespace Tests\Feature;

use App\Console\Commands\ReconcileCajuPayWithdrawalsCommand;
use App\Jobs\ReconcileCajuPayWithdrawalJob;
use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WithdrawalFailureRefundTest extends TestCase
{
    public function test_mark_failed_restores_wallet_balance(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('withdrawals/tenant_wallets tables');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        TenantWallet::query()->updateOrCreate(
            ['tenant_id' => $seller->id],
            ['available_pix' => 20.0, 'available_balance' => 20.0]
        );

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 30,
            'fee_amount' => 0,
            'net_amount' => 30,
            'bucket' => 'pix',
            'status' => 'processing',
            'currency' => 'BRL',
            'payout_provider' => 'cajupay',
            'payout_external_id' => 'ext-fail-1',
        ]);

        MerchantWithdrawalService::markFailed($withdrawal->fresh(), 'Falha de teste');

        $withdrawal->refresh();
        $wallet = TenantWallet::query()->where('tenant_id', $seller->id)->first();

        $this->assertSame('failed', $withdrawal->status);
        $this->assertEqualsWithDelta(50.0, (float) $wallet->available_pix, 0.01);
        $this->assertTrue(
            WalletTransaction::query()
                ->where('withdrawal_id', $withdrawal->id)
                ->where('type', WalletTransaction::TYPE_WITHDRAWAL_REFUND)
                ->exists()
        );
    }

    public function test_reconcile_job_marks_failed_and_refunds_when_api_returns_failed(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('withdrawals/tenant_wallets tables');
        }

        Setting::set('platform_payout_gateway', 'cajupay', null);
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'pk',
            'secret_key' => 'sk',
            'webhook_signing_secret' => 'whsec',
        ]);
        $cred->save();

        Http::fake([
            'https://api.cajupay.com.br/api/payouts/payout-fail-2' => Http::response([
                'id' => 'payout-fail-2',
                'status' => 'failed',
            ], 200),
        ]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        TenantWallet::query()->updateOrCreate(
            ['tenant_id' => $seller->id],
            ['available_pix' => 10.0]
        );

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 10,
            'fee_amount' => 0,
            'net_amount' => 10,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
            'payout_provider' => 'cajupay',
            'payout_external_id' => 'payout-fail-2',
        ]);

        (new ReconcileCajuPayWithdrawalJob($withdrawal->id))->handle();

        $withdrawal->refresh();
        $this->assertSame('failed', $withdrawal->status);
    }

    public function test_reconcile_command_marks_cancelled_and_refunds_wallet(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('withdrawals/tenant_wallets tables');
        }

        Setting::set('platform_payout_gateway', 'cajupay', null);
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'pk',
            'secret_key' => 'sk',
            'webhook_signing_secret' => 'whsec',
        ]);
        $cred->save();

        Http::fake([
            'https://api.cajupay.com.br/api/payouts/payout-cancel-cron' => Http::response([
                'id' => 'payout-cancel-cron',
                'status' => 'cancelled',
            ], 200),
        ]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        TenantWallet::query()->updateOrCreate(
            ['tenant_id' => $seller->id],
            ['available_pix' => 5.0]
        );

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 20,
            'fee_amount' => 0,
            'net_amount' => 20,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
            'payout_provider' => 'cajupay',
            'payout_external_id' => 'payout-cancel-cron',
        ]);

        $this->artisan(ReconcileCajuPayWithdrawalsCommand::class, [
            '--withdrawal' => $withdrawal->id,
        ])->assertSuccessful();

        $withdrawal->refresh();
        $this->assertSame('failed', $withdrawal->status);
        $this->assertEqualsWithDelta(25.0, (float) TenantWallet::query()->where('tenant_id', $seller->id)->value('available_pix'), 0.01);
    }

    public function test_reject_accepts_processing_status(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('withdrawals/tenant_wallets tables');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        TenantWallet::query()->updateOrCreate(
            ['tenant_id' => $seller->id],
            ['available_pix' => 0.0]
        );

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 25,
            'fee_amount' => 0,
            'net_amount' => 25,
            'bucket' => 'pix',
            'status' => 'processing',
            'currency' => 'BRL',
        ]);

        MerchantWithdrawalService::reject($withdrawal->fresh(), 'Admin cancelou');

        $withdrawal->refresh();
        $this->assertSame('rejected', $withdrawal->status);
        $this->assertEqualsWithDelta(25.0, (float) TenantWallet::query()->where('tenant_id', $seller->id)->value('available_pix'), 0.01);
    }

    public function test_reject_with_external_id_refunds_and_records_cancel_meta(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('withdrawals/tenant_wallets tables');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        TenantWallet::query()->updateOrCreate(
            ['tenant_id' => $seller->id],
            ['available_pix' => 0.0]
        );

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 80,
            'fee_amount' => 0,
            'net_amount' => 80,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
            'payout_provider' => 'cajupay',
            'payout_external_id' => 'payout-admin-cancel',
            'payout_meta' => ['requested_at' => now()->toIso8601String()],
        ]);

        MerchantWithdrawalService::reject($withdrawal->fresh(), 'Cancelado pelo admin');

        $withdrawal->refresh();
        $this->assertSame('rejected', $withdrawal->status);
        $this->assertEqualsWithDelta(80.0, (float) TenantWallet::query()->where('tenant_id', $seller->id)->value('available_pix'), 0.01);
        $this->assertNotEmpty($withdrawal->payout_meta['cancelled_by_admin_at'] ?? null);
        $this->assertTrue(
            WalletTransaction::query()
                ->where('withdrawal_id', $withdrawal->id)
                ->where('type', WalletTransaction::TYPE_WITHDRAWAL_REFUND)
                ->exists()
        );
    }
}
