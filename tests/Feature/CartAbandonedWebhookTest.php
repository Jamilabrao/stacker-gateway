<?php

namespace Tests\Feature;

use App\Events\CartAbandoned;
use App\Models\CheckoutSession;
use App\Models\Product;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CartAbandonedWebhookTest extends TestCase
{
    public function test_cart_abandoned_webhook_payload_is_slim(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_LINK,
            'checkout_slug' => 'checkout-test',
            'checkout_config' => ['deliverable_link' => 'https://example.com'],
            'member_area_config' => ['theme' => ['primary' => '#000']],
        ]);

        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => 'checkout-test',
            'session_token' => 'tok-abc',
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'email' => 'abandoned@test.com',
            'name' => 'Abandonado',
        ]);

        $webhook = Webhook::create([
            'tenant_id' => 1,
            'name' => 'CRM',
            'url' => 'https://example.com/webhook',
            'events' => [CartAbandoned::class],
            'is_active' => true,
        ]);

        event(new CartAbandoned($session));

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            if (($body['event'] ?? '') !== 'carrinho_abandonado') {
                return false;
            }
            $payload = $body['payload'] ?? [];
            $encoded = json_encode($payload);

            return isset($payload['customer']['email'])
                && $payload['customer']['email'] === 'abandoned@test.com'
                && isset($payload['checkout_link'])
                && isset($payload['checkoutSession']['id'])
                && ! str_contains($encoded, 'member_area_config')
                && ! str_contains($encoded, 'checkout_config');
        });
    }

}
