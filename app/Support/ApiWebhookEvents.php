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

    /**
     * Valor persistido para inscrição em todos os eventos (null = sem filtro).
     */
    public static function allSubscription(): ?array
    {
        return null;
    }

    public static function isAllSubscription(?array $events): bool
    {
        if ($events === null) {
            return true;
        }

        return count($events) === 0 || count($events) >= count(self::all());
    }
}
