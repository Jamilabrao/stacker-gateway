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
        $this->assertNotNull($session->form_started_at);
    }

    public function test_track_resyncs_phone_on_subsequent_form_filled(): void
    {
        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'checkout_slug' => 'trackapi2',
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
            'step' => 'form_filled',
            'email' => 'buyer@example.com',
            'name' => 'Comprador Teste',
        ])->assertOk();

        $session->refresh();
        $this->assertNull($session->phone);
        $originalFilledAt = $session->form_filled_at;

        $this->travel(1)->seconds();

        $this->postJson('/api/checkout/track', [
            'session_token' => $session->session_token,
            'step' => 'form_filled',
            'email' => 'buyer@example.com',
            'name' => 'Comprador Teste',
            'phone' => '5511988776655',
        ])->assertOk();

        $session->refresh();
        $this->assertSame('5511988776655', $session->phone);
        $this->assertSame(CheckoutSession::STEP_FORM_FILLED, $session->step);
        $this->assertTrue($session->form_filled_at->gt($originalFilledAt));
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
