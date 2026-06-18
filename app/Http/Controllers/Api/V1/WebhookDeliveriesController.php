<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverApiWebhookJob;
use App\Models\ApiWebhookDelivery;
use App\Services\Api\ApiAuthContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookDeliveriesController extends Controller
{
    public function retry(Request $request, string $deliveryId): JsonResponse
    {
        $ctx = ApiAuthContext::fromRequest($request);
        if (! $ctx->hasScope(\App\Support\ApiScopes::PAYMENTS_READ)) {
            return response()->json(['message' => 'Insufficient API key permissions.'], 403);
        }

        $delivery = ApiWebhookDelivery::query()
            ->where('tenant_id', $ctx->application->tenant_id)
            ->whereKey($deliveryId)
            ->first();

        if ($delivery === null) {
            return response()->json(['message' => 'Entrega não encontrada.'], 404);
        }

        $delivery->update([
            'status' => ApiWebhookDelivery::STATUS_PENDING,
            'next_retry_at' => now(),
        ]);

        DeliverApiWebhookJob::dispatch($delivery->id);

        return response()->json(['message' => 'Reenvio agendado.', 'delivery_id' => $delivery->id]);
    }
}
