<?php

namespace App\Services\Push\Contracts;

use Illuminate\Support\Collection;

interface PanelPushChannel
{
    /**
     * @param  Collection<int, \App\Models\PanelPushSubscription>  $subscriptions
     * @return array{sent:int,failed:int,invalid:int,expired:int,total:int}
     */
    public function send(Collection $subscriptions, string $title, string $body, ?string $url = null): array;
}
