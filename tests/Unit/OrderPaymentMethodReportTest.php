<?php

namespace Tests\Unit;

use App\Models\Order;
use Tests\TestCase;

class OrderPaymentMethodReportTest extends TestCase
{
    public function test_report_key_maps_internal_gateway_to_payment_method(): void
    {
        $order = new Order([
            'gateway' => 'spacepag',
            'payment_method' => null,
            'metadata' => [],
        ]);

        $this->assertSame('pix', $order->paymentMethodReportKey());
        $this->assertSame('PIX', Order::paymentMethodReportLabel('pix'));
    }

    public function test_gateway_slug_display_label_never_exposes_gateway_name(): void
    {
        $this->assertSame('Outro', Order::gatewaySlugDisplayLabel('onlyup'));
        $this->assertSame('Outro', Order::gatewaySlugDisplayLabel('mercadopago'));
    }

    public function test_payment_method_display_label_uses_checkout_method_not_gateway(): void
    {
        $order = new Order([
            'gateway' => 'onlyup',
            'metadata' => ['checkout_payment_method' => 'card'],
        ]);

        $this->assertSame('Cartão', $order->paymentMethodDisplayLabel());
    }
}
