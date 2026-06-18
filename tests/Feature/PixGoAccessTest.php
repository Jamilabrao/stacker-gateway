<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\PixGoAccess;
use Tests\TestCase;

class PixGoAccessTest extends TestCase
{
    public function test_pixgo_disabled_by_default(): void
    {
        Setting::query()->where('key', PixGoAccess::SETTING_ENABLED)->delete();

        $this->assertFalse(PixGoAccess::globalEnabled());
    }

    public function test_pixgo_toggle_and_sidebar_label(): void
    {
        PixGoAccess::setEnabled(true);
        PixGoAccess::setSidebarLabel('CajuGO');

        $this->assertTrue(PixGoAccess::globalEnabled());
        $this->assertSame('CajuGO', PixGoAccess::sidebarLabel());

        PixGoAccess::setSidebarLabel('');
        $this->assertSame(PixGoAccess::DEFAULT_SIDEBAR_LABEL, PixGoAccess::sidebarLabel());
    }

    public function test_platform_admin_can_update_pixgo_settings(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_PLATFORM_ADMIN]);

        $this->actingAs($admin)
            ->put('/plataforma/financeiro/pixgo', [
                'pixgo_enabled' => true,
                'pixgo_sidebar_label' => 'PixGO Pro',
            ])
            ->assertRedirect();

        $this->assertTrue(PixGoAccess::globalEnabled());
        $this->assertSame('PixGO Pro', PixGoAccess::sidebarLabel());
    }
}
