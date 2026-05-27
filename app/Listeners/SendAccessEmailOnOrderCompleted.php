<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Services\AccessEmailService;
use Illuminate\Support\Facades\Log;

class SendAccessEmailOnOrderCompleted
{
    public function __construct(
        protected AccessEmailService $accessEmailService
    ) {}

    public function handle(OrderCompleted $event): void
    {
        $order = $event->order;
        Log::info('SendAccessEmailOnOrderCompleted: disparando envio de e-mail de acesso.', ['order_id' => $order->id]);

        try {
            $result = $this->accessEmailService->sendForOrder($order);
            if (! $result->success) {
                Log::warning('SendAccessEmailOnOrderCompleted: sendForOrder falhou.', [
                    'order_id' => $order->id,
                    'reason' => $result->reason,
                    'message' => $result->message,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('SendAccessEmailOnOrderCompleted: exceção ao enviar e-mail.', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            report($e);
        }
    }
}
