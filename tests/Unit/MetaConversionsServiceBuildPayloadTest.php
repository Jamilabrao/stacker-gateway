<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\MetaConversionsService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaConversionsServiceBuildPayloadTest extends TestCase
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
                // dados que o checkout persiste
                'fbp' => 'fb.1.1234567890.1111111111',
                'fbc' => 'fb.1.1234567890.AbCdEfGhIj',
                'user_agent' => 'UnitTest UA',
            ],
        ]);
        $order->id = 999;
        $order->created_at = now();
        $order->updated_at = now();

        // Simula pixels vindos do produto/enrollment (AffiliateConversionPixels::forOrder)
        // Aqui, setamos direto no relation product->conversion_pixels para o service ler via AffiliateConversionPixels.
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

        $svc = new MetaConversionsService;
        $results = $svc->sendPurchaseForOrder($order);

        $this->assertNotEmpty($results);

        Http::assertSent(function ($req) {
            $data = $req->data();
            $this->assertArrayHasKey('data', $data);
            $evt = $data['data'][0] ?? null;
            $this->assertSame('Purchase', $evt['event_name'] ?? null);
            $this->assertSame('order:999', $evt['event_id'] ?? null);
            $ud = $evt['user_data'] ?? [];
            $this->assertSame('fb.1.1234567890.1111111111', $ud['fbp'] ?? null);
            $this->assertSame('fb.1.1234567890.AbCdEfGhIj', $ud['fbc'] ?? null);

            return true;
        });
    }
}

