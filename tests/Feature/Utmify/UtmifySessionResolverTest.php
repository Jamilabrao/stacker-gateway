<?php

namespace Tests\Feature\Utmify;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\UtmifyService;
use Tests\TestCase;

class UtmifySessionResolverTest extends TestCase
{
    public function test_resolves_session_via_checkout_session_token_in_order_metadata(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1, 'checkout_slug' => 'resolver1']);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 25,
            'email' => 'resolver@example.com',
            'metadata' => [
                'checkout_session_token' => 'token-resolver-abc',
                'utm_source' => 'google',
            ],
        ]);

        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'token-resolver-abc',
            'step' => CheckoutSession::STEP_FORM_STARTED,
            'order_id' => null,
            'utm_source' => 'facebook',
            'utm_campaign' => 'from-session',
        ]);

        $service = new UtmifyService;
        $session = $service->resolveCheckoutSessionForOrder($order);

        $this->assertNotNull($session);
        $this->assertSame('token-resolver-abc', $session->session_token);
        $this->assertNull($session->order_id);

        $payload = $service->buildPayload($order, 'waiting_payment');
        $this->assertSame('google', $payload['trackingParameters']['utm_source']);
        $this->assertSame('from-session', $payload['trackingParameters']['utm_campaign']);
    }

    public function test_resolves_session_by_order_id_first(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => 'linked@example.com',
        ]);

        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug ?? 'slug',
            'session_token' => 'other-token',
            'step' => CheckoutSession::STEP_CONVERTED,
            'order_id' => $order->id,
            'utm_source' => 'direct-link',
        ]);

        $service = new UtmifyService;
        $session = $service->resolveCheckoutSessionForOrder($order);

        $this->assertNotNull($session);
        $this->assertSame($order->id, $session->order_id);
    }
}
