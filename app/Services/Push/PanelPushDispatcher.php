<?php

namespace App\Services\Push;

use App\Models\PanelPushSubscription;
use App\Support\PanelPushSettings;
use Illuminate\Support\Collection;

class PanelPushDispatcher
{
    public function __construct(
        protected VapidPushChannel $vapid,
        protected FcmPushChannel $fcm,
    ) {}

    /**
     * @param  Collection<int, PanelPushSubscription>  $subscriptions
     * @return array{sent:int,failed:int,invalid:int,expired:int,total:int}
     */
    public function send(Collection $subscriptions, string $title, string $body, ?string $url = null): array
    {
        $provider = PanelPushSettings::activeProvider();
        $filtered = $subscriptions->filter(function (PanelPushSubscription $s) use ($provider) {
            if ($provider === PanelPushSettings::PROVIDER_FCM) {
                return $s->isFcm();
            }

            return ! $s->isFcm();
        });

        if ($filtered->isEmpty()) {
            return ['sent' => 0, 'failed' => 0, 'invalid' => 0, 'expired' => 0, 'total' => 0];
        }

        $channel = $provider === PanelPushSettings::PROVIDER_FCM ? $this->fcm : $this->vapid;

        return $channel->send($filtered, $title, $body, $url);
    }
}
