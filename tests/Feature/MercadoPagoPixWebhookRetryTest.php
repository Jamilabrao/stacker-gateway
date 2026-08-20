<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoPixWebhookRetryTest extends TestCase
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

    private function createPendingOrder(string $paymentId): Order
    {
        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['name' => 'MP PIX retry']);

        return Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 25.50,
            'email' => 'mp-retry@example.com',
            'payment_method' => 'pix',
            'gateway' => 'mercadopago',
            'gateway_id' => $paymentId,
            'metadata' => ['mercadopago_payment_id' => $paymentId],
        ]);
    }

    public function test_pending_api_status_releases_job_for_retry_then_completes_when_approved(): void
    {
        Event::fake([OrderCompleted::class]);
        $this->seedMercadoPagoCredentials();

        $paymentId = '111222333';
        $order = $this->createPendingOrder($paymentId);

        Http::fake([
            'https://api.mercadopago.com/v1/payments/'.$paymentId => Http::sequence()
                ->push(['id' => (int) $paymentId, 'status' => 'pending', 'external_reference' => (string) $order->id], 200)
                ->push(['id' => (int) $paymentId, 'status' => 'approved', 'external_reference' => (string) $order->id], 200),
        ]);

        $job = new ProcessPaymentWebhook(
            'mercadopago',
            $paymentId,
            'payment.updated',
            'pending',
            ['webhook_source' => 'mercadopago_webhook']
        );
        $job->withFakeQueueInteractions();

        $job->handle();

        $order->refresh();
        $this->assertSame('pending', $order->status);
        $job->assertReleased(5);

        // Segunda tentativa: API já approved
        $job2 = new ProcessPaymentWebhook(
            'mercadopago',
            $paymentId,
            'payment.updated',
            'pending',
            ['webhook_source' => 'mercadopago_webhook']
        );
        $job2->withFakeQueueInteractions();
        $job2->handle();

        $order->refresh();
        $this->assertSame('completed', $order->status);
        Event::assertDispatched(OrderCompleted::class);
    }

    public function test_concurrent_lock_releases_job_instead_of_dropping_approval(): void
    {
        Event::fake([OrderCompleted::class]);
        $this->seedMercadoPagoCredentials();

        $paymentId = '444555666';
        $order = $this->createPendingOrder($paymentId);

        Http::fake([
            'https://api.mercadopago.com/v1/payments/'.$paymentId => Http::response([
                'id' => (int) $paymentId,
                'status' => 'approved',
                'external_reference' => (string) $order->id,
            ], 200),
        ]);

        $lockKey = 'webhook_processing.mercadopago.'.$paymentId;
        Cache::put($lockKey, true, now()->addMinutes(5));

        $job = new ProcessPaymentWebhook(
            'mercadopago',
            $paymentId,
            'payment.updated',
            'pending',
            ['webhook_source' => 'mercadopago_webhook']
        );
        $job->withFakeQueueInteractions();
        $job->handle();

        $order->refresh();
        $this->assertSame('pending', $order->status, 'Com lock ativo o pedido não deve completar nesta tentativa');
        $job->assertReleased(5);
        Event::assertNotDispatched(OrderCompleted::class);

        Cache::forget($lockKey);
    }

    public function test_rejected_api_status_does_not_complete_order(): void
    {
        Event::fake([OrderCompleted::class, \App\Events\OrderRejected::class]);
        $this->seedMercadoPagoCredentials();

        $paymentId = '202020999';
        $order = $this->createPendingOrder($paymentId);

        Http::fake([
            'https://api.mercadopago.com/v1/payments/'.$paymentId => Http::response([
                'id' => (int) $paymentId,
                'status' => 'rejected',
                'status_detail' => 'cc_rejected_insufficient_amount',
                'external_reference' => (string) $order->id,
            ], 200),
            'https://api.mercadopago.com/v1/payments/search*' => Http::response(['results' => []], 200),
        ]);

        $job = new ProcessPaymentWebhook(
            'mercadopago',
            $paymentId,
            'payment.updated',
            'pending',
            ['webhook_source' => 'mercadopago_webhook']
        );
        $job->withFakeQueueInteractions();
        $job->handle();

        $order->refresh();
        $this->assertSame('rejected', $order->status);
        $job->assertNotReleased();
        Event::assertNotDispatched(OrderCompleted::class);
        Event::assertDispatched(\App\Events\OrderRejected::class);
    }

    public function test_cancelled_api_status_does_not_retry(): void
    {
        Event::fake([OrderCompleted::class, \App\Events\OrderRejected::class]);
        $this->seedMercadoPagoCredentials();

        $paymentId = '777888999';
        $order = $this->createPendingOrder($paymentId);

        Http::fake([
            'https://api.mercadopago.com/v1/payments/'.$paymentId => Http::response([
                'id' => (int) $paymentId,
                'status' => 'cancelled',
                'external_reference' => (string) $order->id,
            ], 200),
            'https://api.mercadopago.com/v1/payments/search*' => Http::response(['results' => []], 200),
        ]);

        $job = new ProcessPaymentWebhook(
            'mercadopago',
            $paymentId,
            'payment.updated',
            'pending',
            ['webhook_source' => 'mercadopago_webhook']
        );
        $job->withFakeQueueInteractions();
        $job->handle();

        $order->refresh();
        $this->assertSame('rejected', $order->status);
        $job->assertNotReleased();
        Event::assertNotDispatched(OrderCompleted::class);
        Event::assertDispatched(\App\Events\OrderRejected::class);
    }

    public function test_api_unavailable_does_not_force_paid_and_retries(): void
    {
        Event::fake([OrderCompleted::class]);
        $this->seedMercadoPagoCredentials();

        $paymentId = '101010999';
        $order = $this->createPendingOrder($paymentId);

        Http::fake([
            'https://api.mercadopago.com/v1/payments/'.$paymentId => Http::response(['message' => 'error'], 500),
            'https://api.mercadopago.com/v1/payments/search*' => Http::response(['results' => []], 200),
        ]);

        $job = new ProcessPaymentWebhook(
            'mercadopago',
            $paymentId,
            'payment.created',
            'pending',
            ['webhook_source' => 'mercadopago_webhook']
        );
        $job->withFakeQueueInteractions();
        $job->handle();

        $order->refresh();
        $this->assertSame('pending', $order->status, 'Não deve liberar acesso sem approved na API');
        $job->assertReleased(5);
        Event::assertNotDispatched(OrderCompleted::class);
    }

    public function test_authorized_api_status_does_not_complete_order(): void
    {
        Event::fake([OrderCompleted::class]);
        $this->seedMercadoPagoCredentials();

        $paymentId = '303030999';
        $order = $this->createPendingOrder($paymentId);

        Http::fake([
            'https://api.mercadopago.com/v1/payments/'.$paymentId => Http::response([
                'id' => (int) $paymentId,
                'status' => 'authorized',
                'external_reference' => (string) $order->id,
            ], 200),
            'https://api.mercadopago.com/v1/payments/search*' => Http::response(['results' => []], 200),
        ]);

        $job = new ProcessPaymentWebhook(
            'mercadopago',
            $paymentId,
            'payment.updated',
            'pending',
            ['webhook_source' => 'mercadopago_webhook']
        );
        $job->withFakeQueueInteractions();
        $job->handle();

        $order->refresh();
        $this->assertSame('pending', $order->status, 'authorized não é creditado — não liberar');
        $job->assertReleased(5);
        Event::assertNotDispatched(OrderCompleted::class);
    }
}
