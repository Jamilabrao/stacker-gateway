<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class CheckoutIframeEmbedTest extends TestCase
{
    public function test_homepage_keeps_x_frame_options_sameorigin(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_checkout_page_allows_iframe_embed_when_enabled(): void
    {
        config(['getfy.checkout_embed.enabled' => true]);

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'checkout_slug' => 'embed1',
            'checkout_config' => [
                'customer_fields' => [
                    'name' => false,
                    'cpf' => false,
                    'phone' => false,
                    'coupon' => false,
                ],
            ],
        ]);

        $response = $this->get('/c/'.$product->checkout_slug);

        $response->assertOk();
        $this->assertNotSame(
            'SAMEORIGIN',
            $response->headers->get('X-Frame-Options'),
            'Checkout embeddable não deve enviar X-Frame-Options: SAMEORIGIN'
        );
    }

    public function test_checkout_page_includes_frame_ancestors_in_production_csp(): void
    {
        config([
            'app.env' => 'production',
            'getfy.checkout_embed.enabled' => true,
        ]);

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'checkout_slug' => 'embed2',
            'checkout_config' => [
                'customer_fields' => [
                    'name' => false,
                    'cpf' => false,
                    'phone' => false,
                    'coupon' => false,
                ],
            ],
        ]);

        $response = $this->get('/c/'.$product->checkout_slug);

        $response->assertOk();
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('frame-ancestors *', $csp);
    }

    public function test_checkout_page_blocks_iframe_when_embed_disabled(): void
    {
        config(['getfy.checkout_embed.enabled' => false]);

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'checkout_slug' => 'embed3',
            'checkout_config' => [
                'customer_fields' => [
                    'name' => false,
                    'cpf' => false,
                    'phone' => false,
                    'coupon' => false,
                ],
            ],
        ]);

        $response = $this->get('/c/'.$product->checkout_slug);

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
