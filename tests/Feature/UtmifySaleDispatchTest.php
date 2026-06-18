<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Events\PixGenerated;
use App\Jobs\UtmifySendOrderJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\UtmifyIntegration;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UtmifySaleDispatchTest extends TestCase
{
    public function test_order_completed_sends_paid_payload_to_utmify_api(): void
    {
        Http::fake([
            'api.utmify.com.br/*' => Http::response(['ok' => true], 200),
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $integration = UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy e2e',
            'api_key' => 'e2e-api-key',
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 99.90,
            'email' => 'e2e@example.com',
            'metadata' => [
                'utm_source' => 'facebook',
                'utm_campaign' => 'black-friday',
            ],
        ]);

        event(new OrderCompleted($order));

        Http::assertSent(function ($request) use ($order) {
            if ($request->url() !== 'https://api.utmify.com.br/api-credentials/orders') {
                return false;
            }

            $body = $request->data();

            return $request->hasHeader('x-api-token', 'e2e-api-key')
                && ($body['status'] ?? '') === 'paid'
                && ($body['orderId'] ?? '') === (string) $order->id
                && ($body['trackingParameters']['utm_source'] ?? '') === 'facebook'
                && ($body['trackingParameters']['utm_campaign'] ?? '') === 'black-friday'
                && array_key_exists('utm_content', $body['trackingParameters'] ?? [])
                && array_key_exists('utm_term', $body['trackingParameters'] ?? []);
        });

        $order->refresh();
        $this->assertNotEmpty($order->metadata['utmify_paid_sent_at'] ?? null);
    }

    public function test_waiting_payment_skipped_when_order_already_paid(): void
    {
        Http::fake([
            'api.utmify.com.br/*' => Http::response(['ok' => true], 200),
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $integration = UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy regression',
            'api_key' => 'regression-api-key',
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'regression@example.com',
            'metadata' => ['utmify_paid_sent_at' => now()->subMinutes(5)->toIso8601String()],
        ]);

        $job = new UtmifySendOrderJob($integration->id, $order->id, 'waiting_payment');
        $job->handle(app(\App\Services\UtmifyService::class));

        Http::assertNothingSent();
    }

    public function test_product_filter_prevents_job_dispatch(): void
    {
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $productA = $this->createTestProduct(['tenant_id' => 1, 'name' => 'Produto A']);
        $productB = $this->createTestProduct(['tenant_id' => 1, 'name' => 'Produto B']);

        $integration = UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy filtered',
            'api_key' => 'filter-api-key',
            'is_active' => true,
        ]);
        $integration->products()->sync([$productB->id]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $productA->id,
            'status' => 'completed',
            'amount' => 30,
            'email' => 'filter@example.com',
        ]);

        event(new OrderCompleted($order));

        Queue::assertNotPushed(UtmifySendOrderJob::class);
    }

    public function test_pix_generated_dispatches_waiting_payment_job(): void
    {
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy pix',
            'api_key' => 'pix-api-key',
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 40,
            'email' => 'pix@example.com',
        ]);

        event(new PixGenerated($order, ['qr_code' => 'test']));

        Queue::assertPushed(UtmifySendOrderJob::class, function (UtmifySendOrderJob $job) use ($order) {
            return $job->orderId === $order->id && $job->utmifyStatus === 'waiting_payment';
        });
    }

    public function test_failed_job_records_error_metadata(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $integration = UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy fail',
            'api_key' => 'fail-api-key',
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 20,
            'email' => 'fail@example.com',
        ]);

        $job = new UtmifySendOrderJob(
            $integration->id,
            $order->id,
            'paid',
            now()->utc()->format('Y-m-d H:i:s')
        );
        $job->failed(new \RuntimeException('UTMIFY API error: 401 Unauthorized'));

        $order->refresh();
        $this->assertNotEmpty($order->metadata['utmify_failed_at'] ?? null);
        $this->assertStringContainsString('401', $order->metadata['utmify_last_error'] ?? '');
    }
}
