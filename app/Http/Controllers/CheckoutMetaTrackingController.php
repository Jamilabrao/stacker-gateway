<?php

namespace App\Http\Controllers;

use App\Models\CheckoutSession;
use App\Services\Meta\MetaTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutMetaTrackingController extends Controller
{
    public function store(Request $request, MetaTrackingService $trackingService): JsonResponse
    {
        $validated = $request->validate([
            'checkout_session_token' => ['required', 'string', 'max:64'],
            'event_name' => ['required', 'string', 'in:PageView,InitiateCheckout'],
            'event_id' => ['required', 'string', 'max:128'],
            'fbp' => ['nullable', 'string', 'max:512'],
            'fbc' => ['nullable', 'string', 'max:512'],
            'user_agent' => ['nullable', 'string', 'max:1024'],
            'event_source_url' => ['nullable', 'string', 'max:2048'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'content_ids' => ['nullable', 'array'],
            'content_ids.*' => ['string', 'max:128'],
            'content_name' => ['nullable', 'string', 'max:255'],
        ]);

        $session = CheckoutSession::where('session_token', $validated['checkout_session_token'])->first();
        if (! $session) {
            return response()->json(['ok' => false, 'message' => 'Sessão não encontrada.'], 404);
        }

        $trackingService->persistSessionAttribution($session, $validated);

        $session->refresh();

        $overrides = array_filter([
            'fbp' => $validated['fbp'] ?? null,
            'fbc' => $validated['fbc'] ?? null,
            'user_agent' => $validated['user_agent'] ?? null,
            'event_source_url' => $validated['event_source_url'] ?? null,
            'value' => isset($validated['value']) ? (float) $validated['value'] : null,
            'currency' => $validated['currency'] ?? null,
            'content_ids' => $validated['content_ids'] ?? null,
            'content_name' => $validated['content_name'] ?? null,
        ], fn ($v) => $v !== null);

        $queued = $trackingService->queueSessionEvent(
            $session,
            $validated['event_name'],
            $validated['event_id'],
            $overrides,
        );

        return response()->json([
            'ok' => true,
            'queued' => count($queued),
        ]);
    }
}
