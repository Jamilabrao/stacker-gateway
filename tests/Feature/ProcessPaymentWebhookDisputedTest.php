<?php

namespace Tests\Feature;

use App\Jobs\ProcessPaymentWebhook;
use App\Models\Order;
use App\Models\User;
use Tests\TestCase;

class ProcessPaymentWebhookDisputedTest extends TestCase
{
    public function test_checkout_disputed_marks_med_not_refunded(): void
    {
        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 40,
            'email' => 'd@e.com',
            'gateway' => 'cajupay',
            'gateway_id' => 'sess-dispute-1',
            'metadata' => ['cajupay_checkout_session_id' => 'sess-dispute-1'],
        ]);

        ProcessPaymentWebhook::dispatchSync(
            'cajupay',
            'sess-dispute-1',
            'checkout.payment.disputed',
            'disputed',
            ['webhook_source' => 'cajupay_webhook']
        );

        $this->assertSame('disputed', $order->fresh()->status);
    }

    public function test_checkout_refunded_marks_refunded(): void
    {
        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 40,
            'email' => 'r@e.com',
            'gateway' => 'cajupay',
            'gateway_id' => 'pay-ref-1',
        ]);

        ProcessPaymentWebhook::dispatchSync(
            'cajupay',
            'pay-ref-1',
            'checkout.payment.refunded',
            'refunded',
            ['webhook_source' => 'cajupay_webhook']
        );

        $this->assertSame('refunded', $order->fresh()->status);
    }
}
