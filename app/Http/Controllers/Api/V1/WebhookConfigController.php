<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Api\ApiAuthContext;
use App\Services\Api\ApiWebhookConfigService;
use App\Services\SellerActivityLogService;
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

        $this->logApiWebhook(
            $ctx,
            SellerActivityLogService::API_WEBHOOK_UPDATED,
            [
                'webhook_url' => $validated['webhook_url'],
                'webhook_enabled' => (bool) ($validated['webhook_enabled'] ?? true),
                'rotate_secret' => (bool) ($validated['rotate_secret'] ?? false),
            ]
        );

        if (! empty($validated['rotate_secret'])) {
            $this->logApiWebhook($ctx, SellerActivityLogService::API_WEBHOOK_SECRET_ROTATED);
        }

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

        $this->logApiWebhook($ctx, SellerActivityLogService::API_WEBHOOK_SECRET_ROTATED);

        return response()->json([
            'webhook_secret' => $secret,
            'has_secret' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function logApiWebhook(ApiAuthContext $ctx, string $action, array $metadata = []): void
    {
        $owner = User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->where(function ($q) use ($ctx) {
                $q->where('id', $ctx->application->tenant_id)
                    ->orWhere('tenant_id', $ctx->application->tenant_id);
            })
            ->first();

        SellerActivityLogService::record(
            actor: $owner,
            action: $action,
            targetType: $ctx->application::class,
            targetId: $ctx->application->id,
            metadata: $metadata,
            tenantId: (int) $ctx->application->tenant_id,
            source: 'api',
        );
    }
}
