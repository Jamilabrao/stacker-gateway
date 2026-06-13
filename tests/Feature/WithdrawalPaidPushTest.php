<?php

namespace Tests\Feature;

use App\Models\PanelNotification;
use App\Models\PanelPushSubscription;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use App\Services\Push\PanelPushDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Concerns\UsesTestVapidKeys;
use Tests\TestCase;

class WithdrawalPaidPushTest extends TestCase
{
    use RefreshDatabase;
    use UsesTestVapidKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPushFeatureTests();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_mark_paid_sends_withdrawal_paid_push(): void
    {
        if (! Schema::hasTable('panel_push_subscriptions') || ! Schema::hasTable('panel_notifications')) {
            $this->markTestSkipped('panel push tables missing');
        }

        $this->configureTestVapidPush();

        $seller = $this->createSellerUser();

        PanelPushSubscription::create([
            'user_id' => $seller->id,
            'tenant_id' => $seller->tenant_id,
            'provider' => PanelPushSubscription::PROVIDER_VAPID,
            'endpoint' => 'https://push.example.com/sub/withdrawal',
            'keys' => ['auth' => 'dGVzdA', 'p256dh' => 'dGVzdA'],
        ]);

        $dispatcher = Mockery::mock(PanelPushDispatcher::class);
        $dispatcher->shouldReceive('send')
            ->once()
            ->withArgs(function ($subscriptions, string $title, string $body, ?string $url) {
                return $subscriptions->count() === 1
                    && $title === 'Saque concluído'
                    && str_contains($body, 'R$ 95,00')
                    && str_contains((string) $url, '/financeiro');
            })
            ->andReturn(['sent' => 1, 'failed' => 0, 'invalid' => 0, 'expired' => 0, 'total' => 1]);

        $this->app->instance(PanelPushDispatcher::class, $dispatcher);

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->tenant_id,
            'user_id' => $seller->id,
            'status' => MerchantWithdrawalService::STATUS_PROCESSING,
            'bucket' => 'pix',
            'amount' => 100.00,
            'fee_amount' => 5.00,
            'net_amount' => 95.00,
            'currency' => 'BRL',
        ]);

        MerchantWithdrawalService::markPaid($withdrawal->fresh());

        $this->assertDatabaseHas('panel_notifications', [
            'user_id' => $seller->id,
            'type' => 'withdrawal_paid',
            'event_key' => 'withdrawal_'.$withdrawal->id,
        ]);

        $this->assertSame(MerchantWithdrawalService::STATUS_PAID, $withdrawal->fresh()->status);
    }
}
