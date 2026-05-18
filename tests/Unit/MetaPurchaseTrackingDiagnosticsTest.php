<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Product;
use App\Services\MetaPurchaseTrackingDiagnostics;
use Tests\TestCase;

class MetaPurchaseTrackingDiagnosticsTest extends TestCase
{
    public function test_diagnose_includes_capi_and_browser_flags(): void
    {
        $product = new Product([
            'tenant_id' => 1,
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '111', 'access_token' => 'tok'],
                    ],
                ],
            ],
        ]);

        $order = new Order([
            'tenant_id' => 1,
            'status' => 'completed',
            'amount' => 25,
            'payment_method' => 'pix',
            'metadata' => [
                'fbp' => 'fb.test',
                'meta_capi_sent_purchase' => true,
            ],
        ]);
        $order->id = 42;
        $order->setRelation('product', $product);

        $diag = app(MetaPurchaseTrackingDiagnostics::class)->diagnose($order);

        $this->assertTrue($diag['meta_capi_sent_purchase']);
        $this->assertTrue($diag['has_fbp']);
        $this->assertSame(1, $diag['meta_pixels_ready_for_capi']);
    }
}
