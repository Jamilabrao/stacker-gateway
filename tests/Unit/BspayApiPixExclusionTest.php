<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\PaymentService;
use Tests\TestCase;

class BspayApiPixExclusionTest extends TestCase
{
    public function test_bspay_is_excluded_from_api_pix_but_allowed_on_checkout(): void
    {
        $service = app(PaymentService::class);

        $apiOrder = new Order(['metadata' => ['source' => 'api']]);
        $checkoutOrder = new Order(['metadata' => []]);
        $pixGoOrder = new Order(['metadata' => ['source' => 'pixgo']]);

        $this->assertFalse($service->isPixAcquirerAllowedForOrder('bspay', $apiOrder));
        $this->assertTrue($service->isPixAcquirerAllowedForOrder('cajupay', $apiOrder));
        $this->assertTrue($service->isPixAcquirerAllowedForOrder('woovi', $apiOrder));
        $this->assertTrue($service->isPixAcquirerAllowedForOrder('bspay', $checkoutOrder));
        $this->assertTrue($service->isPixAcquirerAllowedForOrder('bspay', $pixGoOrder));
    }
}
