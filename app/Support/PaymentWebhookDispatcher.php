<?php

namespace App\Support;

use App\Jobs\ProcessPaymentWebhook;

final class PaymentWebhookDispatcher
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function dispatch(
        string $gatewaySlug,
        string $transactionId,
        string $event,
        string $mappedStatus,
        array $payload = [],
    ): void {
        $job = new ProcessPaymentWebhook($gatewaySlug, $transactionId, $event, $mappedStatus, $payload);
        $job->onQueue((string) config('queue.webhooks_inbound_queue', 'webhooks-inbound'));

        if (config('getfy.api.inbound_webhooks_async', true) && config('queue.default') !== 'sync') {
            dispatch($job);

            return;
        }

        dispatch_sync($job);
    }
}
