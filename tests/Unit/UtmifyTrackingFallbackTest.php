<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\UtmifyService;
use Tests\TestCase;

class UtmifyTrackingFallbackTest extends TestCase
{
    public function test_build_payload_uses_order_metadata_when_no_checkout_session(): void
    {
        $order = new Order([
            'tenant_id' => 1,
            'user_id' => 1,
            'product_id' => 'prod-1',
            'status' => 'completed',
            'amount' => 10,
            'email' => 'buyer@example.com',
            'gateway' => 'pix',
            'metadata' => [
                'utm_source' => 'fb',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'camp',
                'utm_content' => 'content',
                'utm_term' => 'term',
                'sck' => 'clickid',
                'src' => 'src-param',
            ],
        ]);
        $order->id = 123;
        $order->created_at = now();
        $order->updated_at = now();

        $service = new UtmifyService;
        $payload = $service->buildPayload($order, 'paid', []);

        $tp = $payload['trackingParameters'];
        $this->assertSame('fb', $tp['utm_source']);
        $this->assertSame('cpc', $tp['utm_medium']);
        $this->assertSame('camp', $tp['utm_campaign']);
        $this->assertSame('content', $tp['utm_content']);
        $this->assertSame('term', $tp['utm_term']);
        $this->assertSame('clickid', $tp['sck']);
        $this->assertSame('src-param', $tp['src']);
    }

    public function test_build_payload_includes_all_seven_tracking_keys_as_null_when_no_utms(): void
    {
        $order = new Order([
            'tenant_id' => 1,
            'user_id' => 1,
            'product_id' => 'prod-1',
            'status' => 'completed',
            'amount' => 10,
            'email' => 'buyer@example.com',
            'gateway' => 'pix',
            'metadata' => [],
        ]);
        $order->id = 456;
        $order->created_at = now();
        $order->updated_at = now();

        $service = new UtmifyService;
        $payload = $service->buildPayload($order, 'paid', []);
        $tracking = $payload['trackingParameters'];

        foreach (\App\Models\CheckoutSession::TRACKING_FIELD_KEYS as $key) {
            $this->assertArrayHasKey($key, $tracking, "Missing tracking key: {$key}");
            $this->assertNull($tracking[$key], "Expected null for key: {$key}");
        }
    }
}

