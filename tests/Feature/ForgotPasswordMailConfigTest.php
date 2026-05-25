<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\TenantMailConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForgotPasswordMailConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_for_password_reset_uses_tenant_smtp_not_env_default(): void
    {
        Setting::set('email_provider', 'smtp', 1);
        Setting::set('smtp_host', 'smtp.example.com', 1);
        Setting::set('smtp_port', '587', 1);
        Setting::set('smtp_encryption', 'tls', 1);
        Setting::set('smtp_username', 'user@example.com', 1);
        Setting::set('smtp_password', encrypt('secret'), 1);

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
            'email' => 'seller@example.com',
        ]);

        $service = app(TenantMailConfigService::class);
        $service->applyForPasswordReset($user);
        $service->assertSmtpHostIsConfigured();

        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
    }

    public function test_apply_for_password_reset_falls_back_to_platform_global_smtp(): void
    {
        Setting::set('email_provider', 'smtp', null);
        Setting::set('smtp_host', 'smtp.plataforma.com', null);
        Setting::set('smtp_port', '465', null);
        Setting::set('smtp_encryption', 'ssl', null);
        Setting::set('smtp_username', 'noreply@plataforma.com', null);
        Setting::set('smtp_password', encrypt('global-secret'), null);

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 99,
            'email' => 'seller2@example.com',
        ]);

        $service = app(TenantMailConfigService::class);
        $service->applyForPasswordReset($user);

        $this->assertSame('smtp.plataforma.com', config('mail.mailers.smtp.host'));
    }

    public function test_apply_for_password_reset_prefers_platform_global_over_tenant_smtp(): void
    {
        Setting::set('email_provider', 'smtp', 5);
        Setting::set('smtp_host', 'smtp.tenant-antigo.com', 5);
        Setting::set('smtp_port', '587', 5);
        Setting::set('smtp_encryption', 'tls', 5);
        Setting::set('smtp_username', 'old@tenant.com', 5);
        Setting::set('smtp_password', encrypt('old-secret'), 5);

        Setting::set('email_provider', 'smtp', null);
        Setting::set('smtp_host', 'smtp.plataforma.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('smtp_username', 'noreply@plataforma.com', null);
        Setting::set('smtp_password', encrypt('global-secret'), null);

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 5,
            'email' => 'seller@example.com',
        ]);

        $service = app(TenantMailConfigService::class);
        $service->applyForPasswordReset($user);

        $this->assertSame('smtp.plataforma.com', config('mail.mailers.smtp.host'));
    }

    public function test_assert_smtp_host_rejects_laravel_default_127_0_0_1_2525(): void
    {
        config(['mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 2525]);

        $this->expectException(\RuntimeException::class);
        app(TenantMailConfigService::class)->assertSmtpHostIsConfigured();
    }
}
