<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Platform\PlatformTotpService;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\GeneratesTotpCodes;
use Tests\TestCase;

class LoginTotpTest extends TestCase
{
    use GeneratesTotpCodes;

    private function createSeller(array $overrides = []): User
    {
        $seller = User::factory()->create(array_merge([
            'role' => User::ROLE_INFOPRODUTOR,
            'password' => Hash::make('password'),
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ], $overrides));
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }

    private function createPlatformAdmin(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
            'password' => Hash::make('password'),
        ], $overrides));
    }

    private function enableTotpFor(User $user): string
    {
        $setup = PlatformTotpService::beginEnrollment($user->fresh());
        $code = $this->totpCodeForSecret($setup['secret']);
        PlatformTotpService::confirmEnrollment($user->fresh(), $code);

        return $code;
    }

    public function test_seller_login_without_totp_uses_existing_flow(): void
    {
        $seller = $this->createSeller();

        $this->post('/login', [
            'email' => $seller->email,
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($seller);
    }

    public function test_seller_with_totp_redirects_to_challenge_without_session(): void
    {
        $seller = $this->createSeller();
        $this->enableTotpFor($seller);

        $this->post('/login', [
            'email' => $seller->email,
            'password' => 'password',
        ])->assertRedirect(route('login.two-factor'));

        $this->assertGuest();
    }

    public function test_seller_can_complete_login_after_totp_challenge(): void
    {
        $seller = $this->createSeller();
        $setup = PlatformTotpService::beginEnrollment($seller->fresh());
        PlatformTotpService::confirmEnrollment($seller->fresh(), $this->totpCodeForSecret($setup['secret']));

        $this->post('/login', [
            'email' => $seller->email,
            'password' => 'password',
        ])->assertRedirect(route('login.two-factor'));

        $code = $this->totpCodeForSecret($setup['secret']);

        $this->post(route('login.two-factor.verify'), [
            'totp_code' => $code,
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($seller->fresh());
    }

    public function test_seller_totp_challenge_rejects_invalid_code(): void
    {
        $seller = $this->createSeller();
        $this->enableTotpFor($seller);

        $this->post('/login', [
            'email' => $seller->email,
            'password' => 'password',
        ]);

        $this->post(route('login.two-factor.verify'), [
            'totp_code' => '000000',
        ])->assertSessionHasErrors('totp_code');

        $this->assertGuest();
    }

    public function test_platform_admin_with_totp_requires_challenge(): void
    {
        $admin = $this->createPlatformAdmin();
        $setup = PlatformTotpService::beginEnrollment($admin->fresh());
        PlatformTotpService::confirmEnrollment($admin->fresh(), $this->totpCodeForSecret($setup['secret']));

        $this->post('/plataforma/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('plataforma.login.two-factor'));

        $this->assertGuest();

        $this->post(route('plataforma.login.two-factor.verify'), [
            'totp_code' => $this->totpCodeForSecret($setup['secret']),
        ])->assertRedirect(route('plataforma.dashboard'));

        $this->assertAuthenticatedAs($admin->fresh());
    }

    public function test_seller_can_enable_totp_from_profile_routes(): void
    {
        $seller = $this->createSeller();
        $setup = PlatformTotpService::beginEnrollment($seller->fresh());
        $code = $this->totpCodeForSecret($setup['secret']);

        $this->actingAs($seller)
            ->post(route('security.totp.confirm'), ['totp_code' => $code])
            ->assertRedirect(route('profile.index'))
            ->assertSessionHas('success');

        $this->assertTrue(PlatformTotpService::isEnabledFor($seller->fresh()));
    }
}
