<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\PhysicalProductAccess;
use Tests\TestCase;

class PhysicalProductAccessTest extends TestCase
{
    public function test_global_enabled_defaults_to_false(): void
    {
        $this->assertFalse(PhysicalProductAccess::globalEnabled());
    }

    public function test_settings_toggle_persists_physical_products_flag(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN]);

        $this->actingAs($admin)
            ->put('/plataforma/configuracoes', [
                'physical_products_enabled' => true,
            ])
            ->assertRedirect();

        $this->assertTrue(PhysicalProductAccess::globalEnabled());

        $this->actingAs($admin)
            ->put('/plataforma/configuracoes', [
                'physical_products_enabled' => false,
            ])
            ->assertRedirect();

        $this->assertFalse(PhysicalProductAccess::globalEnabled());
    }

    public function test_filter_type_config_removes_physical_when_disabled(): void
    {
        Setting::set(PhysicalProductAccess::SETTING_KEY, '0', null);

        $filtered = PhysicalProductAccess::filterTypeConfig(Product::typeConfig());

        $this->assertArrayNotHasKey(Product::TYPE_PRODUTO_FISICO, $filtered);
    }

    public function test_infoprodutor_cannot_create_physical_product_when_disabled(): void
    {
        Setting::set(PhysicalProductAccess::SETTING_KEY, '0', null);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $this->actingAs($seller)
            ->post('/produtos', [
                'name' => 'Caixa',
                'type' => Product::TYPE_PRODUTO_FISICO,
                'billing_type' => Product::BILLING_ONE_TIME,
                'price' => 99,
            ])
            ->assertSessionHasErrors('type');
    }

    public function test_frete_routes_return_404_when_disabled(): void
    {
        Setting::set(PhysicalProductAccess::SETTING_KEY, '0', null);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $this->actingAs($seller)
            ->get('/frete')
            ->assertNotFound();
    }

    public function test_inertia_shares_physical_products_enabled_effective_for_seller(): void
    {
        Setting::set(PhysicalProductAccess::SETTING_KEY, '1', null);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $this->actingAs($seller)
            ->get('/produtos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('physical_products_enabled_effective', true));
    }
}
