<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Platform\PlatformTotpService;
use App\Services\Withdrawal\WithdrawalPolicyService;
use Illuminate\Support\Facades\Crypt;
use Tests\Concerns\GeneratesTotpCodes;
use Tests\TestCase;

class PlatformMerchantTotpResetTest extends TestCase
{
    use GeneratesTotpCodes;

    private function enableTotpFor(User $user): string
    {
        $setup = PlatformTotpService::beginEnrollment($user->fresh());
        $code = $this->totpCodeForSecret($setup['secret']);
        $this->assertTrue(PlatformTotpService::confirmEnrollment($user->fresh(), $code));

        return $setup['secret'];
    }

    public function test_admin_with_totp_can_reset_merchant_totp(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
        $adminSecret = $this->enableTotpFor($admin);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $this->enableTotpFor($seller);

        $this->assertTrue(PlatformTotpService::isEnabledFor($seller->fresh()));
        $this->assertTrue(PlatformTotpService::requiresLoginChallenge($seller->fresh()));

        $response = $this->actingAs($admin)->post(route('plataforma.usuarios.reset-totp', $seller), [
            'totp_code' => $this->totpCodeForSecret($adminSecret),
        ]);

        $response->assertRedirect(route('plataforma.usuarios.edit', $seller));
        $response->assertSessionHas('success');

        $seller->refresh();
        $this->assertFalse(PlatformTotpService::isEnabledFor($seller));
        $this->assertFalse(PlatformTotpService::requiresLoginChallenge($seller));
        $this->assertNull($seller->totp_secret);
        $this->assertNull($seller->totp_enabled_at);
    }

    public function test_admin_with_pin_can_reset_merchant_totp_when_admin_has_no_totp(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
        WithdrawalPolicyService::setManualApprovalPin('246810');

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $this->enableTotpFor($seller);

        $response = $this->actingAs($admin)->post(route('plataforma.usuarios.reset-totp', $seller), [
            'manual_approval_pin' => '246810',
        ]);

        $response->assertRedirect(route('plataforma.usuarios.edit', $seller));
        $response->assertSessionHas('success');
        $this->assertFalse(PlatformTotpService::isEnabledFor($seller->fresh()));
    }

    public function test_reset_merchant_totp_is_blocked_without_admin_totp_or_pin(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $this->enableTotpFor($seller);

        $response = $this->actingAs($admin)->post(route('plataforma.usuarios.reset-totp', $seller));

        $response->assertSessionHasErrors('totp_code');
        $this->assertTrue(PlatformTotpService::isEnabledFor($seller->fresh()));
    }

    public function test_reset_merchant_totp_rejects_invalid_admin_totp(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
        $this->enableTotpFor($admin);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $this->enableTotpFor($seller);

        $response = $this->actingAs($admin)->post(route('plataforma.usuarios.reset-totp', $seller), [
            'totp_code' => '000000',
        ]);

        $response->assertSessionHasErrors('totp_code');
        $this->assertTrue(PlatformTotpService::isEnabledFor($seller->fresh()));
    }

    public function test_reset_does_nothing_useful_when_merchant_has_no_totp(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
        WithdrawalPolicyService::setManualApprovalPin('135790');

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $response = $this->actingAs($admin)->post(route('plataforma.usuarios.reset-totp', $seller), [
            'manual_approval_pin' => '135790',
        ]);

        $response->assertRedirect(route('plataforma.usuarios.edit', $seller));
        $response->assertSessionHas('error', 'Este infoprodutor não possui 2FA ativo.');
    }

    public function test_force_disable_clears_pending_enrollment_secret(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'totp_secret' => Crypt::encryptString('ABCDEFGHIJKLMNOP'),
            'totp_enabled_at' => null,
        ])->save();

        PlatformTotpService::forceDisable($seller->fresh());

        $seller->refresh();
        $this->assertNull($seller->totp_secret);
        $this->assertNull($seller->totp_enabled_at);
        $this->assertFalse(PlatformTotpService::requiresLoginChallenge($seller));
    }
}
