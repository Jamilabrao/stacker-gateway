<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Order;
use App\Models\UtmifyIntegration;
use App\Models\User;
use App\Services\UtmifyService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UtmifyPayloadAndTestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
    }

    private function createInfoprodutor(): User
    {
        return User::factory()->create([
            'tenant_id' => 1,
            'role' => User::ROLE_INFOPRODUTOR,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ]);
    }

    public function test_build_payload_uses_getfy_platform_name(): void
    {
        $order = new Order([
            'tenant_id' => 1,
            'product_id' => 'prod-1',
            'status' => 'completed',
            'amount' => 10,
            'email' => 'buyer@example.com',
            'gateway' => 'efi',
            'payment_method' => 'card',
        ]);
        $order->id = 456;
        $order->created_at = now();
        $order->updated_at = now();

        $service = new UtmifyService;
        $payload = $service->buildPayload($order, 'paid', []);

        $this->assertSame(config('getfy.app_name', 'Getfy'), $payload['platform']);
    }

    public function test_build_payload_maps_payment_method_from_order_not_gateway(): void
    {
        $order = new Order([
            'tenant_id' => 1,
            'product_id' => 'prod-1',
            'status' => 'completed',
            'amount' => 10,
            'email' => 'buyer@example.com',
            'gateway' => 'efi',
            'payment_method' => 'card',
        ]);
        $order->id = 789;
        $order->created_at = now();
        $order->updated_at = now();

        $service = new UtmifyService;
        $payload = $service->buildPayload($order, 'paid', []);

        $this->assertSame('credit_card', $payload['paymentMethod']);
    }

    public function test_test_endpoint_sends_is_test_payload(): void
    {
        Http::fake([
            'api.utmify.com.br/*' => Http::response(['ok' => true], 200),
        ]);

        $user = $this->createInfoprodutor();

        $integration = UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMIFY test',
            'api_key' => 'test-api-key-utmify',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson(route('integrations.utmify.test', $integration))
            ->assertOk()
            ->assertJson(['success' => true]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://api.utmify.com.br/api-credentials/orders'
                && ($body['isTest'] ?? false) === true
                && ($body['platform'] ?? '') === config('getfy.app_name', 'Getfy');
        });
    }

    public function test_test_endpoint_returns_422_without_api_key(): void
    {
        $user = $this->createInfoprodutor();

        $integration = UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMIFY sem chave',
            'api_key' => null,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson(route('integrations.utmify.test', $integration))
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        Http::assertNothingSent();
    }
}
