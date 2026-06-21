<?php

namespace Tests\Feature;

use App\Mail\TestEmail;
use App\Mail\VerifyEmailMail;
use App\Models\Setting;
use App\Models\User;
use App\Services\PlatformEmailNotifications;
use App\Services\PlatformTransactionalMailService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PlatformTransactionalMailServiceTest extends TestCase
{
    public function test_send_uses_platform_global_smtp_not_first_tenant(): void
    {
        Setting::set('email_provider', 'smtp', 1);
        Setting::set('smtp_host', 'smtp.tenant-errado.com', 1);
        Setting::set('smtp_port', '587', 1);
        Setting::set('smtp_encryption', 'tls', 1);
        Setting::set('smtp_username', 'wrong@tenant.com', 1);
        Setting::set('smtp_password', encrypt('tenant-secret'), 1);

        Setting::set('email_provider', 'smtp', null);
        Setting::set('smtp_host', 'smtp.plataforma.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('smtp_username', 'noreply@plataforma.com', null);
        Setting::set('smtp_password', encrypt('global-secret'), null);

        Mail::fake();

        $sent = app(PlatformTransactionalMailService::class)->send(
            new TestEmail('Assunto', '<p>Corpo</p>'),
            'dest@example.com'
        );

        $this->assertTrue($sent);
        $this->assertSame('smtp.plataforma.com', config('mail.mailers.smtp.host'));
        Mail::assertSent(TestEmail::class);
    }

    public function test_send_returns_false_when_platform_smtp_not_configured(): void
    {
        Setting::set('email_provider', 'smtp', 1);
        Setting::set('smtp_host', 'smtp.tenant.com', 1);
        Setting::set('smtp_port', '587', 1);
        Setting::set('smtp_encryption', 'tls', 1);
        Setting::set('smtp_username', 'user@tenant.com', 1);
        Setting::set('smtp_password', encrypt('secret'), 1);

        Mail::fake();

        $seller = User::query()->create([
            'name' => 'No Global SMTP',
            'email' => 'no-global@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);
        $seller->update(['tenant_id' => $seller->id]);

        $sent = app(PlatformEmailNotifications::class)->sendEmailVerification($seller);

        $this->assertFalse($sent);
        Mail::assertNotSent(VerifyEmailMail::class);
    }
}
