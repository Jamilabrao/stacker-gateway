<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Models\TenantWallet;
use App\Models\User;
use App\Services\MerchantWithdrawalService;
use App\Services\Withdrawal\WithdrawalMinimumService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PlatformWithdrawalMinimumTest extends TestCase
{
    public function test_platform_minimum_blocks_withdrawal_even_when_gateway_min_is_lower(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('wallet/withdrawal tables');
        }

        Setting::set('merchant_fee_rules', [
            'pix' => ['percent' => 0, 'fixed' => 0],
            'card' => ['percent' => 0, 'fixed' => 0],
            'boleto' => ['percent' => 0, 'fixed' => 0],
            'withdrawal' => ['percent' => 0, 'fixed' => 0],
        ], null);

        WithdrawalMinimumService::setPlatformMinimumBrl(50);

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'test-public',
            'secret_key' => 'test-secret',
            'cajupay_payout_min_brl' => '7',
            'cajupay_admin_fee_pix_brl' => '45.50',
            'cajupay_admin_fee_payout_brl' => '0',
        ]);
        $cred->save();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        TenantWallet::query()->firstOrCreate(
            ['tenant_id' => $seller->id],
            [
                'available_balance' => 0,
                'pending_balance' => 0,
                'currency' => 'BRL',
                'available_pix' => 100,
                'available_card' => 0,
                'available_boleto' => 0,
                'pending_pix' => 0,
                'pending_card' => 0,
                'pending_boleto' => 0,
            ]
        );

        try {
            $this->expectException(ValidationException::class);
            MerchantWithdrawalService::requestWithdrawal($seller->fresh(), 10.0, 'pix', null);
        } finally {
            $cred->delete();
            WithdrawalMinimumService::setPlatformMinimumBrl(0);
        }
    }

    public function test_gateway_admin_fees_do_not_inflate_seller_minimum(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('wallet/withdrawal tables');
        }

        Setting::set('merchant_fee_rules', [
            'pix' => ['percent' => 0, 'fixed' => 0],
            'card' => ['percent' => 0, 'fixed' => 0],
            'boleto' => ['percent' => 0, 'fixed' => 0],
            'withdrawal' => ['percent' => 0, 'fixed' => 0],
        ], null);

        WithdrawalMinimumService::setPlatformMinimumBrl(0);

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'test-public',
            'secret_key' => 'test-secret',
            'cajupay_payout_min_brl' => '7',
            'cajupay_admin_fee_pix_brl' => '45.50',
            'cajupay_admin_fee_payout_brl' => '0',
        ]);
        $cred->save();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        TenantWallet::query()->firstOrCreate(
            ['tenant_id' => $seller->id],
            [
                'available_balance' => 0,
                'pending_balance' => 0,
                'currency' => 'BRL',
                'available_pix' => 100,
                'available_card' => 0,
                'available_boleto' => 0,
                'pending_pix' => 0,
                'pending_card' => 0,
                'pending_boleto' => 0,
            ]
        );

        try {
            // 10 > payout_min 7; fees admin não devem bloquear.
            $withdrawal = MerchantWithdrawalService::requestWithdrawal($seller->fresh(), 10.0, 'pix', null);
            $this->assertSame(10.0, (float) $withdrawal->amount);
            $this->assertSame(10.0, (float) $withdrawal->net_amount);
        } finally {
            $cred->delete();
        }
    }
}
