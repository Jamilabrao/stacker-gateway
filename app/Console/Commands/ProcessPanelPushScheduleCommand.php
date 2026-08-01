<?php

namespace App\Console\Commands;

use App\Jobs\ProcessDailySalesPushJob;
use App\Services\PanelPushCampaignService;
use App\Support\DailySalesPushSettings;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ProcessPanelPushScheduleCommand extends Command
{
    protected $signature = 'push:process-schedule';

    protected $description = 'Dispara campanhas push vencidas e o resumo diário no horário configurado';

    public function handle(PanelPushCampaignService $campaigns): int
    {
        $claimed = $campaigns->claimDueCampaigns(30);
        $this->info("Campanhas enfileiradas: {$claimed}");

        if (DailySalesPushSettings::enabled()) {
            $tz = DailySalesPushSettings::timezone();
            $now = Carbon::now($tz);
            $target = DailySalesPushSettings::time(); // HH:mm
            [$h, $m] = array_map('intval', explode(':', $target));
            if ((int) $now->format('H') === $h && (int) $now->format('i') === $m) {
                $lockKey = 'daily_sales_push_dispatch:'.$now->toDateString();
                if (Cache::add($lockKey, 1, now()->addHours(20))) {
                    ProcessDailySalesPushJob::dispatch();
                    $this->info('Resumo diário enfileirado.');
                }
            }
        }

        return self::SUCCESS;
    }
}
