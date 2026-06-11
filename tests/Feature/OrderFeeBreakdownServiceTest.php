<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\OrderFeeBreakdownService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderFeeBreakdownServiceTest extends TestCase
{
    public function test_breakdown_uses_wallet_amounts_after_fee_rules_change(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('wallet tables');
        }

        Setting::set('merchant_fee_rules', [
            'pix' => ['percent' => 2.0, 'fixed' => 0.0],
            'api_pix' => ['percent' => 2.0, 'fixed' => 0.0],
            'card' => ['percent' => 0, 'fixed' => 0],
            'apple_pay' => ['percent' => 0, 'fixed' => 0],
            'google_pay' => ['percent' => 0, 'fixed' => 0],
            'boleto' => ['percent' => 0, 'fixed' => 0],
            'withdrawal' => ['percent' => 0, 'fixed' => 0],
        ], null);

        Setting::set('merchant_settlement_rules', [
            'pix' => ['days_to_available' => 0, 'reserve_percent' => 0],
            'card' => ['days_to_available' => 0, 'reserve_percent' => 0],
            'boleto' => ['days_to_available' => 0, 'reserve_percent' => 0],
        ], null);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100.00,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'metadata' => [],
        ]);

        event(new OrderCompleted($order->fresh()));

        $breakdownBefore = OrderFeeBreakdownService::forOrder($order->fresh());
        $this->assertTrue($breakdownBefore['from_wallet']);
        $this->assertEqualsWithDelta(2.0, $breakdownBefore['fee'], 0.01);
        $this->assertEqualsWithDelta(98.0, $breakdownBefore['net'], 0.01);

        Setting::set('merchant_fee_rules', [
            'pix' => ['percent' => 15.0, 'fixed' => 5.0],
            'api_pix' => ['percent' => 15.0, 'fixed' => 5.0],
            'card' => ['percent' => 0, 'fixed' => 0],
            'apple_pay' => ['percent' => 0, 'fixed' => 0],
            'google_pay' => ['percent' => 0, 'fixed' => 0],
            'boleto' => ['percent' => 0, 'fixed' => 0],
            'withdrawal' => ['percent' => 0, 'fixed' => 0],
        ], null);

        $seller->forceFill([
            'merchant_fees' => ['pix' => ['percent' => 20.0, 'fixed' => 10.0]],
        ])->save();

        $breakdownAfter = OrderFeeBreakdownService::forOrder($order->fresh());
        $this->assertTrue($breakdownAfter['from_wallet']);
        $this->assertEqualsWithDelta($breakdownBefore['fee'], $breakdownAfter['fee'], 0.01);
        $this->assertEqualsWithDelta($breakdownBefore['net'], $breakdownAfter['net'], 0.01);

        $tx = WalletTransaction::query()
            ->where('order_id', $order->id)
            ->where('tenant_id', $seller->id)
            ->first();
        $this->assertNotNull($tx);
        $this->assertEqualsWithDelta((float) $tx->amount_fee, $breakdownAfter['fee'], 0.01);
    }
}
