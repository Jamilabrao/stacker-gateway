<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CajuPay\CajuPayCheckoutCompletionService;
use Illuminate\Support\Facades\Cache;
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
        $response = $this->call('POST', route('webhooks.cajupay.checkout'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CAJUPAY_SIGNATURE' => 't='.time().',v1=deadbeef',
        ], $raw);

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
                'cajupay_session_token' => 'public-token-test',
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

        $response = $this->call('POST', route('webhooks.cajupay.checkout'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CAJUPAY_SIGNATURE' => 't='.$ts.',v1='.$sig,
        ], $raw);

        $response->assertOk();
        $this->assertSame('completed', $order->fresh()->status);
        Event::assertDispatched(OrderCompleted::class);
    }

    public function test_pending_paid_webhook_applied_when_order_is_materialized(): void
    {
        Event::fake([OrderCompleted::class]);

        Http::fake([
            'https://api.cajupay.com.br/api/sdk/public/checkout/sessions/*' => Http::response(['status' => 'paid'], 200),
        ]);

        $sessionId = '550e8400-e29b-41d4-a716-446655440099';
        app(CajuPayCheckoutCompletionService::class)->storePendingPaidWebhook(
            $sessionId,
            'charge-pending-1',
            ['type' => 'checkout.payment.paid']
        );

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['name' => 'Late order']);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'late@example.com',
            'payment_method' => 'card',
            'metadata' => [
                'cajupay_checkout_session_id' => $sessionId,
                'cajupay_session_token' => 'public-late-token',
            ],
        ]);

        app(CajuPayCheckoutCompletionService::class)->applyPendingForOrder($order);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('cajupay', $order->fresh()->gateway);
        $this->assertSame('charge-pending-1', $order->fresh()->gateway_id);
        Event::assertDispatched(OrderCompleted::class);
        $this->assertNull(Cache::get('cajupay_checkout_webhook_pending:'.$sessionId));
    }

    public function test_order_status_poll_completes_cajupay_without_gateway_id(): void
    {
        Event::fake([OrderCompleted::class]);

        config(['services.cajupay.base_url' => 'https://api.cajupay.com.br']);
        Http::fake([
            'https://api.cajupay.com.br/api/sdk/public/checkout/sessions/*' => Http::response(['status' => 'paid'], 200),
        ]);

        $cred = new GatewayCredential([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials(['public_key' => 'pk', 'secret_key' => 'sk']);
        $cred->save();

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 50,
            'email' => 'poll@example.com',
            'payment_method' => 'apple_pay',
            'gateway' => null,
            'gateway_id' => null,
            'metadata' => [
                'cajupay_checkout_session_id' => 'sess-poll-1',
                'cajupay_session_token' => 'tok-poll-public',
            ],
        ]);

        $token = 'poll-token-'.str_repeat('a', 24);
        session()->put('cajupay_display.'.$token, [
            'order_id' => $order->id,
            'session_token' => 'tok-poll-public',
            'checkout_session_id' => 'sess-poll-1',
            'payment_method' => 'apple_pay',
            'amount' => 50,
            'product_name' => $product->name,
            'created_at' => time(),
        ]);

        $this->getJson('/checkout/order-status?token='.$token)
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->assertSame('completed', $order->fresh()->status);
        Event::assertDispatched(OrderCompleted::class);
    }
}
