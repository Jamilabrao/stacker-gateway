<?php

namespace Tests\Feature;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderRefundGatewayBridge;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CajuPayPixRefundTest extends TestCase
{
    public function test_refund_bridge_calls_pix_refund_api(): void
    {
        config(['services.cajupay.base_url' => 'https://api.cajupay.com.br']);

        $paymentId = '550e8400-e29b-41d4-a716-446655440001';

        Http::fake([
            'https://api.cajupay.com.br/api/payments/'.$paymentId.'/pix-refund' => Http::response([
                'status' => 'devolvido',
                'payment_id' => $paymentId,
            ], 200),
        ]);

        $cred = new GatewayCredential([
            'tenant_id' => 1,
            'gateway_slug' => 'cajupay',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials(['public_key' => 'pk', 'secret_key' => 'sk']);
        $cred->save();

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 99.90,
            'email' => 'a@b.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
            'gateway_id' => $paymentId,
        ]);

        $result = app(OrderRefundGatewayBridge::class)->tryRefund($order);

        $this->assertSame('gateway_ok', $result['status']);
        Http::assertSent(fn ($req) => $req->method() === 'POST'
            && str_contains($req->url(), '/pix-refund'));
    }

    public function test_refund_returns_blocked_med_from_api_error(): void
    {
        config(['services.cajupay.base_url' => 'https://api.cajupay.com.br']);
        $paymentId = '550e8400-e29b-41d4-a716-446655440002';

        Http::fake([
            'https://api.cajupay.com.br/api/payments/'.$paymentId.'/pix-refund' => Http::response([
                'error' => 'med_blocks_refund',
            ], 400),
        ]);

        $cred = new GatewayCredential([
            'tenant_id' => 1,
            'gateway_slug' => 'cajupay',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials(['public_key' => 'pk', 'secret_key' => 'sk']);
        $cred->save();

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'x@y.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
            'gateway_id' => $paymentId,
        ]);

        $result = app(OrderRefundGatewayBridge::class)->tryRefund($order);
        $this->assertSame('blocked_med', $result['status']);
    }

    public function test_driver_map_pix_refund_pending(): void
    {
        $driver = new CajuPayDriver;
        $mapped = $driver->mapPixRefundResponse(['status' => 'submitted']);
        $this->assertTrue($mapped['success']);
        $this->assertTrue($mapped['pending']);
    }
}
