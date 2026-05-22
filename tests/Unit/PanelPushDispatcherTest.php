<?php

namespace Tests\Unit;

use App\Models\PanelPushSubscription;
use App\Services\Push\FcmPushChannel;
use App\Services\Push\PanelPushDispatcher;
use App\Services\Push\VapidPushChannel;
use App\Support\PanelPushSettings;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class PanelPushDispatcherTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_dispatcher_uses_vapid_channel_for_vapid_subscriptions(): void
    {
        config(['getfy.pwa.push_provider' => PanelPushSettings::PROVIDER_VAPID]);

        $vapid = Mockery::mock(VapidPushChannel::class);
        $fcm = Mockery::mock(FcmPushChannel::class);
        $vapid->shouldReceive('send')->once()->andReturn([
            'sent' => 1, 'failed' => 0, 'invalid' => 0, 'expired' => 0, 'total' => 1,
        ]);
        $fcm->shouldNotReceive('send');

        $sub = new PanelPushSubscription([
            'provider' => PanelPushSubscription::PROVIDER_VAPID,
            'endpoint' => 'https://example.com/push',
        ]);

        $dispatcher = new PanelPushDispatcher($vapid, $fcm);
        $result = $dispatcher->send(Collection::make([$sub]), 'T', 'B', null);

        $this->assertSame(1, $result['sent']);
    }

    public function test_dispatcher_filters_fcm_when_provider_is_vapid(): void
    {
        config(['getfy.pwa.push_provider' => PanelPushSettings::PROVIDER_VAPID]);

        $vapid = Mockery::mock(VapidPushChannel::class);
        $fcm = Mockery::mock(FcmPushChannel::class);
        $vapid->shouldNotReceive('send');
        $fcm->shouldNotReceive('send');

        $sub = new PanelPushSubscription([
            'provider' => PanelPushSubscription::PROVIDER_FCM,
            'fcm_token' => 'token123',
        ]);

        $dispatcher = new PanelPushDispatcher($vapid, $fcm);
        $result = $dispatcher->send(Collection::make([$sub]), 'T', 'B', null);

        $this->assertSame(0, $result['total']);
    }
}
