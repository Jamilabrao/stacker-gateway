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

        Product::query()->create([
            'tenant_id' => $seller->id,
            'name' => '<img src=x onerror=alert(1)>',
            'slug' => 'xss-prod',
            'description' => '<script>alert(1)</script>',
            'type' => Product::TYPE_LINK,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 10,
            'currency' => 'BRL',
            'is_active' => true,
        ]);

        $this->actingAs($seller);
        $res = $this->get('/produtos');

        $res->assertOk();
        // O conteúdo pode aparecer em JSON do Inertia, mas não deve executar no client; aqui garantimos que não
        // renderizamos HTML direto em server-side templates (Blade).
        $this->assertStringNotContainsString('<script>', $res->getContent());
    }
}

