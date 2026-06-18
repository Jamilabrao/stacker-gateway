<?php

namespace App\Support;

final class ApiWebhookEvents
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $events = [];
        foreach (config('api_webhook_events.groups', []) as $group) {
            foreach (array_keys($group['events'] ?? []) as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @return array<string, array{label: string, events: array<string, string>}>
     */
    public static function catalog(): array
    {
        return config('api_webhook_events.groups', []);
    }
}
