<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\User;
use App\Services\MemberAreaResolver;
use App\Support\PublicAppUrl;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PublicAppUrlAndMagicLinkTest extends TestCase
{
    public function test_public_app_url_prefers_webhook_public_over_localhost_app_url(): void
    {
        config([
            'app.url' => 'http://localhost',
            'getfy.webhook_public_url' => 'https://loja.exemplo.com',
        ]);

        $this->assertSame('https://loja.exemplo.com', PublicAppUrl::base());
        $this->assertSame('https://loja.exemplo.com', PublicAppUrl::origin('http://localhost/m/foo'));
    }

    public function test_when_app_and_webhook_urls_match_member_links_use_that_host(): void
    {
        config([
            'app.url' => 'https://app.valuxpay.com',
            'getfy.webhook_public_url' => 'https://app.valuxpay.com',
        ]);

        $this->assertSame('https://app.valuxpay.com', PublicAppUrl::base());

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => '2xyzv09',
        ]);

        $url = app(MemberAreaResolver::class)->baseUrlForProduct($product);
        $this->assertSame('https://app.valuxpay.com/m/2xyzv09', $url);
    }

    public function test_path_magic_link_does_not_use_localhost_when_public_url_configured(): void
    {
        config([
            'app.url' => 'http://localhost',
            'getfy.webhook_public_url' => 'https://gateway.exemplo.com',
        ]);

        // Simula request interno (queue/webhook local) — não deve vazar no link do e-mail.
        $this->get('/');
        URL::forceRootUrl('http://localhost');

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'wasender',
        ]);
        $user = User::factory()->create();

        $url = app(MemberAreaResolver::class)->signedMagicAccessUrl($product, $user);

        $this->assertStringStartsWith('https://gateway.exemplo.com/m/wasender/access', $url);
        $this->assertStringNotContainsString('localhost', $url);
        $this->assertStringContainsString('signature=', $url);
    }
}
