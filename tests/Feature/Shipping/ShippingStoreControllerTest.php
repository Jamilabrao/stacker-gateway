<?php

namespace Tests\Feature\Shipping;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsurePhysicalProductsEnabled;
use App\Models\Setting;
use App\Models\ShippingStore;
use App\Models\User;
use Tests\TestCase;

class ShippingStoreControllerTest extends TestCase
{
    public function test_seller_can_create_store_and_other_tenant_cannot_update(): void
    {
        Setting::set('physical_products_enabled', '1', null);

        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsurePhysicalProductsEnabled::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);
        $other = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 2,
        ]);

        $this->actingAs($seller)
            ->postJson('/frete/lojas', [
                'name' => 'Centro SP',
                'is_active' => true,
                'origin_zip' => '01310100',
                'origin_street' => 'Av Paulista',
                'origin_number' => '100',
                'origin_neighborhood' => 'Bela Vista',
                'origin_city' => 'São Paulo',
                'origin_state' => 'SP',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $store = ShippingStore::where('tenant_id', 1)->first();
        $this->assertNotNull($store);

        $this->actingAs($other)
            ->putJson('/frete/lojas/'.$store->id, ['name' => 'Hack'])
            ->assertForbidden();
    }
}
