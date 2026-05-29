<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\PanelColorScheme;
use Tests\TestCase;

class PanelColorSchemeTest extends TestCase
{
    public function test_platform_admin_can_read_and_save_panel_color_scheme(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->getJson(route('plataforma.settings.panel-color-scheme.data'))
            ->assertOk()
            ->assertJsonPath('mode', 'dark')
            ->assertJsonPath('locked', false);

        $this->actingAs($admin)
            ->putJson(route('plataforma.settings.panel-color-scheme.update'), [
                'mode' => 'system',
                'locked' => false,
            ])
            ->assertOk()
            ->assertJsonPath('mode', 'system')
            ->assertJsonPath('locked', false);

        $stored = Setting::get(PanelColorScheme::KEY, null, null);
        $this->assertIsString($stored);
        $decoded = json_decode((string) $stored, true);
        $this->assertSame('system', $decoded['mode'] ?? null);
        $this->assertFalse($decoded['locked'] ?? true);
        $this->assertSame('system', PanelColorScheme::current()['mode']);
    }

    public function test_platform_admin_can_save_forced_and_fixed_schemes(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->putJson(route('plataforma.settings.panel-color-scheme.update'), [
                'mode' => 'dark',
                'locked' => false,
            ])
            ->assertOk()
            ->assertJsonPath('mode', 'dark')
            ->assertJsonPath('locked', false);

        $this->actingAs($admin)
            ->putJson(route('plataforma.settings.panel-color-scheme.update'), [
                'mode' => 'light',
                'locked' => true,
            ])
            ->assertOk()
            ->assertJsonPath('mode', 'light')
            ->assertJsonPath('locked', true);

        $this->assertSame([
            'mode' => 'light',
            'locked' => true,
        ], PanelColorScheme::current());
    }

    public function test_invalid_mode_returns_422(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->putJson(route('plataforma.settings.panel-color-scheme.update'), [
                'mode' => 'neon',
                'locked' => false,
            ])
            ->assertStatus(422);
    }

    public function test_guest_page_receives_panel_color_scheme_prop(): void
    {
        Setting::set(PanelColorScheme::KEY, [
            'mode' => 'light',
            'locked' => true,
        ], null);

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('public_branding.panel_color_scheme.mode', 'light')
                ->where('public_branding.panel_color_scheme.locked', true)
            );
    }

    public function test_resolve_is_dark_matrix(): void
    {
        $this->assertFalse(PanelColorScheme::resolveIsDark([
            'mode' => 'light',
            'locked' => true,
        ]));

        $this->assertTrue(PanelColorScheme::resolveIsDark([
            'mode' => 'dark',
            'locked' => true,
        ]));

        $this->assertFalse(PanelColorScheme::resolveIsDark([
            'mode' => 'dark',
            'locked' => false,
        ], 'light'));

        $this->assertTrue(PanelColorScheme::resolveIsDark([
            'mode' => 'light',
            'locked' => false,
        ], 'dark'));

        $this->assertFalse(PanelColorScheme::resolveIsDark([
            'mode' => 'system',
            'locked' => false,
        ], null, false));

        $this->assertTrue(PanelColorScheme::resolveIsDark([
            'mode' => 'system',
            'locked' => false,
        ], null, true));

        $this->assertTrue(PanelColorScheme::resolveIsDark([
            'mode' => 'system',
            'locked' => true,
        ], 'light', true));

        $this->assertFalse(PanelColorScheme::showToggler([
            'mode' => 'dark',
            'locked' => true,
        ]));

        $this->assertTrue(PanelColorScheme::showToggler([
            'mode' => 'dark',
            'locked' => false,
        ]));
    }
}
