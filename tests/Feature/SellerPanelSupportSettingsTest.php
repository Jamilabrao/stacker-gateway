<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\SellerPanelSupportSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SellerPanelSupportSettingsTest extends TestCase
{
    public function test_default_is_disabled_without_href(): void
    {
        foreach ([
            'seller_panel_support_enabled',
            'seller_panel_support_destination',
            'seller_panel_support_whatsapp',
            'seller_panel_support_url',
            'seller_panel_support_icon',
            'seller_panel_support_icon_image',
            'seller_panel_support_color',
        ] as $key) {
            Setting::query()->where('key', $key)->delete();
        }

        $config = SellerPanelSupportSettings::publicConfig();

        $this->assertFalse($config['enabled']);
        $this->assertNull($config['href']);
    }

    public function test_whatsapp_number_builds_wa_me_url(): void
    {
        Setting::set('seller_panel_support_enabled', '1', null);
        Setting::set('seller_panel_support_destination', 'whatsapp', null);
        Setting::set('seller_panel_support_whatsapp', '5511999887766', null);

        $config = SellerPanelSupportSettings::publicConfig();

        $this->assertTrue($config['enabled']);
        $this->assertSame('https://wa.me/5511999887766', $config['href']);
    }

    public function test_custom_url_is_sanitized(): void
    {
        Setting::set('seller_panel_support_enabled', '1', null);
        Setting::set('seller_panel_support_destination', 'url', null);
        Setting::set('seller_panel_support_url', 'javascript:alert(1)', null);

        $config = SellerPanelSupportSettings::publicConfig();

        $this->assertFalse($config['enabled']);
        $this->assertNull($config['href']);
    }

    public function test_valid_custom_url_is_used(): void
    {
        Setting::set('seller_panel_support_enabled', '1', null);
        Setting::set('seller_panel_support_destination', 'url', null);
        Setting::set('seller_panel_support_url', 'https://example.com/suporte', null);

        $config = SellerPanelSupportSettings::publicConfig();

        $this->assertTrue($config['enabled']);
        $this->assertSame('https://example.com/suporte', $config['href']);
    }

    public function test_enabled_without_destination_stays_disabled(): void
    {
        Setting::set('seller_panel_support_enabled', '1', null);
        Setting::set('seller_panel_support_destination', 'whatsapp', null);
        Setting::set('seller_panel_support_whatsapp', '', null);

        $this->assertFalse(SellerPanelSupportSettings::publicConfig()['enabled']);
    }

    public function test_custom_icon_accepts_storage_path_url(): void
    {
        Setting::set('seller_panel_support_enabled', '1', null);
        Setting::set('seller_panel_support_destination', 'whatsapp', null);
        Setting::set('seller_panel_support_whatsapp', '5511999887766', null);
        Setting::set('seller_panel_support_icon', 'custom', null);
        Setting::set('seller_panel_support_icon_image', '/storage/seller-panel-support/icon.png', null);

        $config = SellerPanelSupportSettings::publicConfig();

        $this->assertTrue($config['enabled']);
        $this->assertSame('custom', $config['icon']);
        $this->assertStringContainsString('/storage/seller-panel-support/icon.png', (string) $config['icon_url']);
    }

    public function test_is_enabled_accepts_legacy_truthy_values(): void
    {
        Setting::set('seller_panel_support_enabled', true, null);
        $this->assertTrue(SellerPanelSupportSettings::isEnabled());

        Setting::set('seller_panel_support_enabled', 'true', null);
        $this->assertTrue(SellerPanelSupportSettings::isEnabled());
    }

    public function test_platform_admin_can_save_settings(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->put('/plataforma/configuracoes', [
                'seller_panel_support_enabled' => true,
                'seller_panel_support_destination' => 'whatsapp',
                'seller_panel_support_whatsapp' => '1199887766',
                'seller_panel_support_icon' => 'whatsapp',
                'seller_panel_support_color' => '#25D366',
            ])
            ->assertRedirect();

        $this->assertSame('551199887766', SellerPanelSupportSettings::whatsappNumber());
        $this->assertTrue(SellerPanelSupportSettings::isEnabled());
    }

    public function test_seller_receives_shared_prop_when_enabled(): void
    {
        Setting::set('seller_panel_support_enabled', '1', null);
        Setting::set('seller_panel_support_destination', 'whatsapp', null);
        Setting::set('seller_panel_support_whatsapp', '5511999887766', null);

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
            'kyc_status' => User::KYC_NOT_SUBMITTED,
            'account_status' => 'pending',
        ]);

        $this->actingAs($seller)
            ->get('/financeiro?tab=seus-dados')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('seller_panel_support.enabled', true)
                ->where('seller_panel_support.href', 'https://wa.me/5511999887766')
            );
    }

    public function test_platform_admin_does_not_receive_seller_shared_prop(): void
    {
        Setting::set('seller_panel_support_enabled', '1', null);
        Setting::set('seller_panel_support_destination', 'whatsapp', null);
        Setting::set('seller_panel_support_whatsapp', '5511999887766', null);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->get('/plataforma/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('seller_panel_support', null));
    }

    public function test_icon_upload_and_clear(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $file = UploadedFile::fake()->image('icon.png', 64, 64);

        $this->actingAs($admin)
            ->post('/plataforma/configuracoes/suporte-painel/upload', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNotSame('', SellerPanelSupportSettings::iconImageUrl());
        $this->assertSame('custom', SellerPanelSupportSettings::icon());

        $this->actingAs($admin)
            ->post('/plataforma/configuracoes/suporte-painel/clear-icon')
            ->assertOk();

        $this->assertSame('', SellerPanelSupportSettings::iconImageUrl());
    }
}
