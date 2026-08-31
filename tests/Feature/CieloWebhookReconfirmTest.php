<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Events\OrderRefunded;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CieloWebhookReconfirmTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'queue.default' => 'sync',
            'getfy.api.inbound_webhooks_async' => false,
        ]);
        Event::fake([OrderCompleted::class, OrderRefunded::class]);
    }

    private function seedCieloCredentials(array $extra = []): GatewayCredential
    {
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cielo',
        ]);
        $cred->is_connected = true;
        $cred->is_enabled = true;
        $cred->setEncryptedCredentials(array_merge([
            'merchant_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'merchant_key' => str_repeat('K', 40),
            'sandbox' => true,
        ], $extra));
        $cred->save();

        return $cred;
    }

    private function createPendingCieloOrder(string $paymentId, float $amount = 10.00): Order
    {
        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['name' => 'Cielo PIX']);

        return Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => $amount,
            'email' => 'buyer@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cielo',
            'gateway_id' => $paymentId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $sale
     */
    private function fakeCieloSale(array $sale): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($sale) {
            $host = parse_url($request->url(), PHP_URL_HOST);

            if (! in_array($host, [
                'apiquerysandbox.cieloecommerce.cielo.com.br',
                'apiquery.cieloecommerce.cielo.com.br',
            ], true)) {
                return Http::response(['unexpected' => $request->url()], 500);
            }

            return Http::response($sale, 200);
        });
    }

    public function test_probe_without_payment_id_returns_ok(): void
    {
        $this->postJson('/webhooks/gateways/cielo', ['ChangeType' => 1])
            ->assertOk()
            ->assertJson(['received' => true]);
    }

    public function test_change_type_1_with_status_2_completes_order(): void
    {
        $paymentId = 'b8c1b2ea-e06a-4135-9389-8bdbdccacd20';
        $this->seedCieloCredentials();
        $order = $this->createPendingCieloOrder($paymentId, 10.00);

        $this->fakeCieloSale([
            'MerchantOrderId' => (string) $order->id,
            'Payment' => [
                'PaymentId' => $paymentId,
                'Status' => 2,
                'Amount' => 1000,
                'Type' => 'Pix',
            ],
        ]);

        $this->postJson('/webhooks/gateways/cielo', [
            'PaymentId' => $paymentId,
            'ChangeType' => 1,
        ])->assertOk()->assertJson(['received' => true]);

        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_amount_mismatch_does_not_complete_order(): void
    {
        $paymentId = 'cccccccccccccccccccccccccccccccccccc';
        $this->seedCieloCredentials();
        $order = $this->createPendingCieloOrder($paymentId, 50.00);

        $this->fakeCieloSale([
            'MerchantOrderId' => (string) $order->id,
            'Payment' => [
                'PaymentId' => $paymentId,
                'Status' => 2,
                'Amount' => 1000,
            ],
        ]);

        $this->postJson('/webhooks/gateways/cielo', [
            'PaymentId' => $paymentId,
            'ChangeType' => 1,
        ])->assertOk();

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_unknown_payment_id_is_ignored(): void
    {
        $this->seedCieloCredentials();

        $this->postJson('/webhooks/gateways/cielo', [
            'PaymentId' => '00000000-0000-0000-0000-000000000000',
            'ChangeType' => 1,
        ])->assertOk()->assertJson(['received' => true]);
    }

    public function test_invalid_static_header_is_rejected(): void
    {
        $paymentId = 'dddddddd-dddd-dddd-dddd-dddddddddddd';
        $this->seedCieloCredentials([
            'webhook_header_key' => 'X-Cielo-Secret',
            'webhook_header_value' => 'super-secret',
        ]);
        $this->createPendingCieloOrder($paymentId);

        $this->postJson('/webhooks/gateways/cielo', [
            'PaymentId' => $paymentId,
            'ChangeType' => 1,
        ])->assertUnauthorized();
    }

    public function test_replay_does_not_change_completed_order(): void
    {
        $paymentId = 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee';
        $this->seedCieloCredentials();
        $order = $this->createPendingCieloOrder($paymentId, 10.00);

        $this->fakeCieloSale([
            'MerchantOrderId' => (string) $order->id,
            'Payment' => [
                'PaymentId' => $paymentId,
                'Status' => 2,
                'Amount' => 1000,
            ],
        ]);

        $payload = ['PaymentId' => $paymentId, 'ChangeType' => 1];
        $this->postJson('/webhooks/gateways/cielo', $payload)->assertOk();
        $this->assertSame('completed', $order->fresh()->status);

        $this->postJson('/webhooks/gateways/cielo', $payload)->assertOk();
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_status_11_on_completed_order_marks_refunded(): void
    {
        $paymentId = 'ffffffff-ffff-ffff-ffff-ffffffffffff';
        $this->seedCieloCredentials();
        $order = $this->createPendingCieloOrder($paymentId, 10.00);
        $order->update(['status' => 'completed']);

        $this->fakeCieloSale([
            'MerchantOrderId' => (string) $order->id,
            'Payment' => [
                'PaymentId' => $paymentId,
                'Status' => 11,
                'Amount' => 1000,
            ],
        ]);

        $this->postJson('/webhooks/gateways/cielo', [
            'PaymentId' => $paymentId,
            'ChangeType' => 1,
        ])->assertOk();

        $this->assertSame('refunded', $order->fresh()->status);
    }
}
