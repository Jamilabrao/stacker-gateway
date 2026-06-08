<?php

namespace Tests\Feature;

use App\Events\CartAbandoned;
use App\Models\CheckoutSession;
use App\Models\Product;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FireAbandonedCartWebhooksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-08 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_fires_webhook_for_eligible_session_with_phone(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_LINK,
            'checkout_slug' => 'abandon-cmd',
            'checkout_config' => ['deliverable_link' => 'https://example.com'],
        ]);

        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'abandon-cmd-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'email' => 'lead@example.com',
            'name' => 'Lead Abandonado',
            'phone' => '5511999887766',
            'form_started_at' => now()->subMinutes(20),
            'form_filled_at' => now()->subMinutes(15),
        ]);

        Webhook::create([
            'tenant_id' => 1,
            'name' => 'CRM',
            'url' => 'https://example.com/webhook',
            'events' => [CartAbandoned::class],
            'is_active' => true,
        ]);

        $this->artisan('checkout:fire-abandoned-cart-webhooks', ['--minutes' => 10])
            ->assertSuccessful();

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            if (($body['event'] ?? '') !== 'carrinho_abandonado') {
                return false;
            }
            $payload = $body['payload'] ?? [];

            return ($payload['customer']['email'] ?? '') === 'lead@example.com'
                && ($payload['customer']['name'] ?? '') === 'Lead Abandonado'
                && ($payload['customer']['phone'] ?? '') === '5511999887766';
        });

        $this->assertSame(1, CheckoutSession::whereNotNull('abandoned_webhook_fired_at')->count());
    }

    public function test_command_skips_sessions_without_email(): void
    {
        Http::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $product = $this->createTestProduct(['checkout_slug' => 'abandon-noemail']);

        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'abandon-noemail-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_STARTED,
            'name' => 'Sem Email',
            'form_started_at' => now()->subMinutes(20),
        ]);

        $this->artisan('checkout:fire-abandoned-cart-webhooks', ['--minutes' => 10])
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, CheckoutSession::whereNotNull('abandoned_webhook_fired_at')->count());
    }
}
