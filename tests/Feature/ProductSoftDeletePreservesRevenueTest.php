<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\SalesAchievementsService;
use Tests\TestCase;

class ProductSoftDeletePreservesRevenueTest extends TestCase
{
    public function test_deleting_product_preserves_completed_orders_for_sales_race(): void
    {
        $tenantId = 42;
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => $tenantId]);

        $product = $this->createTestProduct([
            'tenant_id' => $tenantId,
            'type' => Product::TYPE_LINK,
            'checkout_config' => ['deliverable_link' => 'https://example.com'],
        ]);

        Order::create([
            'tenant_id' => $tenantId,
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 150.50,
            'email' => 'buyer@test.com',
            'gateway' => 'spacepag',
            'gateway_id' => 'tx-1',
            'approved_manually' => false,
        ]);

        $service = app(SalesAchievementsService::class);
        $before = $service->getValidSalesTotal($tenantId);
        $this->assertEqualsWithDelta(150.50, $before, 0.01);

        $product->delete();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseHas('orders', [
            'product_id' => $product->id,
            'status' => 'completed',
        ]);

        $after = $service->getValidSalesTotal($tenantId);
        $this->assertEqualsWithDelta(150.50, $after, 0.01);
    }
}
