<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Api\ApiAuthContext;
use App\Services\Api\ApiWebhookConfigService;
use App\Support\ApiScopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WebhookConfigController extends Controller
{
    public function __construct(
        protected ApiWebhookConfigService $webhookConfigService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $ctx = ApiAuthContext::fromRequest($request);
        if (! $ctx->hasScope(ApiScopes::WEBHOOKS_READ)) {
            return response()->json(['message' => 'Insufficient API key permissions.'], 403);
        }

        return response()->json(
            $this->webhookConfigService->toResponseArray($ctx->application)
        );
    }

    public function update(Request $request): JsonResponse
    {
        $ctx = ApiAuthContext::fromRequest($request);
        if (! $ctx->hasScope(ApiScopes::WEBHOOKS_WRITE)) {
            return response()->json(['message' => 'Insufficient API key permissions.'], 403);
        }

        $validated = $request->validate([
            'webhook_url' => ['required', 'string', 'url', 'max:512', 'starts_with:https://'],
            'webhook_enabled' => ['sometimes', 'boolean'],
            'rotate_secret' => ['sometimes', 'boolean'],
        ]);

        $result = $this->webhookConfigService->provisionForApi(
            $ctx->application,
            $validated['webhook_url'],
            (bool) ($validated['webhook_enabled'] ?? true),
            (bool) ($validated['rotate_secret'] ?? false),
        );

        return response()->json(
            $this->webhookConfigService->toResponseArray($result->application, $result->revealedSecret)
        );
    }

    public function rotateSecret(Request $request): JsonResponse
    {
        $ctx = ApiAuthContext::fromRequest($request);
        if (! $ctx->hasScope(ApiScopes::WEBHOOKS_WRITE)) {
            return response()->json(['message' => 'Insufficient API key permissions.'], 403);
        }

        try {
            $secret = $this->webhookConfigService->rotateSecret($ctx->application);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'webhook_url' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'webhook_secret' => $secret,
            'has_secret' => true,
        ]);
    }
}
