<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Tests\TestCase;

class PlatformUsersSalesTotalTest extends TestCase
{
    public function test_users_index_includes_vendas_totais(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 150.50,
            'gateway' => 'stripe',
            'approved_manually' => false,
            'email' => 'buyer@test.com',
        ]);

        Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 49.50,
            'gateway' => 'manual',
            'approved_manually' => false,
            'email' => 'buyer2@test.com',
        ]);

        $response = $this->actingAs($admin)->get(route('plataforma.usuarios.index'));

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Platform/Users/Index')
            ->has('users.data', 1)
            ->where('users.data.0.id', $seller->id)
            ->where('users.data.0.vendas_totais', 150.5)
        );
    }
}
