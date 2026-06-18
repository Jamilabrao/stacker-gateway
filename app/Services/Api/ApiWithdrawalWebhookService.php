<?php

namespace App\Services\Api;

use App\Models\ApiApplication;
use App\Models\Withdrawal;
use App\Support\ApiWebhookPayloadBuilder;

class ApiWithdrawalWebhookService
{
    public function __construct(
        private ApiWebhookDeliveryService $deliveryService,
    ) {}

    public function dispatchForWithdrawal(Withdrawal $withdrawal, string $event): void
    {
        if (! $withdrawal->api_application_id) {
            return;
        }

        $app = ApiApplication::query()->find($withdrawal->api_application_id);
        if (! $app) {
            return;
        }

        $this->deliveryService->dispatch(
            $app,
            $event,
            ApiWebhookPayloadBuilder::withdrawalPayload($withdrawal->fresh() ?? $withdrawal, $event),
            $withdrawal->api_key_id ? (int) $withdrawal->api_key_id : null,
        );
    }
}
