<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use App\Services\MercadoPago\MercadoPagoCheckoutCompletionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoPixWebhookFlowTest extends TestCase
{
    private function seedMercadoPagoCredentials(): void
    {
        $cred = new GatewayCredential([
            'tenant_id' => null,
            'gateway_slug' => 'mercadopago',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials(['access_token' => 'TEST_MP_ACCESS_TOKEN']);
        $cred->save();
    }

    private function fakeMercadoPagoPaymentApproved(string $paymentId): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/payments/'.$paymentId => Http::response([
                'id' => (int) $paymentId,
                'status' => 'approved',
                'external_reference' => '',
            ], 200),
        ]);
    }

    private function createPendingMercadoPagoOrder(string $gatewayId, array $metadata = []): Order
    {
        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['name' => 'MP product']);

        return Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 49.90,
            'email' => 'mp-buyer@example.com',
            'payment_method' => 'pix',
            'gateway' => 'mercadopago',
            'gateway_id' => $gatewayId,
            'metadata' => array_merge([
                'mercadopago_payment_id' => $gatewayId,
            ], $metadata),
        ]);
    }

    public function test_webhook_json_payment_updated_completes_pending_order(): void
    {
        Event::fake([OrderCompleted::class]);
        $this->seedMercadoPagoCredentials();

        $paymentId = '987654321';
        $order = $this->createPendingMercadoPagoOrder($paymentId);
        $this->fakeMercadoPagoPaymentApproved($paymentId);

        config(['queue.default' => 'sync']);

        $response = $this->postJson(route('webhooks.mercadopago'), [
            'action' => 'payment.updated',
            'type' => 'payment',
            'data' => ['id' => $paymentId],
        ]);

        $response->assertOk();
        $this->assertSame('completed', $order->fresh()->status);
        Event::assertDispatched(OrderCompleted::class);
    }

    public function test_ipn_get_completes_pending_order(): void
    {
        Event::fake([OrderCompleted::class]);
        $this->seedMercadoPagoCredentials();

        $paymentId = '112233445';
        $order = $this->createPendingMercadoPagoOrder($paymentId);
        $this->fakeMercadoPagoPaymentApproved($paymentId);

        config(['queue.default' => 'sync']);

        $response = $this->get(route('webhooks.mercadopago.ipn', [
            'topic' => 'payment',
            'id' => $paymentId,
        ]));

        $response->assertOk();
        $this->assertSame('completed', $order->fresh()->status);
        Event::assertDispatched(OrderCompleted::class);
    }

    public function test_webhook_with_different_payment_id_resolves_by_external_reference(): void
    {
        Event::fake([OrderCompleted::class]);
        $this->seedMercadoPagoCredentials();

        $stalePaymentId = '111111111';
        $paidPaymentId = '222222222';
        $order = $this->createPendingMercadoPagoOrder($stalePaymentId);

        Http::fake([
            'https://api.mercadopago.com/v1/payments/'.$stalePaymentId => Http::response([
                'id' => (int) $stalePaymentId,
                'status' => 'pending',
            ], 200),
            'https://api.mercadopago.com/v1/payments/'.$paidPaymentId => Http::response([
                'id' => (int) $paidPaymentId,
                'status' => 'approved',
                'external_reference' => (string) $order->id,
            ], 200),
        ]);

        config(['queue.default' => 'sync']);

        $this->postJson(route('webhooks.mercadopago'), [
            'action' => 'payment.updated',
            'type' => 'payment',
            'data' => [
                'id' => $paidPaymentId,
                'object' => ['external_reference' => (string) $order->id],
            ],
        ])->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame($paidPaymentId, $fresh->gateway_id);
        Event::assertDispatched(OrderCompleted::class);
    }

    public function test_reconcile_finds_approved_payment_by_external_reference(): void
    {
        Event::fake([OrderCompleted::class]);
        $this->seedMercadoPagoCredentials();

        $stalePaymentId = '333333333';
        $paidPaymentId = '444444444';
        $order = $this->createPendingMercadoPagoOrder($stalePaymentId);

        Http::fake([
            'https://api.mercadopago.com/v1/payments/'.$stalePaymentId => Http::response([
                'id' => (int) $stalePaymentId,
                'status' => 'pending',
            ], 200),
            'https://api.mercadopago.com/v1/payments/search*' => Http::response([
                'results' => [
                    [
                        'id' => (int) $paidPaymentId,
                        'status' => 'approved',
                        'external_reference' => (string) $order->id,
                    ],
                ],
            ], 200),
            'https://api.mercadopago.com/v1/payments/'.$paidPaymentId => Http::response([
                'id' => (int) $paidPaymentId,
                'status' => 'approved',
            ], 200),
        ]);

        $this->artisan('payments:reconcile-mercadopago', ['--limit' => 10])
            ->assertSuccessful();

        $fresh = $order->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame($paidPaymentId, $fresh->gateway_id);
        Event::assertDispatched(OrderCompleted::class);
    }

    public function test_pending_webhook_applied_when_order_is_materialized(): void
    {
        Event::fake([OrderCompleted::class]);
        $this->seedMercadoPagoCredentials();

        $paymentId = '555666777';
        app(MercadoPagoCheckoutCompletionService::class)->storePendingPaidWebhook(
            $paymentId,
            null,
            ['type' => 'payment', 'action' => 'payment.updated']
        );

        Http::fake([
            'https://api.mercadopago.com/v1/payments/'.$paymentId => Http::response([
                'id' => (int) $paymentId,
                'status' => 'approved',
            ], 200),
        ]);

        $order = $this->createPendingMercadoPagoOrder($paymentId);

        app(MercadoPagoCheckoutCompletionService::class)->applyPendingForOrder($order);

        $this->assertSame('completed', $order->fresh()->status);
        Event::assertDispatched(OrderCompleted::class);
        $this->assertNull(Cache::get('mercadopago_webhook_pending:'.$paymentId));
    }

    public function test_order_status_poll_completes_mercadopago_order(): void
    {
        Event::fake([OrderCompleted::class]);
        $this->seedMercadoPagoCredentials();

        $paymentId = '888999000';
        $order = $this->createPendingMercadoPagoOrder($paymentId);
        $this->fakeMercadoPagoPaymentApproved($paymentId);

        $token = 'mp-poll-'.str_repeat('b', 24);
        session()->put('pix_display.'.$token, [
            'order_id' => $order->id,
            'amount' => 49.90,
            'product_name' => 'MP product',
            'created_at' => time(),
        ]);

        $this->getJson('/checkout/order-status?token='.$token)
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->assertSame('completed', $order->fresh()->status);
        Event::assertDispatched(OrderCompleted::class);
    }
}
