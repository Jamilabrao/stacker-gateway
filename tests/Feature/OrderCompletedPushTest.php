<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Listeners\SendPanelPushOnOrderCompleted;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PanelNotification;
use App\Models\PanelPushSubscription;
use App\Models\Product;
use App\Services\Push\PanelPushDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Concerns\UsesTestVapidKeys;
use Tests\TestCase;

class OrderCompletedPushTest extends TestCase
{
    use RefreshDatabase;
    use UsesTestVapidKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPushFeatureTests();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_order_completed_sends_sale_approved_push(): void
    {
        if (! Schema::hasTable('panel_push_subscriptions') || ! Schema::hasTable('panel_notifications')) {
            $this->markTestSkipped('panel push tables missing');
        }

        $this->configureTestVapidPush();

        $seller = $this->createSellerUser();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->tenant_id,
            'name' => 'Curso Premium',
        ]);

        PanelPushSubscription::create([
            'user_id' => $seller->id,
            'tenant_id' => $seller->tenant_id,
            'provider' => PanelPushSubscription::PROVIDER_VAPID,
            'endpoint' => 'https://push.example.com/sub/1',
            'keys' => ['auth' => 'dGVzdA', 'p256dh' => 'dGVzdA'],
            'vapid_public_key' => config('getfy.pwa.vapid_public'),
        ]);

        $dispatcher = Mockery::mock(PanelPushDispatcher::class);
        $dispatcher->shouldReceive('send')
            ->once()
            ->withArgs(function ($subscriptions, string $title, string $body, ?string $url, ?string $tag) use ($product) {
                return $subscriptions->count() === 1
                    && $title === 'Venda aprovada (PIX)'
                    && str_contains($body, $product->name)
                    && str_contains((string) $url, '/vendas')
                    && is_string($tag)
                    && str_starts_with($tag, 'sale_');
            })
            ->andReturn(['sent' => 1, 'failed' => 0, 'invalid' => 0, 'expired' => 0, 'total' => 1]);

        $this->app->instance(PanelPushDispatcher::class, $dispatcher);

        $order = Order::query()->create([
            'tenant_id' => $seller->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 97.50,
            'email' => 'buyer@example.com',
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);

        app(SendPanelPushOnOrderCompleted::class)->handle(new OrderCompleted($order->fresh(['product'])));

        $this->assertDatabaseHas('panel_notifications', [
            'user_id' => $seller->id,
            'type' => 'sale_approved',
            'event_key' => 'sale_'.$order->id,
        ]);
    }

    public function test_duplicate_order_completed_skips_second_push_for_same_event_key(): void
    {
        if (! Schema::hasTable('panel_push_subscriptions') || ! Schema::hasTable('panel_notifications')) {
            $this->markTestSkipped('panel push tables missing');
        }

        $this->configureTestVapidPush();

        $seller = $this->createSellerUser();
        $product = $this->createTestProduct(['tenant_id' => $seller->tenant_id]);

        PanelPushSubscription::create([
            'user_id' => $seller->id,
            'tenant_id' => $seller->tenant_id,
            'provider' => PanelPushSubscription::PROVIDER_VAPID,
            'endpoint' => 'https://push.example.com/sub/2',
            'keys' => ['auth' => 'dGVzdA', 'p256dh' => 'dGVzdA'],
            'vapid_public_key' => config('getfy.pwa.vapid_public'),
        ]);

        $order = Order::query()->create([
            'tenant_id' => $seller->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'buyer2@example.com',
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);

        $dispatcher = Mockery::mock(PanelPushDispatcher::class);
        $dispatcher->shouldReceive('send')
            ->once()
            ->andReturn(['sent' => 1, 'failed' => 0, 'invalid' => 0, 'expired' => 0, 'total' => 1]);

        $this->app->instance(PanelPushDispatcher::class, $dispatcher);

        $listener = app(SendPanelPushOnOrderCompleted::class);
        $event = new OrderCompleted($order->fresh(['product']));

        $listener->handle($event);
        $listener->handle($event);

        $this->assertSame(
            1,
            PanelNotification::query()
                ->where('user_id', $seller->id)
                ->where('event_key', 'sale_'.$order->id)
                ->count()
        );
    }

    public function test_order_completed_sends_separate_push_for_order_bump(): void
    {
        if (! Schema::hasTable('panel_push_subscriptions') || ! Schema::hasTable('panel_notifications') || ! Schema::hasTable('order_items')) {
            $this->markTestSkipped('panel push/order_items tables missing');
        }

        $this->configureTestVapidPush();

        $seller = $this->createSellerUser();
        $mainProduct = $this->createTestProduct([
            'tenant_id' => $seller->tenant_id,
            'name' => 'Produto Principal',
        ]);
        $bumpProduct = $this->createTestProduct([
            'tenant_id' => $seller->tenant_id,
            'name' => 'Bump Extra',
            'slug' => 'bump-'.uniqid(),
        ]);

        PanelPushSubscription::create([
            'user_id' => $seller->id,
            'tenant_id' => $seller->tenant_id,
            'provider' => PanelPushSubscription::PROVIDER_VAPID,
            'endpoint' => 'https://push.example.com/sub/bump',
            'keys' => ['auth' => 'dGVzdA', 'p256dh' => 'dGVzdA'],
            'vapid_public_key' => config('getfy.pwa.vapid_public'),
        ]);

        $dispatcher = Mockery::mock(PanelPushDispatcher::class);
        $dispatcher->shouldReceive('send')
            ->twice()
            ->andReturn(['sent' => 1, 'failed' => 0, 'invalid' => 0, 'expired' => 0, 'total' => 1]);

        $this->app->instance(PanelPushDispatcher::class, $dispatcher);

        $order = Order::query()->create([
            'tenant_id' => $seller->tenant_id,
            'product_id' => $mainProduct->id,
            'status' => 'completed',
            'amount' => 150,
            'email' => 'buyer-bump@example.com',
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $mainProduct->id,
            'amount' => 100,
            'position' => 0,
        ]);
        $bumpItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $bumpProduct->id,
            'amount' => 50,
            'position' => 1,
        ]);

        app(SendPanelPushOnOrderCompleted::class)->handle(new OrderCompleted($order->fresh(['product', 'orderItems.product'])));

        $this->assertDatabaseHas('panel_notifications', [
            'user_id' => $seller->id,
            'type' => 'sale_approved',
            'event_key' => 'sale_'.$order->id,
        ]);
        $this->assertDatabaseHas('panel_notifications', [
            'user_id' => $seller->id,
            'type' => 'sale_approved',
            'event_key' => 'sale_'.$order->id.'_bump_'.$bumpItem->id,
        ]);

        $bumpNotification = PanelNotification::query()
            ->where('event_key', 'sale_'.$order->id.'_bump_'.$bumpItem->id)
            ->first();
        $this->assertNotNull($bumpNotification);
        $this->assertStringContainsString('Order bump', (string) $bumpNotification->title);
        $this->assertStringContainsString('Order bump', (string) $bumpNotification->body);
        $this->assertStringContainsString('Bump Extra', (string) $bumpNotification->body);
    }
}
