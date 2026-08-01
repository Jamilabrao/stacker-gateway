<?php

namespace App\Jobs;

use App\Services\PanelPushCampaignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessPanelPushCampaignJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $campaignId) {}

    public function handle(PanelPushCampaignService $service): void
    {
        $service->process($this->campaignId);
    }
}
