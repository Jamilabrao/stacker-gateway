<?php

namespace Tests\Unit;

use App\Models\MedDispute;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\Med\MedPolicyService;
use Tests\TestCase;

class MedPolicyServiceTest extends TestCase
{
    private MedPolicyService $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = app(MedPolicyService::class);
    }

    public function test_checkout_order_is_platform_managed(): void
    {
        $order = new Order([
            'tenant_id' => 1,
            'payment_method' => 'pix',
            'metadata' => [],
        ]);

        $this->assertFalse($this->policy->isApiPixRestOrder($order));
        $this->assertSame(MedDispute::PARTY_PLATFORM, $this->policy->responsiblePartyForOrder($order));
        $this->assertFalse($this->policy->shouldHoldTenantBalance(new MedDispute(['responsible_party' => MedDispute::PARTY_PLATFORM])));
    }

    public function test_api_pix_rest_order_is_tenant_managed_without_med_zero(): void
    {
        $order = new Order([
            'tenant_id' => 5,
            'api_application_id' => 10,
            'payment_method' => 'pix',
            'metadata' => ['source' => 'api'],
        ]);

        $this->assertTrue($this->policy->isApiPixRestOrder($order));
        $this->assertSame(MedDispute::PARTY_TENANT, $this->policy->responsiblePartyForOrder($order));
    }

    public function test_api_pix_with_med_zero_is_platform_managed(): void
    {
        Setting::set(MedPolicyService::SETTING_MED_ZERO, true, 7);

        $order = new Order([
            'tenant_id' => 7,
            'api_application_id' => 10,
            'payment_method' => 'pix',
            'metadata' => ['source' => 'api'],
        ]);

        $this->assertTrue($this->policy->medZeroForTenant(7));
        $this->assertSame(MedDispute::PARTY_PLATFORM, $this->policy->responsiblePartyForOrder($order));
    }

    public function test_set_med_zero_clears_setting_when_disabled(): void
    {
        $this->policy->setMedZeroForTenant(9, true);
        $this->assertTrue($this->policy->medZeroForTenant(9));

        $this->policy->setMedZeroForTenant(9, false);
        $this->assertFalse($this->policy->medZeroForTenant(9));
    }
}
