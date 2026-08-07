<?php

namespace Tests\Unit;

use App\Gateways\GatewayRegistry;
use App\Gateways\LinaOpenx\LinaOpenxDriver;
use App\Services\PlatformPaymentMethods;
use Tests\TestCase;

class LinaOpenxGatewayConfigTest extends TestCase
{
    public function test_linaopenx_is_registered_as_acquirer(): void
    {
        $this->assertTrue(GatewayRegistry::isAllowedAcquirer('linaopenx'));
        $def = GatewayRegistry::get('linaopenx');
        $this->assertNotNull($def);
        $this->assertContains('open_finance', $def['methods'] ?? []);
        $driver = GatewayRegistry::driver('linaopenx');
        $this->assertInstanceOf(LinaOpenxDriver::class, $driver);
    }

    public function test_open_finance_is_platform_method_key(): void
    {
        $this->assertContains('open_finance', PlatformPaymentMethods::METHOD_KEYS);
        $labels = collect(PlatformPaymentMethods::labelsForAdmin())->pluck('key')->all();
        $this->assertContains('open_finance', $labels);
    }

    public function test_default_order_includes_open_finance(): void
    {
        $order = config('gateways.default_order.open_finance');
        $this->assertIsArray($order);
        $this->assertContains('linaopenx', $order);
    }
}
