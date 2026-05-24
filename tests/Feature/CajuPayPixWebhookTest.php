<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CajuPayPixWebhookTest extends TestCase
{
    public function test_payment_paid_webhook_completes_classic_pix_order(): void
    {
        Event::fake([OrderCompleted::class]);

        $signingSecret = 'cwhsec_pix_test_secret_32chars_xx';

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
        $product = $this->createTestProduct(['name' => 'PIX product']);

        $paymentId = 'pay_pix_classic_001';
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 49.90,
            'email' => 'pix@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
            'gateway_id' => $paymentId,
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);

        $raw = json_encode([
            'id' => 'evt-pix-paid',
            'type' => 'payment.paid',
            'data' => [
                'object' => [
                    'payment_id' => $paymentId,
                    'status' => 'paid',
                ],
            ],
        ]);
        $this->assertIsString($raw);
        $ts = time();
        $sig = hash_hmac('sha256', $ts.'.'.$raw, $signingSecret);

        $response = $this->call('POST', route('webhooks.cajupay'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CAJUPAY_SIGNATURE' => 't='.$ts.',v1='.$sig,
        ], $raw);

        $response->assertOk();
        $this->assertSame('completed', $order->fresh()->status);
        Event::assertDispatched(OrderCompleted::class);
    }

    public function test_order_status_poll_completes_classic_pix_when_api_reports_paid(): void
    {
        Event::fake([OrderCompleted::class]);

        config(['services.cajupay.base_url' => 'https://api.cajupay.com.br']);
        Http::fake([
            'https://api.cajupay.com.br/api/payments/pay_poll_77' => Http::response([
                'payment_id' => 'pay_poll_77',
                'status' => 'paid',
            ], 200),
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
            'amount' => 30,
            'email' => 'pixpoll@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
            'gateway_id' => 'pay_poll_77',
        ]);

        $token = 'pix-poll-'.str_repeat('b', 24);
        session()->put('pix_display.'.$token, [
            'order_id' => $order->id,
            'amount' => 30,
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
