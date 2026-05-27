<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class ProductPricePersistenceTest extends TestCase
{
    public function test_product_update_preserves_brl_price_for_eur_currency(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'price' => 16.00,
            'currency' => 'EUR',
            'billing_type' => Product::BILLING_ONE_TIME,
        ]);

        $response = $this->actingAs($seller)->put(route('produtos.update', $product->id), [
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'type' => $product->type,
            'billing_type' => $product->billing_type,
            'price' => 100,
            'currency' => 'EUR',
            'is_active' => true,
        ]);

        $response->assertRedirect();

        $product->refresh();
        $this->assertSame('EUR', $product->currency);
        $this->assertEqualsWithDelta(16.0, (float) $product->price, 0.001);
    }

    public function test_product_update_keeps_brl_price_unchanged(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'price' => 97.90,
            'currency' => 'BRL',
        ]);

        $response = $this->actingAs($seller)->put(route('produtos.update', $product->id), [
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'type' => $product->type,
            'billing_type' => $product->billing_type,
            'price' => 97.90,
            'currency' => 'BRL',
            'is_active' => true,
        ]);

        $response->assertRedirect();

        $product->refresh();
        $this->assertEqualsWithDelta(97.90, (float) $product->price, 0.001);
    }
}
