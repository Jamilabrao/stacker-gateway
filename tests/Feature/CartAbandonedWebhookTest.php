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
            'phone' => '5511999999999',
        ]);

        $webhook = Webhook::create([
            'tenant_id' => 1,
            'name' => 'CRM',
            'url' => 'https://example.com/webhook',
            'events' => [CartAbandoned::class],
            'is_active' => true,
        ]);

        event(new CartAbandoned($session));

        Http::assertSent(function ($request) use ($product) {
            $body = json_decode($request->body(), true);
            if (($body['event'] ?? '') !== 'carrinho_abandonado') {
                return false;
            }
            $payload = $body['payload'] ?? [];
            $encoded = json_encode($payload);

            return isset($payload['customer']['email'])
                && isset($payload['customer']['name'])
                && isset($payload['customer']['phone'])
                && $payload['customer']['email'] === 'abandoned@test.com'
                && $payload['customer']['name'] === 'Abandonado'
                && $payload['customer']['phone'] === '5511999999999'
                && isset($payload['product']['id'])
                && isset($payload['product']['name'])
                && (string) $payload['product']['id'] === (string) $product->id
                && $payload['product']['name'] === $product->name
                && isset($payload['checkout_link'])
                && isset($payload['checkoutSession']['id'])
                && isset($payload['checkoutSession']['phone'])
                && $payload['checkoutSession']['phone'] === '5511999999999'
                && ! str_contains($encoded, 'member_area_config')
                && ! str_contains($encoded, 'checkout_config');
        });
    }

}
