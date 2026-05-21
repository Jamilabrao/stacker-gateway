<?php

namespace Tests\Feature\Meta;

use App\Http\Middleware\EnsureInstalled;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchasePixelAckTest extends TestCase
{
    public function test_purchase_pixel_ack_stores_metadata(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct();

        $sessionToken = (string) Str::uuid();
        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => 'ack-test',
            'session_token' => $sessionToken,
            'step' => CheckoutSession::STEP_CONVERTED,
            'customer_ip' => '127.0.0.1',
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => 'buyer@test.com',
            'payment_method' => 'pix',
            'metadata' => [],
        ]);

        $this->postJson('/checkout/pixel/purchase-ack', [
            'order_id' => $order->id,
            'token' => 'abc',
            'trigger_type' => 'pix',
        ])->assertStatus(422);

        $this->postJson('/checkout/pixel/purchase-ack', [
            'order_id' => $order->id,
            'checkout_session_token' => $sessionToken,
            'token' => 'abc',
            'trigger_type' => 'pix',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->postJson('/checkout/pixel/purchase-ack', [
            'order_id' => $order->id,
            'checkout_session_token' => 'invalid-token',
            'trigger_type' => 'pix',
        ])->assertStatus(403);

        $order->refresh();
        $this->assertNotEmpty($order->metadata['browser_purchase_ack_at'] ?? null);
        $this->assertSame('pix', $order->metadata['browser_purchase_ack_trigger'] ?? null);
    }
}
