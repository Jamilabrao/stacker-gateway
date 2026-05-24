<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use App\Services\OrderCompletedWalletCreditor;
use App\Support\CheckoutConfigUrlSanitizer;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    public function test_wallet_credit_is_idempotent_when_called_twice(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
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

        OrderCompletedWalletCreditor::credit($order->fresh());
        OrderCompletedWalletCreditor::credit($order->fresh());

        $creditCount = WalletTransaction::query()
            ->where('order_id', $order->id)
            ->where('type', WalletTransaction::TYPE_CREDIT_SALE)
            ->count();

        $this->assertSame(1, $creditCount);

        $wallet = TenantWallet::query()->where('tenant_id', $seller->id)->first();
        $this->assertNotNull($wallet);
        $this->assertLessThan(200.0, (float) ($wallet->available_pix ?? 0));
    }

    public function test_begin_payout_approval_blocks_parallel_approval(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 50.00,
            'fee_amount' => 0,
            'net_amount' => 50.00,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
        ]);

        $first = MerchantWithdrawalService::beginPayoutApproval((int) $withdrawal->id);
        $second = MerchantWithdrawalService::beginPayoutApproval((int) $withdrawal->id);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(MerchantWithdrawalService::STATUS_PROCESSING, $first->status);
    }

    public function test_mark_paid_is_idempotent_for_wallet_transactions(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 30.00,
            'fee_amount' => 0,
            'net_amount' => 30.00,
            'bucket' => 'pix',
            'status' => 'processing',
            'currency' => 'BRL',
        ]);

        MerchantWithdrawalService::markPaid($withdrawal);
        MerchantWithdrawalService::markPaid($withdrawal->fresh());

        $completeCount = WalletTransaction::query()
            ->where('withdrawal_id', $withdrawal->id)
            ->where('type', WalletTransaction::TYPE_WITHDRAWAL_COMPLETE)
            ->count();

        $this->assertSame(1, $completeCount);
        $this->assertSame(MerchantWithdrawalService::STATUS_PAID, $withdrawal->fresh()->status);
    }

    public function test_checkout_config_sanitizer_strips_javascript_urls(): void
    {
        $config = CheckoutConfigUrlSanitizer::sanitize([
            'support_button' => [
                'enabled' => true,
                'url' => 'javascript:alert(document.cookie)',
            ],
            'reviews' => [
                [
                    'author' => '<script>x</script>João',
                    'photo' => 'javascript:void(0)',
                    'description' => '<b>ok</b>',
                ],
            ],
        ]);

        $this->assertSame('#', $config['support_button']['url']);
        $this->assertSame('', $config['reviews'][0]['photo']);
        $this->assertStringNotContainsString('<script>', (string) $config['reviews'][0]['author']);
        $this->assertStringNotContainsString('<b>', (string) $config['reviews'][0]['description']);
    }

    public function test_checkout_config_sanitizer_keeps_https_urls(): void
    {
        $url = 'https://example.com/suporte';
        $config = CheckoutConfigUrlSanitizer::sanitize([
            'support_button' => ['url' => $url],
        ]);

        $this->assertSame($url, $config['support_button']['url']);
    }
}
