<?php

namespace Tests\Feature;

use App\Models\ApiApplication;
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

    public function test_med_opened_checkout_creates_platform_dispute_without_order_disputed(): void
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
                    'reason' => 'Suspeita de fraude',
                ],
            ],
        ], $secret)->assertOk();

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertDatabaseHas('med_disputes', [
            'order_id' => $order->id,
            'cajupay_dispute_id' => $disputeId,
            'status' => MedDispute::STATUS_OPEN,
            'responsible_party' => MedDispute::PARTY_PLATFORM,
        ]);
    }

    public function test_med_opened_api_pix_marks_order_disputed_and_tenant_managed(): void
    {
        $secret = 'cwhsec_med_api_pix_secret_32chars';
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

        $user = User::factory()->create(['tenant_id' => 2]);
        $product = $this->createTestProduct(['tenant_id' => 2]);
        $apiApp = ApiApplication::create([
            'tenant_id' => 2,
            'name' => 'API',
            'slug' => ApiApplication::generateUniqueSlug(2, 'API'),
            'api_key_hash' => hash('sha256', 'k'),
            'public_key' => ApiApplication::generatePublicKey(),
            'secret_key_hash' => hash('sha256', 's'),
            'payment_gateways' => ApiApplication::defaultPaymentGateways(),
            'allowed_ips' => [],
            'is_active' => true,
            'is_legacy' => true,
            'scopes' => [],
        ]);

        $paymentId = 'pay-med-api-001';
        $order = Order::create([
            'tenant_id' => 2,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'api_application_id' => $apiApp->id,
            'status' => 'completed',
            'amount' => 99,
            'email' => 'api-med@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
            'gateway_id' => $paymentId,
            'metadata' => ['source' => 'api'],
        ]);

        $disputeId = 'dispute-api-001';
        $this->postSignedWebhook([
            'id' => 'evt-med-api',
            'type' => 'pix.payment.med_opened',
            'data' => [
                'object' => [
                    'cajupay_payment_id' => $paymentId,
                    'med_dispute_id' => $disputeId,
                    'amount_cents' => 9900,
                    'status' => 'open',
                ],
            ],
        ], $secret)->assertOk();

        $this->assertSame('disputed', $order->fresh()->status);
        $this->assertDatabaseHas('med_disputes', [
            'order_id' => $order->id,
            'responsible_party' => MedDispute::PARTY_TENANT,
        ]);
    }

    public function test_med_resolved_api_pix_closes_open_dispute(): void
    {
        $secret = 'cwhsec_med_api_resolve_secret_32c';
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

        $user = User::factory()->create(['tenant_id' => 3]);
        $product = $this->createTestProduct(['tenant_id' => 3]);
        $apiApp = ApiApplication::create([
            'tenant_id' => 3,
            'name' => 'API',
            'slug' => ApiApplication::generateUniqueSlug(3, 'API'),
            'api_key_hash' => hash('sha256', 'k3'),
            'public_key' => ApiApplication::generatePublicKey(),
            'secret_key_hash' => hash('sha256', 's3'),
            'payment_gateways' => ApiApplication::defaultPaymentGateways(),
            'allowed_ips' => [],
            'is_active' => true,
            'is_legacy' => true,
            'scopes' => [],
        ]);

        $paymentId = 'pay-med-api-resolve-001';
        $order = Order::create([
            'tenant_id' => 3,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'api_application_id' => $apiApp->id,
            'status' => 'disputed',
            'amount' => 55,
            'email' => 'api-med-r@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
            'gateway_id' => $paymentId,
            'metadata' => ['source' => 'api', 'cajupay_payment_id' => $paymentId],
        ]);

        $disputeId = 'dispute-api-resolve-001';
        MedDispute::query()->create([
            'order_id' => $order->id,
            'tenant_id' => 3,
            'responsible_party' => MedDispute::PARTY_TENANT,
            'cajupay_dispute_id' => $disputeId,
            'cajupay_payment_id' => $paymentId,
            'status' => MedDispute::STATUS_OPEN,
            'amount_cents' => 5500,
            'currency' => 'BRL',
            'opened_at' => now(),
        ]);

        // Resolve só com med_dispute_id (sem payment id) — caso típico de falha na API PIX.
        $this->postSignedWebhook([
            'id' => 'evt-med-api-resolved',
            'type' => 'pix.payment.med_resolved',
            'data' => [
                'object' => [
                    'med_dispute_id' => $disputeId,
                    'status' => 'resolved_won',
                    'outcome' => 'won',
                    'amount_cents' => 5500,
                ],
            ],
        ], $secret)->assertOk();

        $this->assertDatabaseHas('med_disputes', [
            'cajupay_dispute_id' => $disputeId,
            'status' => MedDispute::STATUS_RESOLVED_WON,
            'outcome' => 'won',
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
