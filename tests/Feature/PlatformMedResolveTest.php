<?php

namespace Tests\Feature;

use App\Models\MedDispute;
use App\Models\Order;
use App\Models\Setting;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Med\MedResolutionService;
use App\Services\OrderCompletedWalletCreditor;
use App\Services\PlatformOrderAdminService;
use Illuminate\Support\Facades\Schema;
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

    public function test_tenant_med_won_releases_wallet_hold_to_available(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('wallet tables');
        }

        Setting::set('merchant_settlement_rules', [
            'pix' => ['days_to_available' => 0, 'reserve_percent' => 0],
            'card' => ['days_to_available' => 0, 'reserve_percent' => 0],
            'boleto' => ['days_to_available' => 0, 'reserve_percent' => 0],
        ], null);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct(['tenant_id' => (int) $seller->id]);
        $order = Order::create([
            'tenant_id' => (int) $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100,
            'email' => 'med-wallet@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
            'metadata' => ['source' => 'api'],
        ]);

        OrderCompletedWalletCreditor::credit($order->fresh());
        PlatformOrderAdminService::markDisputed($order->fresh());

        $walletAfterHold = TenantWallet::query()->where('tenant_id', $seller->id)->first();
        $this->assertNotNull($walletAfterHold);
        $availableAfterHold = (float) ($walletAfterHold->available_pix ?? 0);
        $this->assertLessThan(90.0, $availableAfterHold);

        $dispute = MedDispute::create([
            'order_id' => $order->id,
            'tenant_id' => (int) $seller->id,
            'responsible_party' => MedDispute::PARTY_TENANT,
            'cajupay_dispute_id' => 'wallet-hold-test-1',
            'status' => MedDispute::STATUS_OPEN,
            'amount_cents' => 10000,
            'opened_at' => now(),
        ]);

        app(MedResolutionService::class)->applyWalletOutcome($dispute, 'won');

        $order->refresh();
        $this->assertSame('completed', $order->status);

        $walletAfterRelease = TenantWallet::query()->where('tenant_id', $seller->id)->first();
        $this->assertGreaterThan($availableAfterHold, (float) ($walletAfterRelease->available_pix ?? 0));

        $this->assertTrue(
            WalletTransaction::query()
                ->where('order_id', $order->id)
                ->where('type', WalletTransaction::TYPE_MED_HOLD)
                ->whereNotNull('meta->released_at')
                ->exists()
        );
    }

    public function test_platform_med_won_releases_wallet_when_order_was_disputed(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('wallet tables');
        }

        Setting::set('merchant_settlement_rules', [
            'pix' => ['days_to_available' => 0, 'reserve_percent' => 0],
            'card' => ['days_to_available' => 0, 'reserve_percent' => 0],
            'boleto' => ['days_to_available' => 0, 'reserve_percent' => 0],
        ], null);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct(['tenant_id' => (int) $seller->id]);
        $order = Order::create([
            'tenant_id' => (int) $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 80,
            'email' => 'platform-med@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
        ]);

        OrderCompletedWalletCreditor::credit($order->fresh());
        PlatformOrderAdminService::markDisputed($order->fresh());

        $walletAfterHold = TenantWallet::query()->where('tenant_id', $seller->id)->first();
        $availableAfterHold = (float) ($walletAfterHold->available_pix ?? 0);

        $dispute = MedDispute::create([
            'order_id' => $order->id,
            'tenant_id' => (int) $seller->id,
            'responsible_party' => MedDispute::PARTY_PLATFORM,
            'cajupay_dispute_id' => 'checkout-order-'.$order->id,
            'status' => MedDispute::STATUS_OPEN,
            'amount_cents' => 8000,
            'opened_at' => now(),
        ]);

        app(MedResolutionService::class)->applyWalletOutcome($dispute, 'won');

        $this->assertSame('completed', $order->fresh()->status);
        $walletAfterRelease = TenantWallet::query()->where('tenant_id', $seller->id)->first();
        $this->assertGreaterThan($availableAfterHold, (float) ($walletAfterRelease->available_pix ?? 0));
    }
}
