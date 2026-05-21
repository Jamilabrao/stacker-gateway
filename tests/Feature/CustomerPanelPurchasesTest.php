<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPanelPurchasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_painel_cliente_lists_main_product_and_order_bumps(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $buyer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => 1,
            'email' => 'buyer@test.com',
        ]);

        $main = $this->createTestProduct(['name' => 'Curso principal', 'tenant_id' => 1]);
        $bump = $this->createTestProduct(['name' => 'Bump extra', 'tenant_id' => 1]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $main->id,
            'status' => 'completed',
            'amount' => 150,
            'email' => $buyer->email,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $main->id,
            'amount' => 100,
            'position' => 0,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $bump->id,
            'amount' => 50,
            'position' => 1,
        ]);

        $response = $this->actingAs($buyer)->get('/painel-cliente');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Cliente/Index')
            ->has('purchases', 2)
            ->where('purchases.0.product_name', 'Curso principal')
            ->where('purchases.0.is_order_bump', false)
            ->where('purchases.1.product_name', 'Bump extra')
            ->where('purchases.1.is_order_bump', true)
        );
    }
}
