<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformMercadoPagoBalanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['getfy.mp_balance_tool.enabled' => false]);
    }

    public function test_route_returns_404_when_tool_disabled(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->get(route('plataforma.ops.mercadopago-balance'))
            ->assertNotFound();

        $this->actingAs($admin)
            ->get(route('plataforma.ops.mercadopago-balance'))
            ->assertNotFound();
    }

    public function test_guest_is_redirected_to_platform_login_when_tool_enabled(): void
    {
        config(['getfy.mp_balance_tool.enabled' => true]);

        $this->get(route('plataforma.ops.mercadopago-balance'))
            ->assertRedirect('/plataforma/login');
    }

    public function test_infoprodutor_cannot_access_balance_tool(): void
    {
        config(['getfy.mp_balance_tool.enabled' => true]);

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
        ]);

        $this->actingAs($seller)
            ->get(route('plataforma.ops.mercadopago-balance'))
            ->assertForbidden();
    }

    public function test_platform_admin_sees_missing_credentials_state(): void
    {
        config(['getfy.mp_balance_tool.enabled' => true]);

        GatewayCredential::query()->where('gateway_slug', 'mercadopago')->delete();

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.ops.mercadopago-balance'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Ops/MercadoPagoBalance')
                ->where('status', 'missing_credentials')
                ->where('balance', null)
            );
    }

    public function test_platform_admin_sees_balance_from_mercado_pago_api(): void
    {
        config(['getfy.mp_balance_tool.enabled' => true]);

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'mercadopago',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'TEST-public-key',
            'access_token' => 'TEST-access-token',
            'sandbox' => true,
        ]);
        $cred->save();

        Http::fake([
            'api.mercadopago.com/users/me' => Http::response([
                'id' => 123456789,
                'email' => 'conta@exemplo.com',
                'nickname' => 'GETFY',
            ], 200),
            'api.mercadopago.com/users/123456789/mercadopago_account/balance' => Http::response([
                'user_id' => 123456789,
                'total_amount' => 1500.75,
                'available_balance' => 1200.50,
                'unavailable_balance' => 300.25,
                'currency_id' => 'BRL',
            ], 200),
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.ops.mercadopago-balance'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Ops/MercadoPagoBalance')
                ->where('status', 'ok')
                ->where('balance.user_id', 123456789)
                ->where('balance.email', 'conta@exemplo.com')
                ->where('balance.total_amount', 1500.75)
                ->where('balance.available_balance', 1200.50)
                ->where('balance.unavailable_balance', 300.25)
                ->where('balance.currency_id', 'BRL')
                ->where('balance.is_sandbox', true)
            );
    }
}
