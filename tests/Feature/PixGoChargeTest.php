<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\PixGoAccess;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class PixGoChargeTest extends TestCase
{
    private function approvedSeller(): User
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        return $seller;
    }

    public function test_pixgo_routes_return_404_when_disabled(): void
    {
        PixGoAccess::setEnabled(false);
        $seller = $this->approvedSeller();

        $this->actingAs($seller)->get('/pixgo')->assertNotFound();
    }

    public function test_pixgo_index_loads_when_enabled(): void
    {
        PixGoAccess::setEnabled(true);
        $seller = $this->approvedSeller();

        $this->actingAs($seller)
            ->get('/pixgo')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('PixGo/Index')
                ->has('sidebar_label')
                ->has('minimum_charge_brl'));
    }

    public function test_charge_creates_pixgo_order_and_redirects(): void
    {
        PixGoAccess::setEnabled(true);
        $seller = $this->approvedSeller();

        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createPixPayment')
            ->once()
            ->andReturnUsing(function ($order) {
                $order->update(['gateway' => 'fake', 'gateway_id' => 'tx-pixgo-1']);

                return [
                    'transaction_id' => 'tx-pixgo-1',
                    'gateway' => 'fake',
                    'qrcode' => 'qr-data',
                    'copy_paste' => '00020126580014br.gov.bcb.pix',
                ];
            });
        $this->instance(PaymentService::class, $mock);

        $response = $this->actingAs($seller)->post('/pixgo/cobrar', [
            'amount_cents' => 2590,
            'buyer' => [
                'name' => 'Cliente PixGO',
                'email' => 'cliente@example.com',
            ],
        ]);

        $response->assertRedirect();

        $order = Order::query()->where('tenant_id', $seller->id)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame('pixgo', $order->metadata['source'] ?? null);
        $this->assertSame('pix', $order->payment_method);
        $this->assertNull($order->product_id);
        $this->assertTrue($order->isPixGoSale());
        $this->assertSame(25.90, (float) $order->amount);
    }

    public function test_status_endpoint_returns_completed_when_order_paid(): void
    {
        PixGoAccess::setEnabled(true);
        $seller = $this->approvedSeller();

        $order = Order::create([
            'tenant_id' => $seller->id,
            'status' => 'completed',
            'amount' => 10.00,
            'email' => 'test@example.com',
            'payment_method' => 'pix',
            'metadata' => ['source' => 'pixgo'],
        ]);

        $token = 'test-token-status';
        Cache::put('pixgo.charge.'.$token, [
            'order_id' => $order->id,
            'tenant_id' => $seller->id,
        ], now()->addHour());

        $this->actingAs($seller)
            ->getJson('/pixgo/status?token='.$token)
            ->assertOk()
            ->assertJsonPath('status', 'completed');
    }
}
