<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlatformDashboardWithdrawalsKpiTest extends TestCase
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

    public function test_platform_dashboard_retiradas_uses_paid_status(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals');
        }

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
        $merchant = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();

        Withdrawal::query()->create([
            'tenant_id' => $merchant->id,
            'user_id' => $merchant->id,
            'amount' => 100.0,
            'fee_amount' => 5.0,
            'net_amount' => 95.0,
            'bucket' => 'pix',
            'status' => MerchantWithdrawalService::STATUS_PAID,
            'currency' => 'BRL',
        ]);

        Withdrawal::query()->create([
            'tenant_id' => $merchant->id,
            'user_id' => $merchant->id,
            'amount' => 40.0,
            'fee_amount' => 0,
            'net_amount' => 40.0,
            'bucket' => 'pix',
            'status' => MerchantWithdrawalService::STATUS_PENDING,
            'currency' => 'BRL',
        ]);

        $response = $this->actingAs($admin)->get('/plataforma/dashboard?period=total');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Platform/Dashboard')
            ->where('kpis.withdrawals_total', 95)
            ->where('kpis.withdrawals_pending', 40)
        );
    }
}
