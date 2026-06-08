<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Models\User;
use App\Services\PaymentService;
use Tests\TestCase;

class GatewayEnabledToggleTest extends TestCase
{
    private function seedConnectedGateways(): void
    {
        foreach (
            [
                'cajupay' => ['public_key' => 'pk', 'secret_key' => 'sk'],
                'mercadopago' => ['public_key' => 'pk', 'access_token' => 'TEST'],
            ] as $slug => $creds
        ) {
            $cred = GatewayCredential::query()->firstOrNew([
                'tenant_id' => null,
                'gateway_slug' => $slug,
            ]);
            $cred->is_connected = true;
            $cred->is_enabled = true;
            $cred->setEncryptedCredentials($creds);
            $cred->save();
        }

        Setting::set('gateway_order', [
            'pix' => ['mercadopago', 'cajupay'],
            'card' => ['mercadopago', 'cajupay'],
            'boleto' => [],
            'pix_auto' => [],
        ], null);
    }

    public function test_platform_admin_can_disable_configured_gateway(): void
    {
        $this->seedConnectedGateways();

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $response = $this->actingAs($admin)->putJson(route('plataforma.financeiro.gateways.enabled', 'mercadopago'), [
            'is_enabled' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('is_enabled', false);

        $cred = GatewayCredential::query()
            ->whereNull('tenant_id')
            ->where('gateway_slug', 'mercadopago')
            ->first();

        $this->assertNotNull($cred);
        $this->assertFalse($cred->is_enabled);
    }

    public function test_cannot_toggle_gateway_without_credentials(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $response = $this->actingAs($admin)->putJson(route('plataforma.financeiro.gateways.enabled', 'mercadopago'), [
            'is_enabled' => false,
        ]);

        $response->assertStatus(422);
    }

    public function test_disabled_gateway_is_excluded_from_checkout_payment_methods(): void
    {
        $this->seedConnectedGateways();

        $cred = GatewayCredential::query()
            ->whereNull('tenant_id')
            ->where('gateway_slug', 'mercadopago')
            ->first();
        $cred->is_enabled = false;
        $cred->save();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $methods = app(PaymentService::class)->availablePaymentMethodsForCheckout($product);
        $pix = collect($methods)->firstWhere('id', 'pix');
        $card = collect($methods)->firstWhere('id', 'card');

        $this->assertSame('cajupay', $pix['gateway_slug'] ?? null);
        $this->assertSame('cajupay', $card['gateway_slug'] ?? null);
    }
}
