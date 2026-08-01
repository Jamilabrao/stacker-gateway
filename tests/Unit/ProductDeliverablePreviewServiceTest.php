<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Services\ProductDeliverablePreviewService;
use Tests\TestCase;

class ProductDeliverablePreviewServiceTest extends TestCase
{
    public function test_link_product_exposes_deliverable_url(): void
    {
        $product = new Product([
            'type' => Product::TYPE_LINK,
            'checkout_slug' => 'abc12345',
            'checkout_config' => [
                'deliverable_link' => 'https://drive.google.com/file/d/xyz',
            ],
        ]);

        $preview = app(ProductDeliverablePreviewService::class)->forAdmin($product);

        $this->assertSame('external_link', $preview['kind']);
        $this->assertSame('https://drive.google.com/file/d/xyz', $preview['primary_url']);
        $this->assertTrue($preview['can_open']);
        $this->assertStringContainsString('/c/abc12345', (string) $preview['checkout_url']);
    }

    public function test_link_pagamento_has_checkout_only_preview(): void
    {
        $product = new Product([
            'type' => Product::TYPE_LINK_PAGAMENTO,
            'checkout_slug' => 'pay12345',
        ]);

        $preview = app(ProductDeliverablePreviewService::class)->forAdmin($product);

        $this->assertSame('checkout_only', $preview['kind']);
        $this->assertTrue($preview['can_open']);
        $this->assertStringContainsString('/c/pay12345', (string) $preview['checkout_url']);
        $this->assertStringContainsString('API PIX', (string) $preview['limitations']);
    }

    public function test_member_area_exposes_area_url(): void
    {
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'curso-admin',
        ]);

        $preview = app(ProductDeliverablePreviewService::class)->forAdmin($product);

        $this->assertSame('member_area', $preview['kind']);
        $this->assertNotEmpty($preview['primary_url']);
        $this->assertTrue($preview['can_open']);
        $this->assertSame('Ver Área de Membros', $preview['open_label']);
        $this->assertStringContainsString(
            '/plataforma/produtos/'.$product->id.'/area-membros/preview',
            (string) $preview['primary_url']
        );
    }
}
