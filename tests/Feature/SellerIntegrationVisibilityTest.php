<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\SellerIntegrationVisibility;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SellerIntegrationVisibilityTest extends TestCase
{
    private function makeSeller(): User
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        return $seller->fresh();
    }

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    public function test_all_integrations_are_visible_by_default(): void
    {
        foreach (SellerIntegrationVisibility::ids() as $id) {
            $this->assertTrue(SellerIntegrationVisibility::globalEnabled($id));
        }

        $seller = $this->makeSeller();
        $this->assertSame(
            SellerIntegrationVisibility::ids(),
            SellerIntegrationVisibility::visibleIdsForTenant((int) $seller->id)
        );
    }

    public function test_admin_can_hide_integration_globally(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->put('/plataforma/configuracoes', [
                'integration_utmify_enabled' => false,
                'integration_webhook_enabled' => true,
                'integration_spedy_enabled' => true,
                'integration_cademi_enabled' => true,
            ])
            ->assertRedirect();

        $this->assertFalse(SellerIntegrationVisibility::globalEnabled(SellerIntegrationVisibility::UTMIFY));
        $this->assertTrue(SellerIntegrationVisibility::globalEnabled(SellerIntegrationVisibility::WEBHOOK));
    }

    public function test_seller_integrations_page_omits_globally_hidden_apps(): void
    {
        SellerIntegrationVisibility::setGlobal(SellerIntegrationVisibility::UTMIFY, false);
        SellerIntegrationVisibility::setGlobal(SellerIntegrationVisibility::SPEDY, false);

        $seller = $this->makeSeller();

        $this->actingAs($seller)
            ->get(route('integrations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Integrations/Index')
                ->where('visible_integrations', [
                    SellerIntegrationVisibility::WEBHOOK,
                    SellerIntegrationVisibility::CADEMI,
                ])
            );
    }

    public function test_seller_cannot_configure_hidden_integration(): void
    {
        SellerIntegrationVisibility::setGlobal(SellerIntegrationVisibility::UTMIFY, false);

        $seller = $this->makeSeller();

        $this->actingAs($seller)
            ->postJson(route('integrations.utmify.store'), [
                'name' => 'UTMIFY teste',
                'api_key' => 'utm_test_key',
            ])
            ->assertForbidden();
    }

    public function test_merchant_override_can_enable_even_when_global_is_off(): void
    {
        SellerIntegrationVisibility::setGlobal(SellerIntegrationVisibility::UTMIFY, false);

        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();
        $tenantId = (int) $seller->id;

        $this->assertFalse(SellerIntegrationVisibility::effectiveForTenant(SellerIntegrationVisibility::UTMIFY, $tenantId));

        $this->actingAs($admin)->put(route('plataforma.usuarios.update', $seller), [
            'name' => $seller->name,
            'email' => $seller->email,
            'integration_modes' => [
                'utmify' => SellerIntegrationVisibility::MODE_ENABLED,
            ],
        ])->assertRedirect();

        $this->assertTrue(SellerIntegrationVisibility::effectiveForTenant(SellerIntegrationVisibility::UTMIFY, $tenantId));
        $this->assertSame(
            SellerIntegrationVisibility::MODE_ENABLED,
            SellerIntegrationVisibility::tenantMode(SellerIntegrationVisibility::UTMIFY, $tenantId)
        );

        $this->actingAs($seller)
            ->get(route('integrations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('visible_integrations', fn ($ids) => in_array(SellerIntegrationVisibility::UTMIFY, $ids, true))
            );

        $this->actingAs($seller)
            ->postJson(route('integrations.utmify.store'), [
                'name' => 'UTMIFY override',
                'api_key' => 'utm_override_key',
            ])
            ->assertCreated();
    }

    public function test_merchant_override_can_disable_even_when_global_is_on(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();
        $tenantId = (int) $seller->id;

        $this->actingAs($admin)->put(route('plataforma.usuarios.update', $seller), [
            'name' => $seller->name,
            'email' => $seller->email,
            'integration_modes' => [
                'cademi' => SellerIntegrationVisibility::MODE_DISABLED,
            ],
        ])->assertRedirect();

        $this->assertFalse(SellerIntegrationVisibility::effectiveForTenant(SellerIntegrationVisibility::CADEMI, $tenantId));
        $this->assertTrue(SellerIntegrationVisibility::globalEnabled(SellerIntegrationVisibility::CADEMI));

        $this->actingAs($seller)
            ->get(route('integrations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('visible_integrations', fn ($ids) => ! in_array(SellerIntegrationVisibility::CADEMI, $ids, true))
            );
    }

    public function test_inherit_clears_override_and_follows_global(): void
    {
        $seller = $this->makeSeller();
        $tenantId = (int) $seller->id;
        $admin = $this->makeAdmin();

        SellerIntegrationVisibility::setGlobal(SellerIntegrationVisibility::SPEDY, false);
        SellerIntegrationVisibility::setTenantMode(
            SellerIntegrationVisibility::SPEDY,
            $tenantId,
            SellerIntegrationVisibility::MODE_ENABLED
        );

        $this->assertTrue(SellerIntegrationVisibility::effectiveForTenant(SellerIntegrationVisibility::SPEDY, $tenantId));

        $this->actingAs($admin)->put(route('plataforma.usuarios.update', $seller), [
            'name' => $seller->name,
            'email' => $seller->email,
            'integration_modes' => [
                'spedy' => SellerIntegrationVisibility::MODE_INHERIT,
            ],
        ])->assertRedirect();

        $this->assertSame(
            SellerIntegrationVisibility::MODE_INHERIT,
            SellerIntegrationVisibility::tenantMode(SellerIntegrationVisibility::SPEDY, $tenantId)
        );
        $this->assertFalse(SellerIntegrationVisibility::effectiveForTenant(SellerIntegrationVisibility::SPEDY, $tenantId));
        $this->assertNull(
            Setting::query()
                ->where('key', SellerIntegrationVisibility::settingKey(SellerIntegrationVisibility::SPEDY))
                ->where('tenant_id', $tenantId)
                ->first()
        );
    }

    public function test_edit_merchant_exposes_integration_modes(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();
        SellerIntegrationVisibility::setTenantMode(
            SellerIntegrationVisibility::WEBHOOK,
            (int) $seller->id,
            SellerIntegrationVisibility::MODE_DISABLED
        );

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.edit', $seller))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Users/Edit')
                ->where('merchant.integration_modes.webhook', SellerIntegrationVisibility::MODE_DISABLED)
                ->where('merchant.integration_modes.utmify', SellerIntegrationVisibility::MODE_INHERIT)
                ->has('platform_integrations')
                ->has('platform_integrations_enabled')
            );
    }

    public function test_settings_page_includes_integrations_catalog(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get('/plataforma/configuracoes?tab=integracoes')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Index')
                ->has('seller_integrations_catalog', 4)
                ->where('settings.integration_webhook_enabled', true)
            );
    }
}
