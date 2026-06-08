<?php

namespace Tests\Unit;

use App\Models\TenantWallet;
use App\Models\User;
use App\Services\MerchantOperationalGuard;
use App\Services\MerchantWithdrawalService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MerchantOperationalGuardTest extends TestCase
{
    public function test_withdrawal_service_rejects_pending_review_merchant(): void
    {
        $seller = User::query()->create([
            'name' => 'Seller',
            'email' => 'guard-seller@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'kyc_status' => User::KYC_PENDING_REVIEW,
            'account_status' => 'pending',
        ]);
        $seller->update(['tenant_id' => $seller->id]);

        TenantWallet::query()->create([
            'tenant_id' => $seller->tenant_id,
            'available_pix' => 500,
            'available_card' => 0,
            'available_boleto' => 0,
            'pending_balance' => 0,
            'available_balance' => 500,
            'currency' => 'BRL',
        ]);

        $this->expectException(ValidationException::class);

        MerchantWithdrawalService::requestWithdrawal($seller, 10.0, 'pix');
    }

    public function test_tenant_not_operationally_approved_for_api(): void
    {
        $seller = User::query()->create([
            'name' => 'Seller',
            'email' => 'guard-api@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'kyc_status' => User::KYC_PENDING_REVIEW,
            'account_status' => 'pending',
        ]);
        $seller->update(['tenant_id' => $seller->id]);

        $this->assertFalse(MerchantOperationalGuard::tenantIsOperationallyApproved((int) $seller->tenant_id));
    }
}
