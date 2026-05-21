<?php

namespace Tests\Feature;

use App\Models\CheckoutSession;
use App\Models\Setting;
use App\Models\User;
use App\Support\CheckoutTurnstileSettings;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckoutSecurityTest extends TestCase
{
    private function checkoutPayload(CheckoutSession $session, array $overrides = []): array
    {
        return array_merge([
            'product_id' => $session->product_id,
            'payment_method' => 'pix',
            'email' => 'buyer-security@example.com',
            'checkout_session_token' => $session->session_token,
            'website' => '',
        ], $overrides);
    }

    private function createSessionForProduct($product, ?\DateTimeInterface $createdAt = null): CheckoutSession
    {
        return CheckoutSession::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'checkout_slug' => 'chksec1',
            'session_token' => (string) Str::uuid(),
            'step' => CheckoutSession::STEP_VISIT,
            'customer_ip' => '127.0.0.1',
            'created_at' => $createdAt ?? now()->subSeconds(30),
            'updated_at' => $createdAt ?? now()->subSeconds(30),
        ]);
    }

    public function test_honeypot_blocks_checkout(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct();
        $session = $this->createSessionForProduct($product);

        $response = $this->postJson('/checkout', $this->checkoutPayload($session, [
            'website' => 'https://spam-bot.example',
        ]));

        $response->assertStatus(422);
    }

    public function test_missing_checkout_session_token_is_rejected(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct();

        $response = $this->postJson('/checkout', [
            'product_id' => $product->id,
            'payment_method' => 'pix',
            'email' => 'buyer@example.com',
        ]);

        $response->assertStatus(422);
    }

    public function test_checkout_session_too_young_is_rejected(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct();
        $session = $this->createSessionForProduct($product, now());

        $response = $this->postJson('/checkout', $this->checkoutPayload($session));

        $response->assertStatus(422);
    }

    public function test_checkout_session_old_enough_passes_min_time_guard(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct();
        $session = $this->createSessionForProduct($product, now()->subSeconds(30));

        $response = $this->postJson('/checkout', $this->checkoutPayload($session));

        $this->assertNotEquals(
            'Aguarde alguns segundos antes de finalizar o pagamento.',
            $response->json('message') ?? $response->exception?->getMessage()
        );
    }

    public function test_pix_rate_limit_returns_429_on_fourth_attempt(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct();
        $session = $this->createSessionForProduct($product);
        $payload = $this->checkoutPayload($session);

        $this->postJson('/checkout', $payload)->assertStatus(422);
        $this->postJson('/checkout', $payload)->assertStatus(422);
        $this->postJson('/checkout', $payload)->assertStatus(422);

        $fourth = $this->postJson('/checkout', $payload);
        $fourth->assertStatus(429);
    }

    public function test_turnstile_required_when_enabled_for_pix(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct();
        $session = $this->createSessionForProduct($product);

        Setting::set('checkout_turnstile_enabled', '1', null);
        Setting::set('checkout_turnstile_site_key', 'test-site-key', null);
        Setting::set('checkout_turnstile_mode', CheckoutTurnstileSettings::MODE_PIX_BOLETO, null);
        Setting::set('checkout_turnstile_secret_key', encrypt('test-secret'), null);

        $response = $this->postJson('/checkout', $this->checkoutPayload($session));

        $response->assertStatus(422);
    }

    public function test_checkout_builder_preview_from_client_does_not_bypass_guard(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct();
        $session = $this->createSessionForProduct($product);

        $response = $this->postJson('/checkout', $this->checkoutPayload($session, [
            'checkout_builder_preview' => true,
        ]));

        $response->assertStatus(422);
    }

}
