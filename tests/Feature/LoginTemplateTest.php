<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\LoginTemplate;
use Tests\TestCase;

class LoginTemplateTest extends TestCase
{
    public function test_platform_admin_can_read_and_save_login_template(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->getJson(route('plataforma.settings.login-template.data'))
            ->assertOk()
            ->assertJsonPath('template', 'default');

        $this->actingAs($admin)
            ->putJson(route('plataforma.settings.login-template.update'), [
                'template' => 'spotlight',
            ])
            ->assertOk()
            ->assertJsonPath('template', 'spotlight');

        $this->assertSame('spotlight', Setting::get(LoginTemplate::KEY, null, null));
        $this->assertSame('spotlight', LoginTemplate::current());
    }

    public function test_invalid_login_template_returns_422(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->putJson(route('plataforma.settings.login-template.update'), [
                'template' => 'invalid-theme',
            ])
            ->assertStatus(422);
    }

    public function test_login_page_includes_login_template_in_public_branding(): void
    {
        User::factory()->create();
        Setting::set(LoginTemplate::KEY, LoginTemplate::SPOTLIGHT, null);

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('public_branding.login_template', 'spotlight')
                ->where('public_branding.login_hero_tagline', 'Sua plataforma para vender mais.')
                ->where('public_branding.login_hero_subtagline', 'Feita para quem escala de verdade.')
            );
    }

    public function test_guest_sees_default_login_template_when_not_configured(): void
    {
        User::factory()->create();

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('public_branding.login_template', 'default')
            );
    }

    public function test_platform_admin_can_save_immersive_login_template(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->putJson(route('plataforma.settings.login-template.update'), [
                'template' => 'immersive',
            ])
            ->assertOk()
            ->assertJsonPath('template', 'immersive');

        $this->assertSame('immersive', LoginTemplate::current());
    }

    public function test_login_page_includes_immersive_template_in_public_branding(): void
    {
        User::factory()->create();
        Setting::set(LoginTemplate::KEY, LoginTemplate::IMMERSIVE, null);

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('public_branding.login_template', 'immersive')
            );
    }
}
