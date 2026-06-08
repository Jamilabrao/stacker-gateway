<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Product;
use PHPUnit\Framework\TestCase;

class OrderSalePushNotificationTest extends TestCase
{
    public function test_sale_approved_push_title_includes_payment_method(): void
    {
        $order = new Order([
            'amount' => 47.00,
            'metadata' => ['checkout_payment_method' => 'card'],
        ]);
        $order->setRelation('product', new Product(['name' => 'curso do joão']));

        $this->assertSame('Venda aprovada (Cartão de crédito)', $order->saleApprovedPushTitle());
        $this->assertSame('curso do joão - R$ 47,00', $order->saleApprovedPushBody());
    }

    public function test_sale_approved_push_uses_pix_label(): void
    {
        $order = new Order([
            'amount' => 19.90,
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);
        $order->setRelation('product', new Product(['name' => 'E-book Premium']));

        $this->assertSame('Venda aprovada (PIX)', $order->saleApprovedPushTitle());
        $this->assertSame('E-book Premium - R$ 19,90', $order->saleApprovedPushBody());
    }

    public function test_payment_method_push_label_falls_back_to_payment_method_column(): void
    {
        $order = new Order([
            'payment_method' => 'credit_card',
            'amount' => 100,
        ]);
        $order->setRelation('product', new Product(['name' => 'Produto X']));

        $this->assertSame('Cartão de crédito', $order->paymentMethodPushLabel());
    }
}
