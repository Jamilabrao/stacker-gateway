<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Listeners\IncrementCouponUsageOnOrderCompleted;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CouponCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_max_uses_is_persisted_on_create(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $this->actingAs($seller)
            ->post('/produtos/cupons', [
                'code' => 'LIMITE10',
                'type' => 'percent',
                'value' => 10,
                'product_ids' => [(string) $product->id],
                'max_uses' => 5,
                'is_active' => true,
            ])
            ->assertRedirect();

        $coupon = Coupon::query()->where('code', 'LIMITE10')->first();
        $this->assertNotNull($coupon);
        $this->assertSame(5, $coupon->max_uses);
    }

    public function test_coupon_without_products_applies_to_any_tenant_product(): void
    {
        $product = $this->createTestProduct(['tenant_id' => 1, 'price' => 100]);

        $coupon = Coupon::create([
            'tenant_id' => 1,
            'code' => 'GLOBAL',
            'type' => Coupon::TYPE_PERCENT,
            'value' => 20,
            'max_uses' => 2,
            'used_count' => 0,
            'is_active' => true,
        ]);

        $this->assertTrue($coupon->appliesToProduct($product));

        $result = $coupon->applyTo($product, 100.0);
        $this->assertNotNull($result);
        $this->assertSame(80.0, $result['final_price']);
    }

    public function test_usage_increments_when_order_completes(): void
    {
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $buyer = User::factory()->create(['role' => User::ROLE_CLIENTE, 'tenant_id' => 1]);

        $coupon = Coupon::create([
            'tenant_id' => 1,
            'code' => 'USO1',
            'type' => Coupon::TYPE_FIXED,
            'value' => 10,
            'max_uses' => 3,
            'used_count' => 0,
            'is_active' => true,
        ]);
        $coupon->products()->sync([$product->id]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 90,
            'email' => $buyer->email,
            'coupon_code' => 'USO1',
        ]);

        app(IncrementCouponUsageOnOrderCompleted::class)->handle(new OrderCompleted($order));

        $coupon->refresh();
        $this->assertSame(1, $coupon->used_count);

        app(CouponCheckoutService::class)->recordUsageFromCompletedOrder($order->fresh());
        $coupon->refresh();
        $this->assertSame(1, $coupon->used_count, 'Não deve contar duas vezes o mesmo pedido');
    }

    public function test_apply_or_fail_rejects_when_max_uses_reached(): void
    {
        $product = $this->createTestProduct(['tenant_id' => 1, 'price' => 50]);

        $coupon = Coupon::create([
            'tenant_id' => 1,
            'code' => 'ESGOTADO',
            'type' => Coupon::TYPE_PERCENT,
            'value' => 10,
            'max_uses' => 1,
            'used_count' => 1,
            'is_active' => true,
        ]);
        $coupon->products()->sync([$product->id]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(CouponCheckoutService::class)->applyOrFail($product, 'ESGOTADO', 50.0);
    }
}
