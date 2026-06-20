<?php

namespace Tests\Feature\Meta;

use App\Events\OrderCompleted;
use App\Jobs\Meta\SendMetaTrackingEventJob;
use App\Jobs\MetaConversionsSendPurchaseJob;
use App\Models\MetaTrackingEvent;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\MetaConversionsService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetaConversionsSendPurchaseJobTest extends TestCase
{
    public function test_order_completed_dispatches_meta_capi_job(): void
    {
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '123', 'access_token' => 'tok'],
                    ],
                ],
            ],
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => null,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'buyer@test.com',
            'payment_method' => 'pix',
            'metadata' => ['fbp' => 'fb.1.test', 'fbc' => 'fb.1.click'],
        ]);

        event(new OrderCompleted($order));

        Queue::assertPushed(MetaConversionsSendPurchaseJob::class, fn ($job) => $job->orderId === $order->id);
    }

    public function test_service_sets_metadata_on_successful_capi(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1], 200),
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '999', 'access_token' => 'secret'],
                    ],
                ],
            ],
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 99.9,
            'email' => 'a@b.com',
            'metadata' => [
                'fbp' => 'fb.1.111',
                'fbc' => 'fb.1.222',
                'user_agent' => 'Test',
            ],
        ]);

        $results = app(MetaConversionsService::class)->sendPurchaseForOrder($order);

        $this->assertNotEmpty($results);
        $order->refresh();
        $this->assertTrue($order->metadata['meta_capi_sent_purchase'] ?? false);

        $orderId = $order->id;
        Http::assertSent(function ($req) use ($orderId) {
            $data = $req->data();
            $evt = $data['data'][0] ?? [];
            $ud = $evt['user_data'] ?? [];

            return ($evt['event_id'] ?? '') === 'order:'.$orderId
                && isset($ud['fbp']) && isset($ud['fbc']);
        });
    }

    public function test_capi_skips_pix_when_fire_purchase_on_pix_disabled(): void
    {
        Http::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        [
                            'pixel_id' => '123',
                            'access_token' => 'tok',
                            'fire_purchase_on_pix' => false,
                        ],
                    ],
                ],
            ],
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'buyer@test.com',
            'payment_method' => 'pix',
        ]);

        app(MetaConversionsService::class)->sendPurchaseForOrder($order);

        Http::assertNothingSent();
        $order->refresh();
        $this->assertFalse($order->metadata['meta_capi_sent_purchase'] ?? false);
    }

    public function test_capi_records_missing_access_token_in_metadata(): void
    {
        Http::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '123'],
                    ],
                ],
            ],
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'buyer@test.com',
            'payment_method' => 'credit_card',
        ]);

        app(MetaConversionsService::class)->sendPurchaseForOrder($order);

        Http::assertNothingSent();
        $order->refresh();
        $this->assertSame('missing_access_token', $order->metadata['meta_capi_skipped_reason'] ?? null);
    }

    public function test_order_completed_queues_send_meta_tracking_via_purchase_job(): void
    {
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '555', 'access_token' => 'secret'],
                    ],
                ],
            ],
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 25,
            'email' => 'sync@test.com',
            'payment_method' => 'credit_card',
        ]);

        event(new OrderCompleted($order));

        Queue::assertPushed(MetaConversionsSendPurchaseJob::class);
    }

    public function test_purchase_job_creates_tracking_records(): void
    {
        Queue::fake([SendMetaTrackingEventJob::class]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '777888999', 'access_token' => 'tok'],
                    ],
                ],
            ],
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => 'x@y.com',
            'payment_method' => 'credit_card',
        ]);

        (new MetaConversionsSendPurchaseJob($order->id))->handle(app(\App\Services\Meta\MetaTrackingService::class));

        $this->assertDatabaseHas('meta_tracking_events', [
            'event_name' => 'Purchase',
            'event_id' => 'order:'.$order->id,
            'pixel_id' => '777888999',
        ]);
    }
}
