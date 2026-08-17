<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CardInstallmentWalletCreditTest extends TestCase
{
    /**
     * @return array<int, array{percent: float, fixed: float, days_to_available: int}>
     */
    private function installmentTable(int $daysForNx, int $daysFor1x = 0, float $percentNx = 5.49): array
    {
        $out = [];
        for ($i = 1; $i <= 12; $i++) {
            $out[$i] = [
                'percent' => $i === 1 ? 4.99 : $percentNx,
                'fixed' => 0.39,
                'days_to_available' => $i === 1 ? $daysFor1x : $daysForNx,
            ];
        }

        return $out;
    }

    private function seedPlatformFees(array $installments, int $cardSettlementDays = 0): void
    {
        Setting::set('merchant_fee_rules', [
            'pix' => ['percent' => 0, 'fixed' => 0],
            'api_pix' => ['percent' => 0, 'fixed' => 0],
            'card' => ['percent' => 4.99, 'fixed' => 0.39],
            'apple_pay' => ['percent' => 4.99, 'fixed' => 0.39],
            'google_pay' => ['percent' => 4.99, 'fixed' => 0.39],
            'boleto' => ['percent' => 0, 'fixed' => 0],
            'withdrawal' => ['percent' => 0, 'fixed' => 0],
            'card_installments' => $installments,
        ], null);

        Setting::set('merchant_settlement_rules', [
            'pix' => ['days_to_available' => 0, 'reserve_percent' => 0, 'reserve_hold_days' => 0],
            'card' => ['days_to_available' => $cardSettlementDays, 'reserve_percent' => 0, 'reserve_hold_days' => 0],
            'boleto' => ['days_to_available' => 0, 'reserve_percent' => 0, 'reserve_hold_days' => 0],
        ], null);
    }

    private function completedCardOrder(User $seller, int $installments, float $amount = 100.00): Order
    {
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        return Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => $amount,
            'email' => $buyer->email,
            'payment_method' => 'card',
            'metadata' => ['installments' => $installments],
        ]);
    }

    public function test_three_installments_create_staggered_pending_credits(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('wallet tables');
        }

        Carbon::setTestNow(Carbon::parse('2026-08-17 12:00:00'));

        $this->seedPlatformFees($this->installmentTable(30));

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $order = $this->completedCardOrder($seller, 3);
        event(new OrderCompleted($order->fresh()));

        $pending = WalletTransaction::query()
            ->where('order_id', $order->id)
            ->where('type', WalletTransaction::TYPE_CREDIT_SALE_PENDING)
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $pending);
        $this->assertEqualsWithDelta(5.88, (float) $pending->sum('amount_fee'), 0.01);
        $this->assertEqualsWithDelta(94.12, (float) $pending->sum('amount_net'), 0.01);

        $this->assertSame('i1', $pending[0]->meta['portion'] ?? null);
        $this->assertSame('i2', $pending[1]->meta['portion'] ?? null);
        $this->assertSame('i3', $pending[2]->meta['portion'] ?? null);
        $this->assertSame(31.37, (float) $pending[0]->amount_net);
        $this->assertSame(31.37, (float) $pending[1]->amount_net);
        $this->assertSame(31.38, (float) $pending[2]->amount_net);

        $this->assertSame('2026-09-16', Carbon::parse($pending[0]->meta['clears_at'])->toDateString());
        $this->assertSame('2026-10-16', Carbon::parse($pending[1]->meta['clears_at'])->toDateString());
        $this->assertSame('2026-11-15', Carbon::parse($pending[2]->meta['clears_at'])->toDateString());

        Carbon::setTestNow();
    }

    public function test_one_installment_with_zero_days_credits_available_immediately(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('wallet tables');
        }

        $this->seedPlatformFees($this->installmentTable(30, 0));

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $order = $this->completedCardOrder($seller, 1);
        event(new OrderCompleted($order->fresh()));

        $this->assertTrue(
            WalletTransaction::query()
                ->where('order_id', $order->id)
                ->where('type', WalletTransaction::TYPE_CREDIT_SALE)
                ->exists()
        );
        $this->assertFalse(
            WalletTransaction::query()
                ->where('order_id', $order->id)
                ->where('type', WalletTransaction::TYPE_CREDIT_SALE_PENDING)
                ->exists()
        );

        $tx = WalletTransaction::query()->where('order_id', $order->id)->first();
        $this->assertEqualsWithDelta(5.38, (float) $tx->amount_fee, 0.001);
    }

    public function test_three_installments_with_zero_days_use_lump_settlement(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        Carbon::setTestNow(Carbon::parse('2026-08-17 12:00:00'));
        $this->seedPlatformFees($this->installmentTable(0), 2);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $order = $this->completedCardOrder($seller, 3);
        event(new OrderCompleted($order->fresh()));

        $pending = WalletTransaction::query()
            ->where('order_id', $order->id)
            ->where('type', WalletTransaction::TYPE_CREDIT_SALE_PENDING)
            ->get();

        $this->assertCount(1, $pending);
        $this->assertSame('main', $pending[0]->meta['portion'] ?? null);
        $this->assertSame('2026-08-19', Carbon::parse($pending[0]->meta['clears_at'])->toDateString());
        $this->assertEqualsWithDelta(94.12, (float) $pending[0]->amount_net, 0.001);

        Carbon::setTestNow();
    }
}
