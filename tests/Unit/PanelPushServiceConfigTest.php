<?php

namespace Tests\Unit;

use App\Models\PanelPushSubscription;
use App\Services\PanelPushService;
use App\Services\Push\PanelPushDispatcher;
use App\Support\PanelPushSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use Tests\Concerns\UsesTestVapidKeys;
use Tests\TestCase;

class PanelPushServiceConfigTest extends TestCase
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

    public function test_send_to_subscriptions_applies_branding_config_before_dispatch(): void
    {
        $keys = $this->configureTestVapidPush();
        PanelPushSettings::storeVapidKeys($keys['publicKey'], $keys['privateKey']);

        config([
            'getfy.pwa.vapid_public' => null,
            'getfy.pwa.vapid_private' => null,
        ]);

        $seller = $this->createSellerUser();
        $subscription = PanelPushSubscription::create([
            'user_id' => $seller->id,
            'tenant_id' => $seller->tenant_id,
            'provider' => PanelPushSubscription::PROVIDER_VAPID,
            'endpoint' => 'https://push.example.com/sub/config-test',
            'keys' => ['auth' => 'dGVzdA', 'p256dh' => 'dGVzdA'],
        ]);

        $dispatcher = Mockery::mock(PanelPushDispatcher::class);
        $dispatcher->shouldReceive('send')
            ->once()
            ->withArgs(function (Collection $subscriptions) use ($subscription) {
                return $subscriptions->count() === 1
                    && (int) $subscriptions->first()->id === (int) $subscription->id
                    && PanelPushSettings::isPushEnabled();
            })
            ->andReturn(['sent' => 1, 'failed' => 0, 'invalid' => 0, 'expired' => 0, 'total' => 1]);

        $this->app->instance(PanelPushDispatcher::class, $dispatcher);

        app(PanelPushService::class)->sendToSubscriptions(
            collect([$subscription]),
            'Teste',
            'Corpo',
            '/test'
        );

        $this->assertSame($keys['publicKey'], config('getfy.pwa.vapid_public'));
        $this->assertSame($keys['privateKey'], config('getfy.pwa.vapid_private'));
    }
}
