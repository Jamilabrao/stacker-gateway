<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class XssStoredFieldsDoNotBreakPaginationTest extends TestCase
{
    public function test_products_index_renders_even_with_html_in_name(): void
    {
        if (! Schema::hasTable('products')) {
            $this->markTestSkipped('products table');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => '<img src=x onerror=alert(1)>',
            'slug' => 'xss-prod',
            'description' => '<script>alert(1)</script>',
        ]);

        $this->actingAs($seller);
        $res = $this->get('/produtos');

        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->component('Produtos/Index')
            ->has('produtos.data', 1)
        );
    }
}

