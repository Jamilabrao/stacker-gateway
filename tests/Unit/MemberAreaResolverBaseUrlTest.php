<?php

namespace Tests\Unit;

use App\Models\MemberAreaDomain;
use App\Models\Product;
use App\Services\MemberAreaResolver;
use Tests\TestCase;

class MemberAreaResolverBaseUrlTest extends TestCase
{
    public function test_custom_domain_base_url_uses_configured_host(): void
    {
        config([
            'app.url' => 'https://raiz.exemplo.com',
            'getfy.webhook_public_url' => 'https://raiz.exemplo.com',
        ]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'curso-x',
        ]);

        MemberAreaDomain::query()->updateOrCreate(
            ['product_id' => $product->id],
            ['type' => MemberAreaDomain::TYPE_CUSTOM, 'value' => 'membros.loja.com']
        );

        $product->load('memberAreaDomain');
        $url = app(MemberAreaResolver::class)->baseUrlForProduct($product);

        $this->assertSame('https://membros.loja.com', $url);
        $this->assertStringNotContainsString('raiz.exemplo.com', $url);
    }

    public function test_subdomain_host_value_does_not_fall_back_to_root_path(): void
    {
        config([
            'app.url' => 'https://raiz.exemplo.com',
            'getfy.webhook_public_url' => 'https://raiz.exemplo.com',
            'members.subdomain_enabled' => false,
        ]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'curso-y',
        ]);

        MemberAreaDomain::query()->updateOrCreate(
            ['product_id' => $product->id],
            ['type' => MemberAreaDomain::TYPE_SUBDOMAIN, 'value' => 'area.loja.com']
        );

        $product->load('memberAreaDomain');
        $url = app(MemberAreaResolver::class)->baseUrlForProduct($product);

        $this->assertSame('https://area.loja.com', $url);
    }
}
