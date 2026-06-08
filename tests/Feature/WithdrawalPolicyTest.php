<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Withdrawal\WithdrawalPolicyService;
use App\Services\WithdrawalAutoPayoutService;
use App\Models\Withdrawal;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WithdrawalPolicyTest extends TestCase
{
    public function test_auto_withdrawal_disabled_skips_auto_payout(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        Setting::set('platform_auto_withdrawal_enabled', false, null);

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
        ]);

        $result = app(WithdrawalAutoPayoutService::class)->attemptAutoPayout($withdrawal);

        $this->assertFalse($result['ok'] ?? true);
        $this->assertSame('auto_withdrawal_disabled', $result['reason'] ?? null);
    }

    public function test_withdrawal_hours_block_outside_window(): void
    {
        Setting::set('platform_withdrawal_hours_enabled', true, null);
        Setting::set('platform_withdrawal_hours_start', '06:00', null);
        Setting::set('platform_withdrawal_hours_end', '21:00', null);
        Setting::set('platform_withdrawal_timezone', 'America/Sao_Paulo', null);

        $blocked = Carbon::parse('2026-06-09 22:30:00', 'America/Sao_Paulo');
        $allowed = Carbon::parse('2026-06-09 10:00:00', 'America/Sao_Paulo');

        $this->assertFalse(WithdrawalPolicyService::allowsRequestAt($blocked));
        $this->assertTrue(WithdrawalPolicyService::allowsRequestAt($allowed));
    }

    public function test_platform_admin_can_update_withdrawal_policy(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $response = $this->actingAs($admin)->put(route('plataforma.financeiro.saques-politica.update'), [
            'auto_withdrawal_enabled' => false,
            'hours_enabled' => true,
            'hours_start' => '07:00',
            'hours_end' => '20:00',
            'timezone' => 'America/Sao_Paulo',
            'manual_approval_pin' => '1234',
            'manual_approval_pin_confirmation' => '1234',
        ]);

        $response->assertRedirect(route('plataforma.financeiro.index', ['tab' => 'saques']));
        $this->assertFalse(WithdrawalPolicyService::autoWithdrawalEnabled());
        $this->assertTrue(WithdrawalPolicyService::verifyManualApprovalPin('1234'));
    }
}
