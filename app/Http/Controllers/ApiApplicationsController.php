<?php

namespace App\Http\Controllers;

use App\Models\ApiApplication;
use App\Models\ApiKey;
use App\Models\ApiWebhookDelivery;
use App\Services\Api\ApiWebhookConfigService;
use App\Support\ApiScopes;
use App\Support\ApiWebhookEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ApiApplicationsController extends Controller
{
    private const WEBHOOK_SECRET_MASK = '__getfy_masked_webhook_secret__';

    public function __construct(
        protected ApiWebhookConfigService $webhookConfigService,
    ) {}

    public function index(): Response
    {
        $tenantId = auth()->user()->tenant_id;
        $keyReveal = null;
        $app = ApiApplication::ensureDefaultPixApplication($tenantId, $keyReveal);
        if ($keyReveal !== null) {
            session()->flash('api_key_reveal', $keyReveal);
            session()->flash('success', 'Suas chaves de API foram geradas. Guarde a secret em local seguro.');
        }

        return Inertia::render('ApiApplications/Index', $this->hubPayload($app));
    }

    public function create(): RedirectResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $keyReveal = null;
        ApiApplication::ensureDefaultPixApplication($tenantId, $keyReveal);
        if ($keyReveal !== null) {
            session()->flash('api_key_reveal', $keyReveal);
            session()->flash('success', 'Suas chaves de API foram geradas. Guarde a secret em local seguro.');
        }

        return redirect()->route('api-applications.index');
    }

    public function store(Request $request): RedirectResponse
    {
        return redirect()->route('api-applications.index');
    }

    public function edit(ApiApplication $apiApplication): RedirectResponse
    {
        $this->authorizeTenant($apiApplication);

        return redirect()->route('api-applications.index');
    }

    public function update(Request $request, ApiApplication $apiApplication): RedirectResponse
    {
        $this->authorizeTenant($apiApplication);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $apiApplication->update(array_filter([
            'name' => $validated['name'] ?? null,
            'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : null,
        ], fn ($v) => $v !== null));

        return redirect()->route('api-applications.index')->with('success', 'Integração atualizada.');
    }

    public function destroy(ApiApplication $apiApplication): RedirectResponse
    {
        $this->authorizeTenant($apiApplication);
        if ($apiApplication->isGlobalPixApplication()) {
            return redirect()->route('api-applications.index')->with('error', 'A integração API PIX padrão não pode ser excluída.');
        }
        $apiApplication->delete();

        return redirect()->route('api-applications.index')->with('success', 'Aplicação removida.');
    }

    public function regenerateKey(Request $request, ApiApplication $apiApplication): RedirectResponse
    {
        $this->authorizeTenant($apiApplication);

        $plainKey = 'getfy_'.Str::random(12).'_'.Str::random(32);
        $publicKey = ApiApplication::generatePublicKey();
        $secretKey = ApiApplication::generateSecretKey();
        $payload = [
            'api_key_hash' => ApiApplication::hashApiKey($plainKey),
            'public_key' => $publicKey,
            'secret_key_hash' => ApiApplication::hashSecretKey($secretKey),
        ];
        if (Schema::hasColumn($apiApplication->getTable(), 'secret_encrypted')) {
            $payload['secret_encrypted'] = ApiApplication::encryptSecretForStorage($secretKey);
        }
        $apiApplication->update($payload);

        if (Schema::hasColumn($apiApplication->getTable(), 'legacy_api_key_sha256')) {
            $apiApplication->forceFill(['legacy_api_key_sha256' => hash('sha256', $plainKey)])->saveQuietly();
        }

        return redirect()
            ->route('api-applications.index')
            ->with('api_key_reveal', [
                'public_key' => $publicKey,
                'secret_key' => $secretKey,
            ])
            ->with('success', 'Novas credenciais geradas. Copie agora; as credenciais anteriores deixam de funcionar.');
    }

    public function revealSecret(ApiApplication $apiApplication): JsonResponse
    {
        $this->authorizeTenant($apiApplication);

        if (! Schema::hasColumn($apiApplication->getTable(), 'secret_encrypted')) {
            return response()->json([
                'message' => 'Execute php artisan migrate para habilitar a revelação da secret.',
            ], 503);
        }

        $enc = $apiApplication->secret_encrypted;
        if (! is_string($enc) || $enc === '') {
            return response()->json([
                'message' => 'Regenere as chaves uma vez para habilitar a revelação da secret.',
            ], 422);
        }

        try {
            $plain = Crypt::decryptString($enc);
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Não foi possível revelar a secret. Regenere as chaves.',
            ], 422);
        }

        return response()->json(['secret_key' => $plain]);
    }

    public function storeApiKey(Request $request, ApiApplication $apiApplication): RedirectResponse
    {
        $this->authorizeTenant($apiApplication);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'in:'.implode(',', ApiScopes::all())],
        ]);

        $secret = ApiKey::generateSecretKey();
        $keyReveal = [
            'public_key' => ApiKey::generatePublicKey(),
            'secret_key' => $secret,
        ];

        $row = [
            'tenant_id' => $apiApplication->tenant_id,
            'api_application_id' => $apiApplication->id,
            'name' => $validated['name'],
            'public_key' => $keyReveal['public_key'],
            'secret_key_hash' => ApiKey::hashSecretKey($secret),
            'scopes' => array_values(array_unique($validated['scopes'])),
            'allowed_ips' => [],
            'strict_idempotency' => true,
            'async_payments' => false,
            'rate_limit_tier' => 'standard',
            'is_active' => true,
        ];
        if (Schema::hasColumn((new ApiKey)->getTable(), 'secret_encrypted')) {
            $row['secret_encrypted'] = ApiKey::encryptSecretForStorage($secret);
        }

        ApiKey::query()->create($row);

        return redirect()
            ->route('api-applications.index')
            ->with('api_key_reveal', $keyReveal)
            ->with('success', 'Nova chave API criada. Copie a secret agora; ela não será exibida novamente.');
    }

    public function updateApiKey(Request $request, ApiApplication $apiApplication, ApiKey $apiKey): RedirectResponse
    {
        $this->authorizeTenant($apiApplication);
        $this->assertApiKeyBelongsToApplication($apiApplication, $apiKey);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'in:'.implode(',', ApiScopes::all())],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $apiKey->update([
            'name' => $validated['name'],
            'scopes' => array_values(array_unique($validated['scopes'])),
            'is_active' => (bool) ($validated['is_active'] ?? $apiKey->is_active),
        ]);

        return redirect()->route('api-applications.index')->with('success', 'Chave API atualizada.');
    }

    public function regenerateApiKey(ApiApplication $apiApplication, ApiKey $apiKey): RedirectResponse
    {
        $this->authorizeTenant($apiApplication);
        $this->assertApiKeyBelongsToApplication($apiApplication, $apiKey);

        $secret = ApiKey::generateSecretKey();
        $publicKey = ApiKey::generatePublicKey();
        $payload = [
            'public_key' => $publicKey,
            'secret_key_hash' => ApiKey::hashSecretKey($secret),
        ];
        if (Schema::hasColumn($apiKey->getTable(), 'secret_encrypted')) {
            $payload['secret_encrypted'] = ApiKey::encryptSecretForStorage($secret);
        }
        $apiKey->update($payload);

        return redirect()
            ->route('api-applications.index')
            ->with('api_key_reveal', [
                'public_key' => $publicKey,
                'secret_key' => $secret,
            ])
            ->with('success', 'Chave rotacionada. Copie a nova secret agora.');
    }

    public function revealApiKeySecret(ApiApplication $apiApplication, ApiKey $apiKey): JsonResponse
    {
        $this->authorizeTenant($apiApplication);
        $this->assertApiKeyBelongsToApplication($apiApplication, $apiKey);

        if (! $apiKey->canRevealSecret()) {
            return response()->json([
                'message' => 'Regenere a chave uma vez para habilitar a revelação da secret.',
            ], 422);
        }

        try {
            $plain = Crypt::decryptString((string) $apiKey->secret_encrypted);
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Não foi possível revelar a secret. Regenere a chave.',
            ], 422);
        }

        return response()->json(['secret_key' => $plain]);
    }

    public function destroyApiKey(ApiApplication $apiApplication, ApiKey $apiKey): RedirectResponse
    {
        $this->authorizeTenant($apiApplication);
        $this->assertApiKeyBelongsToApplication($apiApplication, $apiKey);
        $apiKey->delete();

        return redirect()->route('api-applications.index')->with('success', 'Chave API removida.');
    }

    public function updateWebhook(Request $request, ApiApplication $apiApplication): RedirectResponse
    {
        $this->authorizeTenant($apiApplication);

        $allEvents = ApiWebhookEvents::all();
        $validated = $request->validate([
            'webhook_url' => ['nullable', 'string', 'url', 'max:512'],
            'webhook_secret' => ['nullable', 'string', 'max:64'],
            'webhook_events' => ['nullable', 'array'],
            'webhook_events.*' => ['string', Rule::in($allEvents)],
            'webhook_enabled' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'clear_webhook' => ['sometimes', 'boolean'],
        ]);

        if (! empty($validated['clear_webhook'])) {
            $this->webhookConfigService->clearWebhook($apiApplication);

            return redirect()->route('api-applications.index', ['tab' => 'webhooks'])
                ->with('success', 'Webhook removido.');
        }

        $webhookSecret = $validated['webhook_secret'] ?? '';
        if ($webhookSecret === self::WEBHOOK_SECRET_MASK) {
            $webhookSecret = '';
        }

        $events = $this->webhookConfigService->normalizePanelEvents($validated['webhook_events'] ?? null);
        $url = $validated['webhook_url'] ?? $apiApplication->webhook_url;
        $hadSecret = ($apiApplication->webhook_secret ?? '') !== '';

        $result = $this->webhookConfigService->updateFromPanel(
            $apiApplication,
            is_string($url) ? $url : null,
            $events,
            strlen($webhookSecret) > 0 ? $webhookSecret : null,
            $hadSecret,
            array_key_exists('webhook_enabled', $validated) ? (bool) $validated['webhook_enabled'] : null,
            array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : null,
        );

        $redirect = redirect()->route('api-applications.index', ['tab' => 'webhooks'])
            ->with('success', 'Webhook atualizado.');

        if ($result->revealedSecret !== null) {
            $redirect->with('webhook_secret_reveal', $result->revealedSecret);
        }

        return $redirect;
    }

    public function rotateWebhookSecret(ApiApplication $apiApplication): JsonResponse
    {
        $this->authorizeTenant($apiApplication);

        try {
            $secret = $this->webhookConfigService->rotateSecret($apiApplication);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['webhook_secret' => $secret]);
    }

    public function webhookDeliveries(Request $request, ApiApplication $apiApplication): JsonResponse
    {
        $this->authorizeTenant($apiApplication);

        $perPage = min(50, max(5, (int) $request->query('per_page', 15)));
        $page = max(1, (int) $request->query('page', 1));

        $query = ApiWebhookDelivery::query()
            ->where('api_application_id', $apiApplication->id)
            ->orderByDesc('created_at');

        $total = (clone $query)->count();
        $items = $query
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get(['id', 'event', 'status', 'last_status_code', 'attempt', 'created_at', 'delivered_at'])
            ->map(fn (ApiWebhookDelivery $d) => [
                'id' => $d->id,
                'event' => $d->event,
                'status' => $d->status,
                'last_status_code' => $d->last_status_code,
                'attempt' => $d->attempt,
                'created_at' => $d->created_at?->toIso8601String(),
                'delivered_at' => $d->delivered_at?->toIso8601String(),
            ]);

        return response()->json([
            'deliveries' => $items,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => (int) ceil($total / $perPage) ?: 1,
            ],
        ]);
    }

    public function updateApiPixToggle(Request $request): RedirectResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:inherit,enabled,disabled'],
        ]);

        ApiPixAccess::setTenantMode((int) $tenantId, $validated['mode']);

        return redirect()->route('api-applications.index')->with('success', 'Configuração da API PIX atualizada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function hubPayload(ApiApplication $app): array
    {
        $additionalKeys = ApiKey::query()
            ->where('api_application_id', $app->id)
            ->orderByDesc('id')
            ->get();

        $keys = collect([
            [
                'id' => 'legacy',
                'type' => 'legacy',
                'name' => $app->name ?: 'Integração principal',
                'public_key' => $app->public_key,
                'public_key_masked' => self::maskPublicKey($app->public_key),
                'scopes' => [ApiScopes::WILDCARD],
                'is_active' => (bool) $app->is_active,
                'can_reveal_secret' => self::apiApplicationCanRevealSecret($app),
                'can_delete' => false,
                'can_edit' => true,
                'created_at' => $app->created_at?->toIso8601String(),
            ],
        ])->merge($additionalKeys->map(fn (ApiKey $k) => [
            'id' => $k->id,
            'type' => 'scoped',
            'name' => $k->name,
            'public_key' => $k->public_key,
            'public_key_masked' => self::maskPublicKey($k->public_key),
            'scopes' => $k->scopes ?? [],
            'is_active' => (bool) $k->is_active,
            'can_reveal_secret' => $k->canRevealSecret(),
            'can_delete' => true,
            'can_edit' => true,
            'created_at' => $k->created_at?->toIso8601String(),
        ]))->values()->all();

        $recentDeliveries = ApiWebhookDelivery::query()
            ->where('api_application_id', $app->id)
            ->orderByDesc('created_at')
            ->limit(15)
            ->get(['id', 'event', 'status', 'last_status_code', 'attempt', 'created_at', 'delivered_at'])
            ->map(fn (ApiWebhookDelivery $d) => [
                'id' => $d->id,
                'event' => $d->event,
                'status' => $d->status,
                'last_status_code' => $d->last_status_code,
                'attempt' => $d->attempt,
                'created_at' => $d->created_at?->toIso8601String(),
                'delivered_at' => $d->delivered_at?->toIso8601String(),
            ])
            ->all();

        $hasWebhook = is_string($app->webhook_url) && $app->webhook_url !== '';

        return [
            'application' => [
                'id' => $app->id,
                'name' => $app->name,
                'public_key' => $app->public_key,
                'is_active' => (bool) $app->is_active,
                'is_legacy' => $app->isLegacyApplication(),
                'can_reveal_secret' => self::apiApplicationCanRevealSecret($app),
            ],
            'keys' => $keys,
            'webhook' => [
                'configured' => $hasWebhook,
                'url' => $app->webhook_url,
                'has_secret' => ($app->webhook_secret ?? '') !== '',
                'webhook_secret_mask' => self::WEBHOOK_SECRET_MASK,
                'events' => $app->webhook_events,
                'is_active' => Schema::hasColumn($app->getTable(), 'webhook_enabled')
                    ? (bool) ($app->webhook_enabled ?? true)
                    : (bool) $app->is_active,
            ],
            'webhook_event_catalog' => ApiWebhookEvents::catalog(),
            'available_scopes' => ApiScopes::all(),
            'scope_catalog' => ApiScopes::catalogList(),
            'recent_deliveries' => $recentDeliveries,
            'api_key_reveal' => session('api_key_reveal'),
            'webhook_secret_reveal' => session('webhook_secret_reveal'),
            'webhook_secret_mask' => self::WEBHOOK_SECRET_MASK,
        ];
    }

    private function authorizeTenant(ApiApplication $apiApplication): void
    {
        $tenantId = auth()->user()->tenant_id;
        if ($apiApplication->tenant_id !== $tenantId) {
            abort(404);
        }
    }

    private function assertApiKeyBelongsToApplication(ApiApplication $apiApplication, ApiKey $apiKey): void
    {
        if ((int) $apiKey->api_application_id !== (int) $apiApplication->id) {
            abort(404);
        }
    }

    private static function apiApplicationCanRevealSecret(ApiApplication $apiApplication): bool
    {
        if (! Schema::hasColumn($apiApplication->getTable(), 'secret_encrypted')) {
            return false;
        }

        return is_string($apiApplication->secret_encrypted) && $apiApplication->secret_encrypted !== '';
    }

    private static function maskPublicKey(?string $key): string
    {
        if (! is_string($key) || strlen($key) < 16) {
            return $key ?? '—';
        }

        return substr($key, 0, 10).'...'.substr($key, -6);
    }
}
