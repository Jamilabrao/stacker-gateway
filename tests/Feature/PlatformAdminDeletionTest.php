<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class PlatformAdminDeletionTest extends TestCase
{
    public function test_platform_admin_can_block_product_and_checkout_returns_not_found(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'is_active' => true,
            'admin_blocked' => false,
            'checkout_slug' => 'blkprd1',
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->post(route('plataforma.produtos.bloqueio', $product), ['admin_blocked' => true])
            ->assertRedirect();

        $product->refresh();
        $this->assertTrue($product->admin_blocked);
        $this->assertFalse($product->isAvailableForPurchase());

        $this->get(route('checkout.show', ['slug' => 'blkprd1']))->assertNotFound();
    }

    public function test_platform_admin_can_delete_product(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $productId = $product->id;

        $this->actingAs($admin)
            ->delete(route('plataforma.produtos.destroy', $product))
            ->assertRedirect(route('plataforma.produtos.index'));

        $this->assertNull(Product::find($productId));
    }

    public function test_platform_admin_can_delete_cancelled_order(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'cancelled',
            'amount' => 10.00,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'metadata' => [],
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $orderId = $order->id;

        $this->actingAs($admin)
            ->delete(route('plataforma.transacoes.pedidos.destroy', $order))
            ->assertRedirect(route('plataforma.transacoes.index'));

        $this->assertNull(Order::find($orderId));
    }

    public function test_cannot_delete_completed_order_without_refund(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10.00,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'metadata' => [],
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->delete(route('plataforma.transacoes.pedidos.destroy', $order))
            ->assertRedirect(route('plataforma.transacoes.index'))
            ->assertSessionHas('error');

        $this->assertNotNull(Order::find($order->id));
    }
}
