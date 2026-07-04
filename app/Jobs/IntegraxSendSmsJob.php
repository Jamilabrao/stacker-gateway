<?php

namespace App\Jobs;

use App\Models\IntegraxSmsDispatch;
use App\Services\Integrax\IntegraxService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IntegraxSendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(
        public int $dispatchId
    ) {
        $this->tries = (int) config('integrax.retry.tries', 5);
        $this->timeout = (int) config('integrax.retry.timeout', 60);
        $this->onQueue((string) config('integrax.queue', 'integrax-sms'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $backoff = config('integrax.retry.backoff', [30, 60, 120, 300, 600]);

        return is_array($backoff) ? array_map('intval', $backoff) : [30, 60, 120];
    }

    public function handle(IntegraxService $integraxService): void
    {
        $dispatch = IntegraxSmsDispatch::query()->find($this->dispatchId);
        if (! $dispatch || $dispatch->status === IntegraxSmsDispatch::STATUS_SENT) {
            return;
        }

        try {
            $integraxService->sendSms($dispatch->phone, $dispatch->message);

            $dispatch->update([
                'status' => IntegraxSmsDispatch::STATUS_SENT,
                'sent_at' => now(),
                'error' => null,
            ]);

            Log::info('IntegraxSendSmsJob sent', [
                'dispatch_id' => $dispatch->id,
                'event_type' => $dispatch->event_type,
            ]);
        } catch (\Throwable $e) {
            $dispatch->update([
                'status' => IntegraxSmsDispatch::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            Log::warning('IntegraxSendSmsJob failed', [
                'dispatch_id' => $dispatch->id,
                'event_type' => $dispatch->event_type,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $dispatch = IntegraxSmsDispatch::query()->find($this->dispatchId);
        if (! $dispatch) {
            return;
        }

        $dispatch->update([
            'status' => IntegraxSmsDispatch::STATUS_FAILED,
            'error' => $exception !== null
                ? mb_substr($exception->getMessage(), 0, 500)
                : null,
        ]);
    }
}
