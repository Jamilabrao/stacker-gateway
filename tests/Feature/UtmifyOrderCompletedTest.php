<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Jobs\UtmifySendOrderJob;
use App\Models\Order;
use App\Models\UtmifyIntegration;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UtmifyOrderCompletedTest extends TestCase
{
    public function test_order_completed_dispatches_utmify_paid_job(): void
    {
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy test',
            'api_key' => 'test-api-key',
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100,
            'email' => 'buyer@example.com',
        ]);

        event(new OrderCompleted($order));

        Queue::assertPushed(UtmifySendOrderJob::class, function (UtmifySendOrderJob $job) use ($order) {
            return $job->orderId === $order->id && $job->utmifyStatus === 'paid';
        });
    }

    public function test_utmify_paid_job_is_idempotent_via_metadata(): void
    {
        Http::fake([
            'api.utmify.com.br/*' => Http::response(['ok' => true], 200),
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $integration = UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy idempotent',
            'api_key' => 'test-api-key-2',
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'idempotent@example.com',
            'metadata' => ['utmify_paid_sent_at' => now()->subHour()->toIso8601String()],
        ]);

        $job = new UtmifySendOrderJob(
            $integration->id,
            $order->id,
            'paid',
            now()->utc()->format('Y-m-d H:i:s')
        );
        $job->handle(app(\App\Services\UtmifyService::class));

        Http::assertNothingSent();
    }

    public function test_utmify_paid_job_sets_metadata_after_success(): void
    {
        Http::fake([
            'api.utmify.com.br/*' => Http::response(['ok' => true], 200),
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $integration = UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy success',
            'api_key' => 'test-api-key-3',
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 75,
            'email' => 'paid@example.com',
        ]);

        $job = new UtmifySendOrderJob(
            $integration->id,
            $order->id,
            'paid',
            now()->utc()->format('Y-m-d H:i:s')
        );
        $job->handle(app(\App\Services\UtmifyService::class));

        $order->refresh();
        $this->assertNotEmpty($order->metadata['utmify_paid_sent_at'] ?? null);
        Http::assertSentCount(1);
    }
}
