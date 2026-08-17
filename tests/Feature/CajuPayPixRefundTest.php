<?php

namespace Tests\Feature;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Jobs\PollCajuPayPixRefundJob;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\SellerActivityLog;
use App\Models\User;
use App\Services\CajuPay\CajuPayPixRefundConfirmationService;
use App\Services\OrderRefundGatewayBridge;
use App\Services\SellerActivityLogService;
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

    public function test_refund_server_error_explains_acquirer_did_not_receive_event(): void
    {
        config(['services.cajupay.base_url' => 'https://api.cajupay.com.br']);
        $paymentId = '550e8400-e29b-41d4-a716-446655440003';

        Http::fake([
            'https://api.cajupay.com.br/api/payments/'.$paymentId.'/pix-refund' => Http::response([
                'error' => 'upstream',
            ], 503),
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
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('A adquirente não recebeu o evento de reembolso', $result['note'] ?? '');
    }

    public function test_driver_map_pix_refund_pending(): void
    {
        $driver = new CajuPayDriver;
        $mapped = $driver->mapPixRefundResponse(['status' => 'submitted']);
        $this->assertTrue($mapped['success']);
        $this->assertTrue($mapped['pending']);
    }

    public function test_poll_job_exhausted_confirmation_writes_activity_log_with_reason(): void
    {
        $merchant = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
        ]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();
        $product = $this->createTestProduct(['tenant_id' => $merchant->id]);
        $order = Order::create([
            'tenant_id' => $merchant->id,
            'user_id' => $merchant->id,
            'product_id' => $product->id,
            'status' => 'refund_pending',
            'amount' => 50,
            'email' => 'x@y.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
            'gateway_id' => '550e8400-e29b-41d4-a716-446655440099',
            'metadata' => ['cajupay_pix_refund_pending' => true],
        ]);

        $confirmation = \Mockery::mock(CajuPayPixRefundConfirmationService::class);
        $confirmation->shouldReceive('confirmIfRemoteCancelled')->once()->andReturn(false);

        (new PollCajuPayPixRefundJob($order->id, 24))->handle($confirmation);

        $log = SellerActivityLog::query()->where('action', SellerActivityLogService::REFUND_FAILED)->first();
        $this->assertNotNull($log);
        $this->assertSame($merchant->id, (int) $log->tenant_id);
        $this->assertSame('job', $log->source);
        $this->assertStringContainsString('A adquirente não recebeu ou não confirmou o evento de reembolso', $log->summary);
    }
}
