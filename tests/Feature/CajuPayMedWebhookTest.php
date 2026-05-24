<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\MedDispute;
use App\Models\Order;
use App\Models\User;
use Tests\TestCase;

class CajuPayMedWebhookTest extends TestCase
{
    private function postSignedWebhook(array $payload, string $secret): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($payload);
        $ts = time();
        $sig = hash_hmac('sha256', $ts.'.'.$raw, $secret);

        return $this->call('POST', route('webhooks.cajupay'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CAJUPAY_SIGNATURE' => 't='.$ts.',v1='.$sig,
            'HTTP_X_CAJUPAY_EVENT' => $payload['type'],
        ], $raw);
    }

    public function test_med_opened_marks_order_disputed_and_creates_record(): void
    {
        $secret = 'cwhsec_med_test_secret_32chars_xx';
        $cred = new GatewayCredential([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials([
            'public_key' => 'pk',
            'secret_key' => 'sk',
            'checkout_webhook_signing_secret' => $secret,
        ]);
        $cred->save();

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct();
        $paymentId = 'pay-med-open-001';
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 120,
            'email' => 'med@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
            'gateway_id' => $paymentId,
        ]);

        $disputeId = 'dispute-uuid-001';
        $this->postSignedWebhook([
            'id' => 'evt-med-open',
            'type' => 'pix.payment.med_opened',
            'data' => [
                'object' => [
                    'cajupay_payment_id' => $paymentId,
                    'med_dispute_id' => $disputeId,
                    'amount_cents' => 12000,
                    'status' => 'open',
                ],
            ],
        ], $secret)->assertOk();

        $this->assertSame('disputed', $order->fresh()->status);
        $this->assertDatabaseHas('med_disputes', [
            'order_id' => $order->id,
            'cajupay_dispute_id' => $disputeId,
            'status' => MedDispute::STATUS_OPEN,
        ]);
    }

    public function test_pix_refunded_webhook_marks_order_refunded(): void
    {
        $secret = 'cwhsec_refund_test_secret_32chars';
        $cred = new GatewayCredential([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials([
            'public_key' => 'pk',
            'secret_key' => 'sk',
            'checkout_webhook_signing_secret' => $secret,
        ]);
        $cred->save();

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct();
        $paymentId = 'pay-refund-wh-001';
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 80,
            'email' => 'ref@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
            'gateway_id' => $paymentId,
        ]);

        $this->postSignedWebhook([
            'id' => 'evt-pix-refund',
            'type' => 'pix.payment.refunded',
            'data' => [
                'object' => [
                    'cajupay_payment_id' => $paymentId,
                    'status' => 'devolvido',
                    'client_refund_id' => 'order-'.$order->id.'-refund',
                ],
            ],
        ], $secret)->assertOk();

        $this->assertSame('refunded', $order->fresh()->status);
    }
}
