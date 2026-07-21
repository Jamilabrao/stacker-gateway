<?php

namespace Tests\Unit;

use App\Models\ProductAffiliateEnrollment;
use App\Models\ProductOffer;
use App\Support\AffiliateCheckoutLinks;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AffiliateCheckoutLinksTest extends TestCase
{
    public function test_links_include_main_and_shared_offers_only(): void
    {
        if (! Schema::hasTable('product_offers') || ! Schema::hasColumn('product_offers', 'affiliate_share_enabled')) {
            $this->markTestSkipped('affiliate_share_enabled');
        }

        $product = $this->createTestProduct([
            'checkout_slug' => 'prodslug',
            'price' => 100,
            'currency' => 'BRL',
        ]);

        ProductOffer::query()->create([
            'product_id' => $product->id,
            'name' => 'Compartilhada',
            'price' => 80,
            'currency' => 'BRL',
            'checkout_slug' => 'share'.substr(uniqid('', true), 0, 8),
            'position' => 0,
            'affiliate_share_enabled' => true,
        ]);
        ProductOffer::query()->create([
            'product_id' => $product->id,
            'name' => 'Exclusiva',
            'price' => 50,
            'currency' => 'BRL',
            'checkout_slug' => 'offslug1',
            'position' => 1,
            'affiliate_share_enabled' => true,
        ]);
        ProductOffer::query()->create([
            'product_id' => $product->id,
            'name' => 'Oculta',
            'price' => 30,
            'currency' => 'BRL',
            'checkout_slug' => 'hidden'.substr(uniqid('', true), 0, 8),
            'position' => 2,
            'affiliate_share_enabled' => false,
        ]);

        $enrollment = new ProductAffiliateEnrollment([
            'product_id' => $product->id,
            'public_ref' => 'abcREF',
        ]);

        $links = AffiliateCheckoutLinks::linksForEnrollment($product->fresh(['offers']), $enrollment);

        $this->assertCount(3, $links);
        $this->assertSame('main', $links[0]['type']);
        $this->assertStringContainsString('/c/prodslug?ref=abcREF', $links[0]['url']);
        $this->assertSame('offer', $links[1]['type']);
        $this->assertStringContainsString('ref=abcREF', $links[1]['url']);
        $this->assertSame('offer', $links[2]['type']);
        $this->assertStringContainsString('/c/offslug1?ref=abcREF', $links[2]['url']);
        $this->assertFalse(collect($links)->contains(fn ($l) => ($l['label'] ?? '') === 'Oculta'));
    }

    public function test_append_ref_uses_ampersand_when_query_exists(): void
    {
        $url = AffiliateCheckoutLinks::appendRef('https://example.test/c/x?offer_id=9', 'r1');
        $this->assertSame('https://example.test/c/x?offer_id=9&ref=r1', $url);
    }
}
