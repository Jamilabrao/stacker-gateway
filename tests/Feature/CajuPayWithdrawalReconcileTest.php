<?php

namespace Tests\Feature;

use App\Jobs\ReconcileCajuPayWithdrawalJob;
use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\WithdrawalAutoPayoutService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CajuPayWithdrawalReconcileTest extends TestCase
{
    private function seedCajuPayCredential(): void
    {
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
    }

    public function test_auto_cajupay_dispatches_reconcile_job(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $this->seedCajuPayCredential();
        Queue::fake();

        Http::fake([
            'https://api.cajupay.com.br/*' => Http::response(['id' => 'payout-reconcile-1'], 200),
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
            'amount' => 100,
            'fee_amount' => 0,
            'net_amount' => 100,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
        ]);

        app(WithdrawalAutoPayoutService::class)->attemptAutoPayout($withdrawal->fresh());

        Queue::assertPushed(ReconcileCajuPayWithdrawalJob::class, function (ReconcileCajuPayWithdrawalJob $job) use ($withdrawal) {
            return $job->withdrawalId === $withdrawal->id;
        });
    }

    public function test_reconcile_job_marks_paid_when_api_returns_completed(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('withdrawals/wallet_transactions tables');
        }

        $this->seedCajuPayCredential();

        Http::fake([
            'https://api.cajupay.com.br/api/payouts/payout-reconcile-2' => Http::response([
                'id' => 'payout-reconcile-2',
                'status' => 'completed',
            ], 200),
        ]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

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
            'payout_external_id' => 'payout-reconcile-2',
        ]);

        (new ReconcileCajuPayWithdrawalJob($withdrawal->id))->handle();

        $this->assertSame('paid', $withdrawal->fresh()->status);
        $this->assertSame(1, WalletTransaction::query()
            ->where('withdrawal_id', $withdrawal->id)
            ->where('type', WalletTransaction::TYPE_WITHDRAWAL_COMPLETE)
            ->count());
    }

    public function test_reconcile_job_is_idempotent(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('withdrawals/wallet_transactions tables');
        }

        $this->seedCajuPayCredential();

        Http::fake([
            'https://api.cajupay.com.br/api/payouts/payout-reconcile-3' => Http::response([
                'id' => 'payout-reconcile-3',
                'status' => 'paid',
            ], 200),
        ]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 50,
            'fee_amount' => 0,
            'net_amount' => 50,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
            'payout_provider' => 'cajupay',
            'payout_external_id' => 'payout-reconcile-3',
        ]);

        $job = new ReconcileCajuPayWithdrawalJob($withdrawal->id);
        $job->handle();
        $job->handle();

        $this->assertSame(1, WalletTransaction::query()
            ->where('withdrawal_id', $withdrawal->id)
            ->where('type', WalletTransaction::TYPE_WITHDRAWAL_COMPLETE)
            ->count());
    }

    public function test_reconcile_command_marks_single_withdrawal_paid(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('withdrawals/wallet_transactions tables');
        }

        $this->seedCajuPayCredential();

        Http::fake([
            'https://api.cajupay.com.br/api/payouts/payout-cmd-1' => Http::response([
                'id' => 'payout-cmd-1',
                'status' => 'completed',
            ], 200),
        ]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

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
            'payout_external_id' => 'payout-cmd-1',
        ]);

        Artisan::call('withdrawals:reconcile-cajupay', ['--withdrawal' => (string) $withdrawal->id]);

        $this->assertSame('paid', $withdrawal->fresh()->status);
    }

    public function test_reconcile_falls_back_to_list_when_get_by_id_returns_404(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $this->seedCajuPayCredential();

        Http::fake([
            'https://api.cajupay.com.br/api/payouts/payout-list-1' => Http::response([], 404),
            'https://api.cajupay.com.br/api/payouts*' => Http::response([
                'data' => [
                    ['id' => 'payout-list-1', 'status' => 'completed'],
                ],
            ], 200),
        ]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

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
            'payout_external_id' => 'payout-list-1',
        ]);

        (new ReconcileCajuPayWithdrawalJob($withdrawal->id))->handle();

        $this->assertSame('paid', $withdrawal->fresh()->status);
    }
}
