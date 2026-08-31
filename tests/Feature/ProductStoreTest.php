<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class ProductStoreTest extends TestCase
{
    private function createApprovedSeller(): User
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        return $seller;
    }

    public function test_infoprodutor_can_create_product_with_unique_slug(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class, ValidateCsrfToken::class]);

        $seller = $this->createApprovedSeller();

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
            'slug' => 'curso-teste-2',
        ]);
    }

    public function test_infoprodutor_can_create_product_with_category(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class, ValidateCsrfToken::class]);

        $seller = $this->createApprovedSeller();

        $this->actingAs($seller)
            ->post('/produtos', [
                'name' => 'Curso de Marketing',
                'type' => Product::TYPE_AREA_MEMBROS,
                'billing_type' => Product::BILLING_ONE_TIME,
                'price' => 197,
                'currency' => 'BRL',
                'is_active' => true,
                'category' => 'marketing_e_vendas',
            ])
            ->assertRedirect(route('produtos.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('products', [
            'tenant_id' => $seller->id,
            'name' => 'Curso de Marketing',
            'category' => 'marketing_e_vendas',
        ]);
    }

    public function test_product_category_must_be_from_allowed_list(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class, ValidateCsrfToken::class]);

        $seller = $this->createApprovedSeller();

        $this->actingAs($seller)
            ->post('/produtos', [
                'name' => 'Curso Inválido',
                'type' => Product::TYPE_AREA_MEMBROS,
                'billing_type' => Product::BILLING_ONE_TIME,
                'price' => 50,
                'category' => 'categoria-invalida',
            ])
            ->assertSessionHasErrors('category');
    }
}
