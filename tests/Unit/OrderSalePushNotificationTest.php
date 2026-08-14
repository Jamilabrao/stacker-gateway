<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use PHPUnit\Framework\TestCase;

class OrderSalePushNotificationTest extends TestCase
{
    public function test_sale_approved_push_title_includes_payment_method(): void
    {
        $order = new Order([
            'amount' => 47.00,
            'tenant_id' => 1,
            'metadata' => ['checkout_payment_method' => 'card'],
        ]);
        $order->setRelation('product', new Product(['name' => 'curso do joão']));

        $this->assertSame('Venda aprovada (Cartão de crédito)', $order->saleApprovedPushTitle());
        $this->assertSame(
            "Produto: curso do joão\nValor: R$ 47,00\nPagamento: Cartão de crédito",
            $order->saleApprovedPushBody()
        );
    }

    public function test_sale_approved_push_uses_pix_label(): void
    {
        $order = new Order([
            'amount' => 19.90,
            'tenant_id' => 1,
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);
        $order->setRelation('product', new Product(['name' => 'E-book Premium']));

        $this->assertSame('Venda aprovada (PIX)', $order->saleApprovedPushTitle());
        $this->assertSame(
            "Produto: E-book Premium\nValor: R$ 19,90\nPagamento: PIX",
            $order->saleApprovedPushBody()
        );
    }

    public function test_sale_approved_push_uses_notification_name_when_set(): void
    {
        $order = new Order([
            'amount' => 10,
            'tenant_id' => 1,
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);
        $order->setRelation('product', new Product([
            'name' => 'Nome Interno Longo',
            'notification_name' => 'Asgard Academy',
        ]));

        $this->assertStringContainsString('Produto: Asgard Academy', $order->saleApprovedPushBody());
        $this->assertStringNotContainsString('Nome Interno Longo', $order->saleApprovedPushBody());
    }

    public function test_order_bump_push_title_and_body_include_order_bump_label(): void
    {
        $order = new Order([
            'amount' => 150,
            'tenant_id' => 1,
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);
        $order->id = 55;
        $order->setRelation('product', new Product(['name' => 'Produto Principal']));

        $main = new OrderItem([
            'amount' => 100,
            'position' => 0,
        ]);
        $main->id = 1;
        $main->setRelation('product', new Product(['name' => 'Produto Principal']));

        $bump = new OrderItem([
            'amount' => 50,
            'position' => 1,
        ]);
        $bump->id = 2;
        $bump->setRelation('product', new Product(['name' => 'Bump Extra']));

        $order->setRelation('orderItems', collect([$main, $bump]));

        $messages = $order->saleApprovedPushMessages();
        $this->assertCount(2, $messages);

        $this->assertFalse($messages[0]['is_order_bump']);
        $this->assertSame('sale_55', $messages[0]['event_key']);
        $this->assertSame('Venda aprovada (PIX)', $messages[0]['title']);
        $this->assertStringContainsString('Produto: Produto Principal', $messages[0]['body']);
        $this->assertStringNotContainsString('Order bump', $messages[0]['body']);

        $this->assertTrue($messages[1]['is_order_bump']);
        $this->assertSame('sale_55_bump_2', $messages[1]['event_key']);
        $this->assertSame('Venda aprovada — Order bump (PIX)', $messages[1]['title']);
        $this->assertStringStartsWith("Order bump\n", $messages[1]['body']);
        $this->assertStringContainsString('Produto: Bump Extra', $messages[1]['body']);
        $this->assertStringContainsString('Valor: R$ 50,00', $messages[1]['body']);
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

    public function test_pix_generated_push_uses_same_content_fields_as_sale(): void
    {
        $order = new Order([
            'amount' => 19.90,
            'tenant_id' => 1,
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);
        $order->setRelation('product', new Product(['name' => 'E-book Premium']));

        $this->assertSame('PIX gerado (PIX)', $order->pixGeneratedPushTitle());
        $this->assertSame(
            "Produto: E-book Premium\nValor: R$ 19,90\nPagamento: PIX\nAguardando pagamento",
            $order->pixGeneratedPushBody()
        );
    }

    public function test_boleto_generated_push_uses_same_content_fields_as_sale(): void
    {
        $order = new Order([
            'amount' => 120.00,
            'tenant_id' => 1,
            'metadata' => ['checkout_payment_method' => 'boleto'],
        ]);
        $order->setRelation('product', new Product([
            'name' => 'Nome Interno',
            'notification_name' => 'Mentoria VIP',
        ]));

        $this->assertSame('Boleto gerado (Boleto)', $order->boletoGeneratedPushTitle());
        $this->assertSame(
            "Produto: Mentoria VIP\nValor: R$ 120,00\nPagamento: Boleto\nAguardando pagamento",
            $order->boletoGeneratedPushBody()
        );
        $this->assertStringNotContainsString('Nome Interno', $order->boletoGeneratedPushBody());
    }
}
