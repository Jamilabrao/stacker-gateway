<?php

namespace App\Jobs;

use App\Services\PanelPushCampaignService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessPanelPushCampaignJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [15, 30, 60, 120, 180];

    public int $uniqueFor = 600;

    public function __construct(public int $campaignId) {}

    public function uniqueId(): string
    {
        return 'panel-push-campaign:'.$this->campaignId;
    }

    public function handle(PanelPushCampaignService $service): void
    {
        $service->process($this->campaignId);
    }

    public function failed(?Throwable $exception): void
    {
        $message = $exception?->getMessage() ?? 'Falha desconhecida no job de push.';
        Log::error('push_campaign_job_failed', [
            'campaign_id' => $this->campaignId,
            'error' => mb_substr($message, 0, 500),
            'attempts' => $this->attempts(),
        ]);

        try {
            app(PanelPushCampaignService::class)->markFailedAfterRetries(
                $this->campaignId,
                'Esgotadas '.$this->tries.' tentativas: '.$message
            );
        } catch (Throwable $e) {
            Log::error('push_campaign_mark_failed_error', [
                'campaign_id' => $this->campaignId,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);
        }
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    /**
     * Em falha transitória, deixa o Laravel re-tentar com backoff.
     */
    public function middleware(): array
    {
        return [];
    }
}
