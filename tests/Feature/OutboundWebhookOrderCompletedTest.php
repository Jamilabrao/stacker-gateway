<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboundWebhookOrderCompletedTest extends TestCase
{
    public function test_order_completed_webhook_dispatches_with_redis_queue_and_fresh_heartbeat(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        Config::set('queue.default', 'redis');
        Cache::put('queue_heartbeat', now()->toIso8601String(), now()->addMinutes(5));

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['type' => Product::TYPE_LINK, 'checkout_slug' => 'slug-out']);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'outbound@test.com',
        ]);

        Webhook::create([
            'tenant_id' => 1,
            'name' => 'CRM',
            'url' => 'https://example.com/hook',
            'events' => [OrderCompleted::class],
            'is_active' => true,
        ]);

        event(new OrderCompleted($order));

        Http::assertSent(function ($request) use ($order) {
            $body = json_decode($request->body(), true);

            return ($body['event'] ?? '') === 'pedido_pago'
                && ($body['payload']['order']['id'] ?? null) === $order->id;
        });
    }
}
