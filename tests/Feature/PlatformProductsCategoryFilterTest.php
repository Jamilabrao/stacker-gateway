<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformProductsCategoryFilterTest extends TestCase
{
    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function seller(): User
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'password' => Hash::make('password'),
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }

    public function test_admin_products_index_includes_category_label(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();

        $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso de IA',
            'checkout_slug' => 'curso-ia-cat',
            'category' => 'tecnologia_e_inovacao',
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.produtos.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Products/Index')
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Curso de IA')
                ->where('products.data.0.category', 'tecnologia_e_inovacao')
                ->where('products.data.0.category_label', 'Tecnologia e Inovação')
                ->has('categories')
            );
    }

    public function test_admin_can_filter_products_by_category(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();

        $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso Tech',
            'checkout_slug' => 'curso-tech-cat',
            'category' => 'tecnologia_e_inovacao',
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
        ]);
        $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso Saúde',
            'checkout_slug' => 'curso-saude-cat',
            'category' => 'saude_e_bem_estar',
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.produtos.index', ['category' => 'saude_e_bem_estar']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Curso Saúde')
                ->where('filters.category', 'saude_e_bem_estar')
            );
    }

    public function test_admin_can_filter_uncategorized_products(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();

        $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Sem Categoria',
            'checkout_slug' => 'sem-cat-prod',
            'category' => null,
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
        ]);
        $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Com Categoria',
            'checkout_slug' => 'com-cat-prod',
            'category' => 'outros',
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.produtos.index', ['category' => 'uncategorized']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Sem Categoria')
            );
    }

    public function test_merchant_products_tab_filters_by_category(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();

        $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Finanças Seller',
            'checkout_slug' => 'fin-seller-cat',
            'category' => 'financas_e_investimentos',
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
        ]);
        $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Outros Seller',
            'checkout_slug' => 'out-seller-cat',
            'category' => 'outros',
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', [
                'user' => $seller,
                'tab' => 'products',
                'products_category' => 'financas_e_investimentos',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Finanças Seller')
                ->where('products.data.0.category_label', 'Finanças e Investimentos')
            );
    }
}

