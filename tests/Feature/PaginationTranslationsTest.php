<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaginationTranslationsTest extends TestCase
{
    public function test_products_pagination_uses_translated_labels(): void
    {
        if (! Schema::hasTable('products')) {
            $this->markTestSkipped('products table');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        for ($i = 0; $i < 21; $i++) {
            $this->createTestProduct([
                'tenant_id' => $seller->id,
                'name' => 'Produto '.$i,
                'slug' => 'produto-'.$i.'-'.uniqid(),
            ]);
        }

        $this->actingAs($seller);
        $this->withSession(['panel_locale' => 'pt_BR']);

        $response = $this->get('/produtos');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Produtos/Index'));

        $labels = collect($response->original->getData()['page']['props']['produtos']['links'] ?? [])
            ->pluck('label')
            ->all();

        $this->assertContains('Anterior', $labels);
        $this->assertContains('Próximo', $labels);
        $this->assertNotContains('pagination.previous', $labels);
        $this->assertNotContains('pagination.next', $labels);
    }
}
