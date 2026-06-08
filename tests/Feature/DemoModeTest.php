<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DemoMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['getfy.demo_mode' => false]);
    }

    public function test_mutations_allowed_when_demo_disabled(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->from(route('plataforma.settings.index'))
            ->put(route('plataforma.settings.demo.update'), [
                'admin_user_id' => null,
                'seller_user_id' => null,
            ])
            ->assertOk();
    }

    public function test_mutations_blocked_when_demo_enabled(): void
    {
        config(['getfy.demo_mode' => true]);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->putJson(route('plataforma.settings.demo.update'), [
                'admin_user_id' => null,
                'seller_user_id' => null,
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Modo demonstração ativo. Alterações desabilitadas.');

        $this->actingAs($admin)
            ->putJson(route('plataforma.settings.update'), [
                'email_provider' => 'smtp',
            ])
            ->assertForbidden();
    }

    public function test_api_pix_blocked_when_demo_enabled(): void
    {
        config(['getfy.demo_mode' => true]);

        $this->postJson('/api/v1/payments/pix', [])
            ->assertForbidden();
    }

    public function test_demo_login_authenticates_configured_users(): void
    {
        config(['getfy.demo_mode' => true]);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
            'email' => 'demo-admin@test.local',
        ]);

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => null,
            'email' => 'demo-seller@test.local',
        ]);
        $seller->update(['tenant_id' => $seller->id]);

        DemoMode::saveUserIds((int) $admin->id, (int) $seller->id);

        $this->post(route('demo.login.admin'))
            ->assertRedirect(route('plataforma.dashboard'));
        $this->assertAuthenticatedAs($admin);

        $this->post(route('logout'));

        $this->post(route('demo.login.seller'))
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($seller);
    }

    public function test_platform_dashboard_returns_fake_kpis_when_demo_enabled(): void
    {
        config(['getfy.demo_mode' => true]);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Dashboard')
                ->where('kpis.quantidade_vendas', fn ($v) => $v > 0)
                ->where('kpis.vendas_totais', fn ($v) => $v > 0)
                ->has('grafico_vendas', 24)
            );
    }

    public function test_provision_creates_demo_users_when_demo_disabled(): void
    {
        config(['app.url' => 'https://demo.example.com']);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->postJson(route('plataforma.settings.demo.provision'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'email' => 'demo-admin@demo.example.com',
            'role' => User::ROLE_PLATFORM_ADMIN,
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'demo-vendedor@demo.example.com',
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
    }

    public function test_auth_routes_remain_allowed_when_demo_enabled(): void
    {
        config(['getfy.demo_mode' => true]);

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
            'email' => 'seller@test.local',
            'password' => bcrypt('secret-password'),
        ]);

        $this->post(route('login'), [
            'email' => 'seller@test.local',
            'password' => 'secret-password',
        ])->assertRedirect();
    }
}
