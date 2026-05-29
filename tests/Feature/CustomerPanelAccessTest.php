<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerPanelAccessTest extends TestCase
{
    public function test_cliente_dashboard_redirects_to_painel_cliente(): void
    {
        $buyer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($buyer)
            ->get('/dashboard')
            ->assertRedirect('/painel-cliente');
    }

    public function test_aluno_dashboard_redirects_to_painel_cliente(): void
    {
        $buyer = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($buyer)
            ->get('/dashboard')
            ->assertRedirect('/painel-cliente');
    }

    public function test_cliente_home_redirects_to_painel_cliente(): void
    {
        $buyer = User::factory()->create(['role' => User::ROLE_CLIENTE]);

        $this->actingAs($buyer)
            ->get('/')
            ->assertRedirect('/painel-cliente');
    }

    public function test_cliente_can_access_painel_cliente(): void
    {
        $buyer = User::factory()->create(['role' => User::ROLE_CLIENTE]);

        $this->actingAs($buyer)
            ->get('/painel-cliente')
            ->assertOk();
    }

    public function test_cliente_switch_to_seller_without_onboarding_redirects_to_upgrade(): void
    {
        $buyer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'seller_onboarded_at' => null,
        ]);

        $this->actingAs($buyer)
            ->post('/painel/trocar', ['to' => 'seller'])
            ->assertRedirect(route('cadastro.infoprodutor'));
    }

    public function test_infoprodutor_home_redirects_to_dashboard(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->update(['tenant_id' => $seller->id]);

        $this->actingAs($seller)
            ->get('/')
            ->assertRedirect('/dashboard');
    }
}
