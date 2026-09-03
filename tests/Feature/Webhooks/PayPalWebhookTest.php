<?php

namespace Tests\Feature\Webhooks;

use App\Events\OrderCompleted;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayPalWebhookTest extends TestCase
{
    public function test_capture_completed_webhook_marks_pending_order_paid(): void
    {
        Event::fake([OrderCompleted::class]);

        $cred = new GatewayCredential([
            'tenant_id' => 1,
            'gateway_slug' => 'paypal',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials([
            'client_id' => 'paypal_client',
            'client_secret' => 'paypal_secret',
            'webhook_id' => 'WH-TEST',
            'sandbox' => true,
        ]);
        $cred->save();

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $paypalOrderId = 'PAYPALORDERTEST1';

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 25.90,
            'email' => 'paypal@example.com',
            'payment_method' => 'paypal',
            'gateway' => 'paypal',
            'gateway_id' => $paypalOrderId,
            'metadata' => ['checkout_payment_method' => 'paypal'],
        ]);

        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'tok_test',
                'expires_in' => 300,
            ]),
            'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'SUCCESS',
            ]),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders/'.$paypalOrderId => Http::response([
                'id' => $paypalOrderId,
                'status' => 'COMPLETED',
            ]),
        ]);

        $payload = [
            'id' => 'WH-EVT-1',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'CAPTURE1',
                'custom_id' => (string) $order->id,
                'supplementary_data' => [
                    'related_ids' => [
                        'order_id' => $paypalOrderId,
                    ],
                ],
            ],
        ];
        $raw = json_encode($payload);
        $this->assertIsString($raw);

        $response = $this->call('POST', route('webhooks.paypal'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/cert',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'tx-1',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-01-01T00:00:00Z',
        ], $raw);

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertSame('completed', $order->fresh()->status);
        Event::assertDispatched(OrderCompleted::class);
    }

    public function test_unknown_order_still_acknowledges_webhook(): void
    {
        $payload = json_encode([
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => ['id' => 'missing'],
        ]);
        $this->assertIsString($payload);

        $this->call('POST', route('webhooks.paypal'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload)
            ->assertOk()
            ->assertJson(['received' => true]);
    }
}
