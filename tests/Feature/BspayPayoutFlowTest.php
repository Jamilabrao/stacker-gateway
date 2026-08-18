<?php

namespace Tests\Feature;

use App\Jobs\ReconcileBspayWithdrawalJob;
use App\Models\GatewayCredential;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\WithdrawalAutoPayoutService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BspayPayoutFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_auto_bspay_persists_pending_and_dispatches_reconcile_job(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        GatewayCredential::query()->whereIn('gateway_slug', ['cajupay', 'spacepag', 'woovi', 'onlyup'])->delete();

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'bspay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'client_id' => 'id',
            'client_secret' => 'secret',
        ]);
        $cred->save();

        Http::fake([
            'https://api.bspay.co/v2/oauth/token' => Http::response([
                'access_token' => 'jwt-token',
                'expires_in' => 3600,
            ], 200),
            'https://api.bspay.co/v2/transactions/cashout' => Http::response([
                'transaction_id' => 'bspay-cashout-9',
                'status' => 'pending',
            ], 200),
        ]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'payout_settings' => [
                'payout_pix_key' => 'destino@pix.com',
                'payout_pix_key_type' => 'email',
            ],
        ])->save();

        $w = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 100,
            'fee_amount' => 0,
            'net_amount' => 100,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
        ]);

        $auto = app(WithdrawalAutoPayoutService::class)->attemptAutoPayout($w->fresh());

        $this->assertTrue($auto['ok'] ?? false);
        $this->assertTrue($auto['pending'] ?? false);

        $fresh = $w->fresh();
        $this->assertSame('bspay', $fresh->payout_provider);
        $this->assertSame('bspay-cashout-9', $fresh->payout_external_id);
        $this->assertSame('pending', $fresh->status);

        Queue::assertPushed(ReconcileBspayWithdrawalJob::class);

        $cred->delete();
    }

    public function test_auto_bspay_transfer_value_includes_admin_fee_payout_brl(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        GatewayCredential::query()->whereIn('gateway_slug', ['cajupay', 'spacepag', 'woovi', 'onlyup'])->delete();

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'bspay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'client_id' => 'id',
            'client_secret' => 'secret',
            'bspay_admin_fee_payout_brl' => '2',
        ]);
        $cred->save();

        Http::fake([
            'https://api.bspay.co/v2/oauth/token' => Http::response([
                'access_token' => 'jwt-token',
                'expires_in' => 3600,
            ], 200),
            'https://api.bspay.co/v2/transactions/cashout' => Http::response([
                'transaction_id' => 'bspay-cashout-fee',
                'status' => 'pending',
            ], 200),
        ]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'payout_settings' => [
                'payout_pix_key' => 'destino@pix.com',
                'payout_pix_key_type' => 'email',
            ],
        ])->save();

        $w = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 20,
            'fee_amount' => 4,
            'net_amount' => 16,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
        ]);

        $auto = app(WithdrawalAutoPayoutService::class)->attemptAutoPayout($w->fresh());

        $this->assertTrue($auto['ok'] ?? false);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/v2/transactions/cashout')) {
                return false;
            }
            $data = $request->data();
            if (! is_array($data)) {
                return false;
            }

            return ($data['amount'] ?? null) === 18.0;
        });

        $cred->delete();
    }

    public function test_webhook_cashout_confirmed_marks_withdrawal_paid(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $w = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 100,
            'fee_amount' => 0,
            'net_amount' => 100,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
            'payout_provider' => 'bspay',
            'payout_external_id' => 'bspay-cashout-wh',
        ]);

        $response = $this->postSignedBspayWebhook([
            'event' => 'cashout.confirmed',
            'transaction_id' => 'bspay-cashout-wh',
            'data' => [
                'transaction_id' => 'bspay-cashout-wh',
                'status' => 'confirmed',
            ],
        ], 'unused', null, 'cashout.confirmed');

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertSame('paid', $w->fresh()->status);
    }
}
