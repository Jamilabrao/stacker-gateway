<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class ProductSlugTest extends TestCase
{
    public function test_unique_slug_considers_soft_deleted_products(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $deleted = $this->createTestProduct([
            'tenant_id' => 2,
            'name' => 'Teste',
            'slug' => 'teste',
            'checkout_slug' => 'del'.substr(uniqid('', true), -6),
        ]);
        $deleted->delete();

        $this->assertSame('teste-2', Product::uniqueSlugForTenant(2, 'teste'));
    }

    public function test_can_create_two_products_with_same_name_via_store(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
        ]);
        $owner->forceFill(['tenant_id' => 2])->save();

        $this->createTestProduct([
            'tenant_id' => 2,
            'name' => 'TESTE',
            'slug' => 'teste',
            'checkout_slug' => 'ex'.substr(uniqid('', true), -6),
        ]);

        $response = $this->actingAs($owner)->post('/produtos', [
            'name' => 'TESTE',
            'description' => 'TESTE',
            'type' => Product::TYPE_AREA_MEMBROS_EXTERNA,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 47,
            'currency' => 'BRL',
            'is_active' => true,
        ]);

        $response->assertRedirect(route('produtos.index'));
        $this->assertDatabaseHas('products', [
            'tenant_id' => 2,
            'name' => 'TESTE',
            'slug' => 'teste-2',
        ]);
    }
}
