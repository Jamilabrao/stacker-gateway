<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CajuPaySdkCheckoutSessionTest extends TestCase
{
    public function test_sdk_session_creates_and_updates_order_metadata(): void
    {
        config(['services.cajupay.base_url' => 'https://api.cajupay.com.br']);

        Http::fake([
            'https://api.cajupay.com.br/api/sdk/v1/checkout/sessions' => Http::response([
                'token' => 'tok_public_abc',
                'checkout_session_id' => 'sess-uuid-123',
            ], 201),
        ]);

        $cred = new GatewayCredential([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
        ]);
        $cred->save();

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['name' => 'Caju SDK product']);

        $nonce = str_repeat('b', 40);
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10.50,
            'email' => 'buyer2@example.com',
            'payment_method' => 'card',
            'gateway' => null,
            'gateway_id' => null,
            'metadata' => [
                'cajupay_sdk_nonce' => $nonce,
            ],
        ]);

        $response = $this->postJson(route('checkout.cajupay.sdk-session'), [
            'order_id' => $order->id,
            'cajupay_sdk_nonce' => $nonce,
            'cajupay_wallet' => 'card',
        ]);

        $response->assertOk()
            ->assertJsonPath('token', 'tok_public_abc')
            ->assertJsonPath('checkout_session_id', 'sess-uuid-123');

        $meta = $order->fresh()->metadata;
        $this->assertIsArray($meta);
        $this->assertSame('sess-uuid-123', $meta['cajupay_checkout_session_id'] ?? null);
        $this->assertSame('tok_public_abc', $meta['cajupay_sdk_token'] ?? null);
    }
}
