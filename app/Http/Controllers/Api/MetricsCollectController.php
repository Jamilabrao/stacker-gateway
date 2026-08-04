<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetricsEvent;
use App\Services\MetricsTracking\MetricsCaptureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MetricsCollectController extends Controller
{
    public function __invoke(Request $request, MetricsCaptureService $capture): JsonResponse
    {
        // Sempre 202/200 mesmo se tracking estiver desligado — não afeta checkout.
        if (! $capture->enabled()) {
            return response()->json(['ok' => true, 'skipped' => true]);
        }

        $data = $request->validate([
            'event_name' => ['required', 'string', 'max:64'],
            'event_id' => ['nullable', 'string', 'max:128'],
            'session_key' => ['nullable', 'uuid'],
            'visitor_key' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'tenant_id' => ['nullable', 'integer'],
            'offer_id' => ['nullable', 'integer'],
            'plan_id' => ['nullable', 'integer'],
            'checkout_session_id' => ['nullable', 'integer'],
            'order_id' => ['nullable', 'integer'],
            'affiliate_ref' => ['nullable', 'string', 'max:64'],
            'destination_url' => ['nullable', 'string', 'max:2048'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'tracking' => ['nullable', 'array'],
            'properties' => ['nullable', 'array'],
        ]);

        $allowed = [
            MetricsEvent::PAGE_VIEW,
            MetricsEvent::CHECKOUT_VIEW,
            MetricsEvent::CHECKOUT_STARTED,
            MetricsEvent::LINK_CLICKED,
            MetricsEvent::PIX_CREATED,
        ];
        if (! in_array($data['event_name'], $allowed, true)) {
            return response()->json(['ok' => true, 'skipped' => true]);
        }

        $sessionKey = $capture->capture($request, [
            'event_name' => $data['event_name'],
            'event_id' => $data['event_id'] ?? (string) Str::uuid(),
            'session_key' => $data['session_key'] ?? null,
            'visitor_key' => $data['visitor_key'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'tenant_id' => $data['tenant_id'] ?? null,
            'offer_id' => $data['offer_id'] ?? null,
            'plan_id' => $data['plan_id'] ?? null,
            'checkout_session_id' => $data['checkout_session_id'] ?? null,
            'order_id' => $data['order_id'] ?? null,
            'affiliate_ref' => $data['affiliate_ref'] ?? null,
            'destination_url' => $data['destination_url'] ?? null,
            'referrer' => $data['referrer'] ?? null,
            'tracking' => $data['tracking'] ?? null,
            'properties' => $data['properties'] ?? null,
        ]);

        return response()->json([
            'ok' => true,
            'session_key' => $sessionKey,
        ]);
    }
}
