<?php

namespace Tests\Feature;

use App\Mail\VerifyEmailMail;
use App\Models\Setting;
use App\Models\User;
use App\Support\RegistrationEmailVerificationSettings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class RegistrationEmailVerificationTest extends TestCase
{
    private function seedAdmin(): void
    {
        User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-verify@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function registrationPayload(string $email = 'verify-seller@example.com'): array
    {
        return [
            'person_type' => 'pf',
            'name' => 'Vendedor Verify',
            'email' => $email,
            'birth_date' => '1990-05-15',
            'document' => '52998224725',
            'company_name' => null,
            'legal_representative_cpf' => null,
            'address_zip' => '01310100',
            'address_street' => 'Av Paulista',
            'address_number' => '1000',
            'address_complement' => '',
            'address_neighborhood' => 'Bela Vista',
            'address_city' => 'São Paulo',
            'address_state' => 'SP',
            'monthly_revenue_range' => 'up_to_10k',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'accept_terms_privacy' => '1',
        ];
    }

    public function test_registration_without_setting_verifies_email_immediately(): void
    {
        Mail::fake();
        $this->seedAdmin();

        $this->post('/cadastro', $this->registrationPayload())
            ->assertRedirect('/financeiro?tab=seus-dados');

        $user = User::query()->where('email', 'verify-seller@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->email_verified_at);

        Mail::assertNotSent(VerifyEmailMail::class);
    }

    public function test_registration_with_setting_sends_verification_and_redirects_to_notice(): void
    {
        Mail::fake();
        $this->seedAdmin();
        Setting::set('registration_email_verification_enabled', '1', null);

        $this->post('/cadastro', $this->registrationPayload())
            ->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'verify-seller@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);

        Mail::assertSent(VerifyEmailMail::class, function (VerifyEmailMail $mail) use ($user) {
            return $mail->user->is($user) && $mail->verificationUrl !== '';
        });
    }

    public function test_unverified_seller_is_redirected_from_dashboard_when_setting_enabled(): void
    {
        Setting::set('registration_email_verification_enabled', '1', null);

        $seller = User::query()->create([
            'name' => 'Unverified',
            'email' => 'unverified@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'kyc_status' => User::KYC_NOT_SUBMITTED,
            'account_status' => 'pending',
            'email_verified_at' => null,
        ]);
        $seller->update(['tenant_id' => $seller->id]);

        $this->actingAs($seller)
            ->get('/dashboard')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_signed_verification_link_marks_email_verified(): void
    {
        Setting::set('registration_email_verification_enabled', '1', null);

        $seller = User::query()->create([
            'name' => 'Link Verify',
            'email' => 'link-verify@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'kyc_status' => User::KYC_NOT_SUBMITTED,
            'account_status' => 'pending',
            'email_verified_at' => null,
        ]);
        $seller->update(['tenant_id' => $seller->id]);

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $seller->id,
            'hash' => sha1($seller->getEmailForVerification()),
        ]);

        $this->get($url)->assertRedirect('/financeiro?tab=seus-dados');

        $this->assertNotNull($seller->fresh()->email_verified_at);
    }

    public function test_enabling_setting_grandfathers_existing_infoprodutors(): void
    {
        $seller = User::query()->create([
            'name' => 'Legacy',
            'email' => 'legacy-verify@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
            'email_verified_at' => null,
        ]);
        $seller->update(['tenant_id' => $seller->id]);

        RegistrationEmailVerificationSettings::grandfatherExistingInfoprodutors();

        $this->assertNotNull($seller->fresh()->email_verified_at);
    }

    public function test_resend_is_blocked_during_cooldown(): void
    {
        Mail::fake();
        Setting::set('registration_email_verification_enabled', '1', null);

        $seller = User::query()->create([
            'name' => 'Cooldown',
            'email' => 'cooldown@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'kyc_status' => User::KYC_NOT_SUBMITTED,
            'account_status' => 'pending',
            'email_verified_at' => null,
        ]);
        $seller->update(['tenant_id' => $seller->id]);

        \App\Support\EmailVerificationResendGuard::markResent($seller);

        $this->actingAs($seller)
            ->post(route('verification.resend'))
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_resend_shows_error_when_smtp_not_configured(): void
    {
        Setting::set('registration_email_verification_enabled', '1', null);

        $seller = User::query()->create([
            'name' => 'Resend Fail',
            'email' => 'resend-fail@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'kyc_status' => User::KYC_NOT_SUBMITTED,
            'account_status' => 'pending',
            'email_verified_at' => null,
        ]);
        $seller->update(['tenant_id' => $seller->id]);

        $this->actingAs($seller)
            ->post(route('verification.resend'))
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('error');
    }

    public function test_resend_succeeds_after_cooldown(): void
    {
        Mail::fake();
        Setting::set('registration_email_verification_enabled', '1', null);
        Setting::set('email_provider', 'smtp', null);
        Setting::set('smtp_host', 'smtp.plataforma.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('smtp_username', 'noreply@plataforma.com', null);
        Setting::set('smtp_password', encrypt('global-secret'), null);

        $seller = User::query()->create([
            'name' => 'Resend Ok',
            'email' => 'resend-ok@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'kyc_status' => User::KYC_NOT_SUBMITTED,
            'account_status' => 'pending',
            'email_verified_at' => null,
        ]);
        $seller->update(['tenant_id' => $seller->id]);

        $this->actingAs($seller)
            ->post(route('verification.resend'))
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('success');

        Mail::assertSent(VerifyEmailMail::class);
    }
}
