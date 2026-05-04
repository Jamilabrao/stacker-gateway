<?php

namespace Tests\Feature;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\User;
use App\Services\UtmifyService;
use Tests\TestCase;

class CheckoutTrackingParamsTest extends TestCase
{
    public function test_checkout_show_stores_extended_tracking_query_on_session(): void
    {
        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'name' => 'Produto tracking',
            'price' => 19.90,
            'checkout_slug' => 'trkchk1',
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
            'utm_source' => 'src1',
            'utm_medium' => 'med1',
            'utm_campaign' => 'camp1',
            'utm_content' => 'content1',
            'utm_term' => 'term1',
            'sck' => 'sck-value',
            'src' => 'src-param',
        ]);

        $this->get('/c/'.$product->checkout_slug.'?'.$qs)->assertOk();

        $this->assertTrue(
            CheckoutSession::query()
                ->where('product_id', $product->id)
                ->where('utm_source', 'src1')
                ->where('utm_medium', 'med1')
                ->where('utm_campaign', 'camp1')
                ->where('utm_content', 'content1')
                ->where('utm_term', 'term1')
                ->where('sck', 'sck-value')
                ->where('src', 'src-param')
                ->exists()
        );
    }

    public function test_utmify_build_payload_includes_tracking_from_checkout_session(): void
    {
        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'name' => 'Produto utmify',
            'price' => 10,
            'checkout_slug' => 'utmify1',
        ]);

        $buyer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => 1,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => 'buyer@example.com',
            'gateway' => 'pix',
        ]);

        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'test-session-token-utmify-01',
            'step' => CheckoutSession::STEP_CONVERTED,
            'order_id' => $order->id,
            'utm_source' => 'fb',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'summer',
            'utm_content' => 'adset-a',
            'utm_term' => 'kw1',
            'sck' => 'clickid-xyz',
            'src' => 'src-from-ad',
        ]);

        $service = new UtmifyService;
        $payload = $service->buildPayload($order->fresh(), 'paid', []);

        $tp = $payload['trackingParameters'];
        $this->assertSame('fb', $tp['utm_source']);
        $this->assertSame('cpc', $tp['utm_medium']);
        $this->assertSame('summer', $tp['utm_campaign']);
        $this->assertSame('adset-a', $tp['utm_content']);
        $this->assertSame('kw1', $tp['utm_term']);
        $this->assertSame('clickid-xyz', $tp['sck']);
        $this->assertSame('src-from-ad', $tp['src']);
    }
}
