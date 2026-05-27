<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Setting;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailSettingsTest extends TestCase
{
    public function test_platform_settings_persist_hostinger_email_provider(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);

        Setting::set('email_provider', 'smtp', null);
        Setting::set('smtp_host', 'smtp.old.example', null);

        $response = $this->actingAs($user)->put('/plataforma/configuracoes', [
            'email_provider' => 'hostinger',
            'hostinger_smtp_username' => 'contato@meudominio.com',
            'hostinger_mail_from_name' => 'Minha Loja',
            'kyc_notification_emails' => '',
        ]);

        $response->assertRedirect();
        $this->assertSame('hostinger', Setting::get('email_provider', 'smtp', null));
        $this->assertSame('contato@meudominio.com', Setting::get('hostinger_smtp_username', '', null));
        $this->assertSame('contato@meudominio.com', Setting::get('hostinger_mail_from_address', '', null));

        $page = $this->actingAs($user)->get('/plataforma/configuracoes?tab=email');
        $page->assertOk();
        $page->assertInertia(fn ($assert) => $assert
            ->component('Settings/Index')
            ->where('settings.email_provider', 'hostinger'));
    }

    public function test_email_test_endpoint_returns_success()
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // Prepare some settings (global)
        Setting::set('smtp_host', 'smtp.example.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_username', 'user', null);
        Setting::set('smtp_password', encrypt('secret'), null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('mail_from_address', 'noreply@example.com', null);
        Setting::set('mail_from_name', 'Example', null);

        Mail::fake();

        $response = $this->actingAs($user)->postJson('/plataforma/configuracoes/email/test', [
            'test_to' => 'test@example.com',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_email_send_test_endpoint_returns_success()
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);

        Setting::set('smtp_host', 'smtp.example.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_username', 'user', null);
        Setting::set('smtp_password', encrypt('secret'), null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('mail_from_address', 'noreply@example.com', null);
        Setting::set('mail_from_name', 'Example', null);

        Mail::fake();

        $response = $this->actingAs($user)->postJson('/plataforma/configuracoes/email/send-test', [
            'test_to' => 'test@example.com',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
    }
}

