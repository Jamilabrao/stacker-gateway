<?php

namespace Tests\Feature\Utmify;

use App\Models\CheckoutSession;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class UtmifyEarlyUtmMergeTest extends TestCase
{
    public function test_checkout_show_session_utms_available_for_early_order_metadata_merge(): void
    {
        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'name' => 'Produto early utm',
            'price' => 29.90,
            'checkout_slug' => 'earlyutm1',
            'checkout_config' => [
                'customer_fields' => [
                    'name' => false,
                    'cpf' => false,
                    'phone' => false,
                    'coupon' => false,
                ],
            ],
        ]);

        $qs = http_build_query([
            'utm_source' => 'instagram',
            'utm_medium' => 'social',
            'utm_campaign' => 'early-merge',
        ]);

        $response = $this->get('/c/'.$product->checkout_slug.'?'.$qs);
        $response->assertOk();

        $session = CheckoutSession::query()
            ->where('product_id', $product->id)
            ->where('utm_source', 'instagram')
            ->first();

        $this->assertNotNull($session);
        $this->assertNotEmpty($session->session_token);
        $this->assertSame('social', $session->utm_medium);
        $this->assertSame('early-merge', $session->utm_campaign);
    }
}
