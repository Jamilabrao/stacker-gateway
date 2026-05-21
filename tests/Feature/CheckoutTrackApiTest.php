<?php

namespace Tests\Feature;

use App\Models\CheckoutSession;
use App\Models\User;
use Tests\TestCase;

class CheckoutTrackApiTest extends TestCase
{
    public function test_track_updates_session_without_500(): void
    {
        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'checkout_slug' => 'trackapi1',
        ]);

        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'track-token-'.uniqid(),
            'step' => CheckoutSession::STEP_VISIT,
        ]);

        $this->postJson('/api/checkout/track', [
            'session_token' => $session->session_token,
            'step' => 'form_started',
            'email' => 'buyer@example.com',
            'name' => 'Comprador Teste',
            'cpf' => '529.982.247-25',
            'phone' => '+5511999999999',
        ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $session->refresh();
        $this->assertSame(CheckoutSession::STEP_FORM_STARTED, $session->step);
        $this->assertSame('buyer@example.com', $session->email);
        $this->assertSame('Comprador Teste', $session->name);
    }

    public function test_track_returns_success_when_session_missing(): void
    {
        $this->postJson('/api/checkout/track', [
            'session_token' => 'nonexistent-token-xyz',
            'step' => 'form_started',
        ])
            ->assertNotFound();
    }
}
