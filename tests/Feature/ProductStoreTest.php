<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class ProductStoreTest extends TestCase
{
    public function test_infoprodutor_can_create_product_with_unique_slug(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $this->actingAs($seller)
            ->post('/produtos', [
                'name' => 'Curso Teste',
                'type' => Product::TYPE_AREA_MEMBROS,
                'billing_type' => Product::BILLING_ONE_TIME,
                'price' => 97.5,
                'currency' => 'BRL',
                'is_active' => true,
            ])
            ->assertRedirect(route('produtos.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('products', [
            'tenant_id' => $seller->id,
            'name' => 'Curso Teste',
            'slug' => 'curso-teste',
        ]);

        $this->actingAs($seller)
            ->post('/produtos', [
                'name' => 'Curso Teste',
                'type' => Product::TYPE_LINK,
                'billing_type' => Product::BILLING_ONE_TIME,
                'price' => 50,
            ])
            ->assertRedirect(route('produtos.index'));

        $this->assertDatabaseHas('products', [
            'tenant_id' => $seller->id,
            'slug' => 'curso-teste-1',
        ]);
    }
}
