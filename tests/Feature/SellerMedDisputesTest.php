<?php

namespace Tests\Feature;

use App\Models\MedDispute;
use App\Models\Order;
use App\Models\User;
use Tests\TestCase;

class SellerMedDisputesTest extends TestCase
{
    public function test_seller_only_sees_tenant_managed_disputes(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $tenantId = (int) $seller->id;

        $product = $this->createTestProduct(['tenant_id' => $tenantId]);
        $order = Order::create([
            'tenant_id' => $tenantId,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 's@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
        ]);

        $tenantDispute = MedDispute::create([
            'order_id' => $order->id,
            'tenant_id' => $tenantId,
            'responsible_party' => MedDispute::PARTY_TENANT,
            'cajupay_dispute_id' => 'tenant-d-1',
            'status' => MedDispute::STATUS_OPEN,
            'amount_cents' => 5000,
            'opened_at' => now(),
        ]);

        MedDispute::create([
            'order_id' => $order->id,
            'tenant_id' => $tenantId,
            'responsible_party' => MedDispute::PARTY_PLATFORM,
            'cajupay_dispute_id' => 'platform-d-1',
            'status' => MedDispute::STATUS_OPEN,
            'amount_cents' => 5000,
            'opened_at' => now(),
        ]);

        $this->actingAs($seller)
            ->get(route('disputas.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Disputas/Index')
                ->has('disputes', 1)
                ->where('disputes.0.id', $tenantDispute->id)
            );

        $this->actingAs($seller)
            ->get(route('disputas.show', $tenantDispute))
            ->assertOk();

        $platformDispute = MedDispute::where('cajupay_dispute_id', 'platform-d-1')->first();
        $this->actingAs($seller)
            ->get(route('disputas.show', $platformDispute))
            ->assertNotFound();
    }
}
