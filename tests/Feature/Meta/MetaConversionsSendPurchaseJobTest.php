<?php

namespace Tests\Feature\Meta;

use App\Events\OrderCompleted;
use App\Jobs\MetaConversionsSendPurchaseJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
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

    public function test_job_sets_metadata_on_successful_capi(): void
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

        (new MetaConversionsSendPurchaseJob($order->id))->handle(app(\App\Services\MetaConversionsService::class));

        $order->refresh();
        $meta = $order->metadata;
        $this->assertTrue($meta['meta_capi_sent_purchase'] ?? false);
        $this->assertNotEmpty($meta['meta_capi_sent_purchase_at'] ?? null);

        $orderId = $order->id;
        Http::assertSent(function ($req) use ($orderId) {
            $data = $req->data();
            $evt = $data['data'][0] ?? [];
            $ud = $evt['user_data'] ?? [];

            return ($evt['event_id'] ?? '') === 'order:'.$orderId
                && isset($ud['fbp']) && isset($ud['fbc']);
        });
    }
}
