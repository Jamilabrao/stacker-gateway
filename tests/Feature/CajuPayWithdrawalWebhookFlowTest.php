<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use App\Services\WithdrawalAutoPayoutService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CajuPayWithdrawalWebhookFlowTest extends TestCase
{
    public function test_auto_cajupay_keeps_withdrawal_pending_until_webhook_confirmation(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('withdrawals/wallet_transactions tables');
        }

        Setting::set('platform_payout_gateway', 'cajupay', null);
        GatewayCredential::query()->whereIn('gateway_slug', ['spacepag', 'woovi', 'onlyup'])->delete();

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
            'webhook_signing_secret' => 'whsec_test',
            'cajupay_payout_min_brl' => '0',
            'cajupay_admin_fee_pix_brl' => '0',
            'cajupay_admin_fee_payout_brl' => '0',
        ]);
        $cred->save();

        Http::fake([
            'https://api.cajupay.com.br/*' => Http::response(['id' => 'payout-caju-1'], 200),
        ]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'payout_settings' => [
                'cajupay_pix_key' => 'seller@example.com',
                'cajupay_pix_key_type' => 'email',
                'cajupay_pix_key_owner_document' => '52998224725',
            ],
        ])->save();

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 150,
            'fee_amount' => 0,
            'net_amount' => 150,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
        ]);

        $result = app(WithdrawalAutoPayoutService::class)->attemptAutoPayout($withdrawal->fresh());

        $this->assertTrue($result['ok'] ?? false);
        $this->assertTrue($result['pending'] ?? false);

        $fresh = $withdrawal->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame('cajupay', $fresh->payout_provider);
        $this->assertSame('payout-caju-1', $fresh->payout_external_id);
        $this->assertSame(0, WalletTransaction::query()
            ->where('withdrawal_id', $fresh->id)
            ->where('type', WalletTransaction::TYPE_WITHDRAWAL_COMPLETE)
            ->count());
    }

    public function test_cajupay_payout_webhook_marks_paid_and_is_idempotent(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('withdrawals/wallet_transactions tables');
        }

        $signingSecret = 'caju_payout_whsec_123456';
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
            'webhook_signing_secret' => $signingSecret,
        ]);
        $cred->save();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 100,
            'fee_amount' => 0,
            'net_amount' => 100,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
            'payout_provider' => 'cajupay',
            'payout_external_id' => 'payout-caju-2',
        ]);

        $raw = json_encode([
            'id' => 'payout-caju-2',
            'type' => 'payout.completed',
            'status' => 'completed',
        ], JSON_THROW_ON_ERROR);

        $ts = time();
        $sig = hash_hmac('sha256', $ts.'.'.$raw, $signingSecret);

        $first = $this->call('POST', route('webhooks.cajupay.payout'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CAJUPAY_SIGNATURE' => 't='.$ts.',v1='.$sig,
            'HTTP_X_CAJUPAY_EVENT' => 'payout.completed',
        ], $raw);
        $first->assertOk();

        $this->assertSame('paid', $withdrawal->fresh()->status);
        $this->assertSame(1, WalletTransaction::query()
            ->where('withdrawal_id', $withdrawal->id)
            ->where('type', WalletTransaction::TYPE_WITHDRAWAL_COMPLETE)
            ->count());

        $second = $this->call('POST', route('webhooks.cajupay.payout'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CAJUPAY_SIGNATURE' => 't='.$ts.',v1='.$sig,
            'HTTP_X_CAJUPAY_EVENT' => 'payout.completed',
        ], $raw);
        $second->assertOk();

        $this->assertSame(1, WalletTransaction::query()
            ->where('withdrawal_id', $withdrawal->id)
            ->where('type', WalletTransaction::TYPE_WITHDRAWAL_COMPLETE)
            ->count());
    }

    public function test_cajupay_webhook_cancelled_status_refunds_wallet(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('withdrawals/tenant_wallets tables');
        }

        $signingSecret = 'caju_payout_whsec_cancel';
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
            'webhook_signing_secret' => $signingSecret,
        ]);
        $cred->save();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        TenantWallet::query()->updateOrCreate(
            ['tenant_id' => $seller->id],
            ['available_pix' => 0.0, 'available_balance' => 0.0]
        );

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 75,
            'fee_amount' => 0,
            'net_amount' => 75,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
            'payout_provider' => 'cajupay',
            'payout_external_id' => 'payout-caju-cancel',
        ]);

        $raw = json_encode([
            'id' => 'payout-caju-cancel',
            'type' => 'payout.updated',
            'status' => 'cancelled',
        ], JSON_THROW_ON_ERROR);

        $ts = time();
        $sig = hash_hmac('sha256', $ts.'.'.$raw, $signingSecret);

        $response = $this->call('POST', route('webhooks.cajupay.payout'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CAJUPAY_SIGNATURE' => 't='.$ts.',v1='.$sig,
            'HTTP_X_CAJUPAY_EVENT' => 'payout.updated',
        ], $raw);
        $response->assertOk();

        $withdrawal->refresh();
        $this->assertSame('failed', $withdrawal->status);
        $this->assertEqualsWithDelta(75.0, (float) TenantWallet::query()->where('tenant_id', $seller->id)->value('available_pix'), 0.01);
        $this->assertTrue(
            WalletTransaction::query()
                ->where('withdrawal_id', $withdrawal->id)
                ->where('type', WalletTransaction::TYPE_WITHDRAWAL_REFUND)
                ->exists()
        );
    }

    public function test_cajupay_webhook_failed_event_without_status_refunds_wallet(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('withdrawals/tenant_wallets tables');
        }

        $signingSecret = 'caju_payout_whsec_failed_evt';
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
            'webhook_signing_secret' => $signingSecret,
        ]);
        $cred->save();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        TenantWallet::query()->updateOrCreate(
            ['tenant_id' => $seller->id],
            ['available_pix' => 5.0]
        );

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 40,
            'fee_amount' => 0,
            'net_amount' => 40,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
            'payout_provider' => 'cajupay',
            'payout_external_id' => 'payout-caju-failed-evt',
        ]);

        $raw = json_encode([
            'id' => 'payout-caju-failed-evt',
            'type' => 'payout.failed',
        ], JSON_THROW_ON_ERROR);

        $ts = time();
        $sig = hash_hmac('sha256', $ts.'.'.$raw, $signingSecret);

        $response = $this->call('POST', route('webhooks.cajupay.payout'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CAJUPAY_SIGNATURE' => 't='.$ts.',v1='.$sig,
            'HTTP_X_CAJUPAY_EVENT' => 'payout.failed',
        ], $raw);
        $response->assertOk();

        $withdrawal->refresh();
        $this->assertSame('failed', $withdrawal->status);
        $this->assertEqualsWithDelta(45.0, (float) TenantWallet::query()->where('tenant_id', $seller->id)->value('available_pix'), 0.01);
    }

    public function test_paid_webhook_after_admin_reject_does_not_change_status(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('withdrawals/wallet_transactions tables');
        }

        $signingSecret = 'caju_payout_whsec_late_paid';
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
            'webhook_signing_secret' => $signingSecret,
        ]);
        $cred->save();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        TenantWallet::query()->updateOrCreate(
            ['tenant_id' => $seller->id],
            ['available_pix' => 0.0]
        );

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 60,
            'fee_amount' => 0,
            'net_amount' => 60,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
            'payout_provider' => 'cajupay',
            'payout_external_id' => 'payout-caju-late-paid',
        ]);

        MerchantWithdrawalService::reject($withdrawal->fresh(), 'Admin cancelou após envio');
        $this->assertSame('rejected', $withdrawal->fresh()->status);

        $raw = json_encode([
            'id' => 'payout-caju-late-paid',
            'type' => 'payout.completed',
            'status' => 'completed',
        ], JSON_THROW_ON_ERROR);

        $ts = time();
        $sig = hash_hmac('sha256', $ts.'.'.$raw, $signingSecret);

        $response = $this->call('POST', route('webhooks.cajupay.payout'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CAJUPAY_SIGNATURE' => 't='.$ts.',v1='.$sig,
            'HTTP_X_CAJUPAY_EVENT' => 'payout.completed',
        ], $raw);
        $response->assertOk();

        $this->assertSame('rejected', $withdrawal->fresh()->status);
        $this->assertSame(0, WalletTransaction::query()
            ->where('withdrawal_id', $withdrawal->id)
            ->where('type', WalletTransaction::TYPE_WITHDRAWAL_COMPLETE)
            ->count());
    }
}

