<?php

namespace Tests\Feature\Meta;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Order;
use App\Models\User;
use Tests\TestCase;

class PurchasePixelAckTest extends TestCase
{
    public function test_purchase_pixel_ack_stores_metadata(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => 'buyer@test.com',
            'payment_method' => 'pix',
            'metadata' => [],
        ]);

        $this->post('/checkout/pixel/purchase-ack', [
            'order_id' => $order->id,
            'token' => 'abc',
            'trigger_type' => 'pix',
        ])->assertOk()->assertJson(['ok' => true]);

        $order->refresh();
        $this->assertNotEmpty($order->metadata['browser_purchase_ack_at'] ?? null);
        $this->assertSame('pix', $order->metadata['browser_purchase_ack_trigger'] ?? null);
    }
}
