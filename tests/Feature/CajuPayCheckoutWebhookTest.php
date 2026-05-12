<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CajuPayCheckoutWebhookTest extends TestCase
{
    public function test_checkout_webhook_rejects_invalid_signature(): void
    {
        $raw = json_encode([
            'id' => 'evt-1',
            'type' => 'checkout.payment.paid',
            'data' => ['object' => ['checkout_session_id' => 'sess-1', 'cajupay_charge_id' => 'ch-1']],
        ]);
        $this->assertIsString($raw);
        $response = $this->call('POST', route('webhooks.cajupay.checkout'), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CAJUPAY_SIGNATURE' => 't='.time().',v1=deadbeef',
        ], [], $raw);

        $response->assertStatus(401);
    }

    public function test_checkout_webhook_paid_completes_pending_order(): void
    {
        Event::fake([OrderCompleted::class]);

        config(['services.cajupay.base_url' => 'https://api.cajupay.com.br']);
        Http::fake([
            'https://api.cajupay.com.br/api/sdk/public/checkout/sessions/*' => Http::response(['status' => 'paid'], 200),
        ]);

        $signingSecret = 'cwhsec_test_secret_value_32chars_x';

        $cred = new GatewayCredential([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
            'checkout_webhook_signing_secret' => $signingSecret,
        ]);
        $cred->save();

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['name' => 'Caju product']);

        $sessionId = '550e8400-e29b-41d4-a716-446655440000';
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 25.90,
            'email' => 'buyer@example.com',
            'payment_method' => 'card',
            'gateway' => null,
            'gateway_id' => null,
            'metadata' => [
                'cajupay_checkout_session_id' => $sessionId,
                'cajupay_sdk_token' => 'public-token-test',
                'cajupay_sdk_nonce' => str_repeat('a', 40),
            ],
        ]);

        $raw = json_encode([
            'id' => 'evt-paid-1',
            'type' => 'checkout.payment.paid',
            'api_version' => '2026-05-09',
            'created' => gmdate('Y-m-d\TH:i:s\Z'),
            'data' => [
                'object' => [
                    'gateway' => 'cajupay',
                    'cajupay_charge_id' => 'charge-test-uuid',
                    'checkout_session_id' => $sessionId,
                    'amount_cents' => 2590,
                    'currency' => 'brl',
                ],
            ],
        ]);
        $this->assertIsString($raw);
        $ts = time();
        $sig = hash_hmac('sha256', $ts.'.'.$raw, $signingSecret);

        $response = $this->call('POST', route('webhooks.cajupay.checkout'), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CAJUPAY_SIGNATURE' => 't='.$ts.',v1='.$sig,
        ], [], $raw);

        $response->assertOk();
        $this->assertSame('completed', $order->fresh()->status);
        Event::assertDispatched(OrderCompleted::class);
    }
}
