<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Decide se jobs devem rodar em sync (sem worker / fila parada).
 */
final class QueueSyncDispatch
{
    public static function shouldRunSynchronously(): bool
    {
        if (config('queue.default') === 'sync') {
            return true;
        }

        $heartbeat = Cache::get('queue_heartbeat');
        if (! is_string($heartbeat) || $heartbeat === '') {
            return true;
        }

        try {
            $last = \Illuminate\Support\Carbon::parse($heartbeat);
        } catch (\Throwable) {
            return true;
        }

        return $last->lt(now()->subMinutes(3));
    }
}
