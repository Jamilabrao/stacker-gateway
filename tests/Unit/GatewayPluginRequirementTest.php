<?php

namespace Tests\Unit;

use App\Plugins\PluginRegistry;
use App\Support\GatewayPluginRequirement;
use Tests\TestCase;

class GatewayPluginRequirementTest extends TestCase
{
    public function test_linaopenx_requires_plugin_and_is_locked_without_active_plugin(): void
    {
        $this->assertSame('linaopenx', GatewayPluginRequirement::requiredPluginSlug(
            config('gateways.gateways.linaopenx')
        ));
        // Plugin folder exists in the repo but is not registered/enabled by default.
        $this->assertFalse(PluginRegistry::isActive('linaopenx'));
        $this->assertFalse(GatewayPluginRequirement::isUnlocked('linaopenx'));
        $this->assertStringContainsString('plugin', strtolower(GatewayPluginRequirement::lockedUserMessage('linaopenx')));
    }

    public function test_non_plugin_gateway_is_unlocked(): void
    {
        $this->assertTrue(GatewayPluginRequirement::isUnlocked('efi'));
        $this->assertTrue(GatewayPluginRequirement::isUnlocked('pagarme'));
    }

    public function test_ui_props_mark_lina_as_locked(): void
    {
        $def = config('gateways.gateways.linaopenx');
        $this->assertIsArray($def);
        $props = GatewayPluginRequirement::uiPropsForDefinition($def);
        $this->assertTrue($props['plugin_locked']);
        $this->assertSame('linaopenx', $props['requires_plugin']);
        $this->assertNotEmpty($props['plugin_locked_message']);
    }
}
