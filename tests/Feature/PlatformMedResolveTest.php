<?php

namespace Tests\Feature;

use App\Models\MedDispute;
use App\Models\Order;
use App\Models\User;
use Tests\TestCase;

class PlatformMedResolveTest extends TestCase
{
    public function test_admin_can_resolve_tenant_dispute_won(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN]);
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct(['tenant_id' => (int) $seller->id]);
        $order = Order::create([
            'tenant_id' => (int) $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'disputed',
            'amount' => 100,
            'email' => 'x@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
            'metadata' => ['source' => 'api'],
        ]);

        $dispute = MedDispute::create([
            'order_id' => $order->id,
            'tenant_id' => (int) $seller->id,
            'responsible_party' => MedDispute::PARTY_TENANT,
            'cajupay_dispute_id' => 'resolve-test-1',
            'status' => MedDispute::STATUS_OPEN,
            'amount_cents' => 10000,
            'opened_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('plataforma.disputas.resolve', $dispute), [
                'outcome' => 'won',
                'note' => 'Prova aceita',
            ])
            ->assertRedirect(route('plataforma.disputas.show', $dispute));

        $dispute->refresh();
        $this->assertSame(MedDispute::STATUS_RESOLVED_WON, $dispute->status);
        $this->assertSame('won', $dispute->outcome);
        $this->assertSame($admin->id, $dispute->resolved_by_user_id);
    }

    public function test_platform_dispute_resolve_does_not_require_wallet_side_effects(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN]);
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct(['tenant_id' => (int) $seller->id]);
        $order = Order::create([
            'tenant_id' => (int) $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 80,
            'email' => 'p@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
        ]);

        $dispute = MedDispute::create([
            'order_id' => $order->id,
            'tenant_id' => (int) $seller->id,
            'responsible_party' => MedDispute::PARTY_PLATFORM,
            'cajupay_dispute_id' => 'checkout-order-'.$order->id,
            'status' => MedDispute::STATUS_OPEN,
            'amount_cents' => 8000,
            'opened_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('plataforma.disputas.resolve', $dispute), ['outcome' => 'won'])
            ->assertRedirect();

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(MedDispute::STATUS_RESOLVED_WON, $dispute->fresh()->status);
    }
}
