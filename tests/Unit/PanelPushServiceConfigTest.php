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
            'vapid_public_key' => $keys['publicKey'],
        ]);

        $dispatcher = Mockery::mock(PanelPushDispatcher::class);
        $dispatcher->shouldReceive('send')
            ->once()
            ->withArgs(function (Collection $subscriptions, string $title, string $body, ?string $url, ?string $tag) use ($subscription) {
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

    public function test_send_to_subscriptions_deduplicates_by_user(): void
    {
        $keys = $this->configureTestVapidPush();
        PanelPushSettings::storeVapidKeys($keys['publicKey'], $keys['privateKey']);

        $seller = $this->createSellerUser();
        $older = PanelPushSubscription::create([
            'user_id' => $seller->id,
            'tenant_id' => $seller->tenant_id,
            'provider' => PanelPushSubscription::PROVIDER_VAPID,
            'endpoint' => 'https://push.example.com/sub/older',
            'keys' => ['auth' => 'dGVzdA', 'p256dh' => 'dGVzdA'],
            'vapid_public_key' => $keys['publicKey'],
        ]);
        $older->forceFill(['updated_at' => now()->subHour()])->save();

        $newer = PanelPushSubscription::create([
            'user_id' => $seller->id,
            'tenant_id' => $seller->tenant_id,
            'provider' => PanelPushSubscription::PROVIDER_VAPID,
            'endpoint' => 'https://push.example.com/sub/newer',
            'keys' => ['auth' => 'dGVzdB', 'p256dh' => 'dGVzdB'],
            'vapid_public_key' => $keys['publicKey'],
        ]);

        $deliveredEndpoint = null;
        $dispatcher = Mockery::mock(PanelPushDispatcher::class);
        $dispatcher->shouldReceive('send')
            ->once()
            ->withArgs(function (Collection $subscriptions) use (&$deliveredEndpoint) {
                $deliveredEndpoint = $subscriptions->first()?->endpoint;

                return $subscriptions->count() === 1;
            })
            ->andReturn(['sent' => 1, 'failed' => 0, 'invalid' => 0, 'expired' => 0, 'total' => 1]);

        $this->app->instance(PanelPushDispatcher::class, $dispatcher);

        app(PanelPushService::class)->sendToSubscriptions(
            collect([$older, $newer]),
            'Teste',
            'Corpo',
            '/test',
            'pix_1'
        );

        $this->assertSame('https://push.example.com/sub/newer', $deliveredEndpoint);
    }

    public function test_send_to_subscriptions_deduplicates_by_endpoint(): void
    {
        $keys = $this->configureTestVapidPush();
        PanelPushSettings::storeVapidKeys($keys['publicKey'], $keys['privateKey']);

        $sellerA = $this->createSellerUser();
        $sellerB = $this->createSellerUser(['email' => 'seller-b-'.uniqid().'@example.com']);

        $sharedEndpoint = 'https://push.example.com/sub/shared';
        $subA = PanelPushSubscription::create([
            'user_id' => $sellerA->id,
            'tenant_id' => $sellerA->tenant_id,
            'provider' => PanelPushSubscription::PROVIDER_VAPID,
            'endpoint' => $sharedEndpoint,
            'keys' => ['auth' => 'dGVzdA', 'p256dh' => 'dGVzdA'],
            'vapid_public_key' => $keys['publicKey'],
        ]);
        $subB = PanelPushSubscription::create([
            'user_id' => $sellerB->id,
            'tenant_id' => $sellerB->tenant_id,
            'provider' => PanelPushSubscription::PROVIDER_VAPID,
            'endpoint' => $sharedEndpoint,
            'keys' => ['auth' => 'dGVzdB', 'p256dh' => 'dGVzdB'],
            'vapid_public_key' => $keys['publicKey'],
        ]);
        $subB->forceFill(['updated_at' => now()->subMinute()])->save();

        $dispatcher = Mockery::mock(PanelPushDispatcher::class);
        $dispatcher->shouldReceive('send')
            ->once()
            ->withArgs(function (Collection $subscriptions) {
                return $subscriptions->count() === 1;
            })
            ->andReturn(['sent' => 1, 'failed' => 0, 'invalid' => 0, 'expired' => 0, 'total' => 1]);

        $this->app->instance(PanelPushDispatcher::class, $dispatcher);

        app(PanelPushService::class)->sendToSubscriptions(
            collect([$subA, $subB]),
            'Teste',
            'Corpo',
            '/test'
        );
    }
}
