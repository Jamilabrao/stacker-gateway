<?php

namespace App\Jobs;

use App\Services\DailySalesPushService;
use App\Support\DailySalesPushSettings;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

class ProcessDailySalesPushJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public function handle(DailySalesPushService $service): void
    {
        if (! DailySalesPushSettings::enabled()) {
            return;
        }

        $tz = DailySalesPushSettings::timezone();
        $reference = Carbon::now($tz)->startOfDay();
        $service->processReferenceDate($reference);
    }
}
