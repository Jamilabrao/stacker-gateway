<?php

namespace Tests\Feature;

use App\Mail\AccessGrantedMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\AccessEmailSendResult;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccessEmailResendTest extends TestCase
{
    public function test_resend_access_email_succeeds_with_platform_smtp(): void
    {
        Mail::fake();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        Setting::set('smtp_host', 'smtp.example.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_username', 'global-user', null);
        Setting::set('smtp_password', encrypt('secret'), null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('email_provider', 'smtp', null);

        $buyer = User::factory()->create(['tenant_id' => $seller->id, 'email' => 'buyer-resend@test.com']);

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'curso-resend',
        ]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'buyer-resend@test.com',
        ]);

        $response = $this->actingAs($seller)->postJson(route('vendas.resend-access-email', $order->id));

        $response->assertOk()->assertJson(['success' => true]);
        Mail::assertSent(AccessGrantedMail::class);
    }

    public function test_resend_returns_real_error_when_smtp_missing(): void
    {
        Mail::fake();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
        ]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'buyer@test.com',
        ]);

        $response = $this->actingAs($seller)->postJson(route('vendas.resend-access-email', $order->id));

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);

        $message = (string) $response->json('message');
        $this->assertStringContainsString('servidor de e-mail', strtolower($message));
        $this->assertStringNotContainsString('template', strtolower($message));
        Mail::assertNothingSent();
    }

    public function test_resend_rejects_link_pagamento_product(): void
    {
        Mail::fake();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_LINK_PAGAMENTO,
        ]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'buyer@test.com',
        ]);

        $response = $this->actingAs($seller)->postJson(route('vendas.resend-access-email', $order->id));

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(
            AccessEmailSendResult::messageForReason(AccessEmailSendResult::REASON_LINK_PAGAMENTO),
            $response->json('message')
        );
    }
}
