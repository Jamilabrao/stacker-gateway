<?php

namespace App\Support;

use Illuminate\Support\Str;

final class IntegrationJobDispatch
{
    /**
     * Envia jobs de integração de forma síncrona em ambientes sync/database ou com INTEGRATIONS_DISPATCH_SYNC.
     */
    public static function shouldDispatchSync(): bool
    {
        $default = (string) config('queue.default', 'sync');
        if ($default === 'sync' || $default === 'database') {
            return true;
        }

        $v = (string) env('INTEGRATIONS_DISPATCH_SYNC', '');
        if ($v !== '' && in_array(Str::lower(trim($v)), ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        return false;
    }
}
