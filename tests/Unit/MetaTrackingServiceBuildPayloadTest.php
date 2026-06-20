<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\Meta\MetaTrackingService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaTrackingServiceBuildPayloadTest extends TestCase
{
    public function test_send_purchase_uses_event_id_and_meta_tokens(): void
    {
        Http::fake([
            'graph.facebook.com/*/events' => Http::response(['events_received' => 1], 200),
        ]);

        $order = new Order([
            'tenant_id' => 1,
            'status' => 'completed',
            'amount' => 10,
            'email' => 'buyer@example.com',
            'customer_ip' => '127.0.0.1',
            'metadata' => [
                'fbp' => 'fb.1.1234567890.1111111111',
                'fbc' => 'fb.1.1234567890.AbCdEfGhIj',
                'user_agent' => 'UnitTest UA',
            ],
        ]);
        $order->id = 999;
        $order->created_at = now();
        $order->updated_at = now();

        $order->setRelation('product', new \App\Models\Product([
            'tenant_id' => 1,
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '123', 'access_token' => 'tok_abc'],
                    ],
                ],
            ],
        ]));

        $svc = app(MetaTrackingService::class);
        $context = app(\App\Services\Meta\MetaEventContextResolver::class)->forOrder($order);
        $payload = $svc->buildPayload('Purchase', 'order:999', $context);

        $this->assertSame('Purchase', $payload['data'][0]['event_name'] ?? null);
        $this->assertSame('order:999', $payload['data'][0]['event_id'] ?? null);
        $ud = $payload['data'][0]['user_data'] ?? [];
        $this->assertSame('fb.1.1234567890.1111111111', $ud['fbp'] ?? null);
        $this->assertSame('fb.1.1234567890.AbCdEfGhIj', $ud['fbc'] ?? null);
    }
}
