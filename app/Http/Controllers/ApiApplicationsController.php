<?php

namespace App\Http\Controllers;

use App\Gateways\GatewayRegistry;
use App\Models\ApiApplication;
use App\Services\StorageService;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ApiApplicationsController extends Controller
{
    private const WEBHOOK_SECRET_MASK = '__getfy_masked_webhook_secret__';

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildGatewaysList(?int $tenantId): array
    {
        $all = GatewayRegistry::allowedAcquirers();
        return array_map(function ($g) {
            $image = $g['image'] ?? null;
            return [
                'slug' => $g['slug'],
                'name' => $g['name'],
                'image' => GatewayRegistry::resolveImageUrl(is_string($image) ? $image : null),
                'methods' => $g['methods'] ?? [],
            ];
        }, $all);
    }

    /**
     * Gateways grouped by method (pix, card, boleto, etc.) for dropdowns.
     *
     * @return array<string, array<int, array{slug: string, name: string, image: string|null}>>
     */
    private function gatewaysByMethod(?int $tenantId): array
    {
        $list = $this->buildGatewaysList($tenantId);
        $byMethod = ['pix' => [], 'card' => [], 'boleto' => [], 'pix_auto' => [], 'crypto' => []];
        foreach ($list as $g) {
            foreach ($g['methods'] ?? [] as $method) {
                if (isset($byMethod[$method])) {
                    $byMethod[$method][] = ['slug' => $g['slug'], 'name' => $g['name'], 'image' => $g['image'] ?? null];
                }
            }
        }
        return $byMethod;
    }

    public function index(): Response
    {
        $tenantId = auth()->user()->tenant_id;
        $keyReveal = null;
        $default = ApiApplication::ensureDefaultPixApplication($tenantId, $keyReveal);
        if ($keyReveal !== null) {
            session()->flash('api_key_reveal', $keyReveal);
            session()->flash('success', 'Suas chaves de API foram geradas. Guarde a secret em local seguro.');
        }

        return Inertia::render('ApiApplications/Index', [
            'pix_application' => [
                'id' => $default->id,
                'public_key' => $default->public_key,
                'can_reveal_secret' => self::apiApplicationCanRevealSecret($default),
            ],
            'api_key_reveal' => session('api_key_reveal'),
        ]);
    }

    public function create(): RedirectResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $keyReveal = null;
        $app = ApiApplication::ensureDefaultPixApplication($tenantId, $keyReveal);
        if ($keyReveal !== null) {
            session()->flash('api_key_reveal', $keyReveal);
            session()->flash('success', 'Suas chaves de API foram geradas. Guarde a secret em local seguro.');
        }

        return redirect()->route('api-applications.edit', $app);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $keyReveal = null;
        $app = ApiApplication::ensureDefaultPixApplication($tenantId, $keyReveal);
        if ($keyReveal !== null) {
            session()->flash('api_key_reveal', $keyReveal);
        }

        $this->authorizeTenant($app);
        $this->syncApplicationFromRequest($request, $app);

        return redirect()
            ->route('api-applications.edit', $app)
            ->with('success', 'Configuração salva.');
    }

    public function edit(ApiApplication $apiApplication): Response|RedirectResponse
    {
        $this->authorizeTenant($apiApplication);

        $storage = new StorageService($apiApplication->tenant_id);
        $logoUrl = $apiApplication->logo ? $storage->url($apiApplication->logo) : null;

        return Inertia::render('ApiApplications/Edit', [
            'application' => [
                'id' => $apiApplication->id,
                'name' => $apiApplication->name,
                'slug' => $apiApplication->slug,
                'logo_url' => $logoUrl,
                'checkout_sidebar_bg' => $apiApplication->checkout_sidebar_bg,
                'webhook_url' => $apiApplication->webhook_url,
                'default_return_url' => $apiApplication->default_return_url,
                'webhook_secret' => ($apiApplication->webhook_secret ?? '') !== '' ? self::WEBHOOK_SECRET_MASK : '',
                'allowed_ips' => is_array($apiApplication->allowed_ips) ? implode("\n", $apiApplication->allowed_ips) : '',
                'is_active' => $apiApplication->is_active,
                'public_key' => $apiApplication->public_key,
                'is_global_pix_application' => $apiApplication->isGlobalPixApplication(),
                'can_reveal_secret' => self::apiApplicationCanRevealSecret($apiApplication),
            ],
            'api_key_reveal' => session('api_key_reveal'),
            'webhook_secret_mask' => self::WEBHOOK_SECRET_MASK,
        ]);
    }

    public function update(Request $request, ApiApplication $apiApplication): RedirectResponse
    {
        $this->authorizeTenant($apiApplication);
        $this->syncApplicationFromRequest($request, $apiApplication);

        return redirect()->route('api-applications.edit', $apiApplication)->with('success', 'Aplicação atualizada.');
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
        $validated = $request->validate([
            'return_to' => ['sometimes', 'string', 'in:index,edit'],
        ]);
        $toIndex = ($validated['return_to'] ?? 'edit') === 'index';

        $plainKey = 'getfy_' . Str::random(12) . '_' . Str::random(32);
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

        $reveal = [
            'public_key' => $publicKey,
            'secret_key' => $secretKey,
        ];

        if ($toIndex) {
            return redirect()
                ->route('api-applications.index')
                ->with('api_key_reveal', $reveal)
                ->with('success', 'Novas credenciais geradas. Copie agora; as credenciais anteriores deixam de funcionar.');
        }

        return redirect()
            ->route('api-applications.edit', $apiApplication)
            ->with('api_key_reveal', $reveal)
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

    public function uploadLogo(Request $request, ApiApplication $apiApplication): JsonResponse
    {
        $this->authorizeTenant($apiApplication);

        $request->validate([
            'image' => ['required', 'image', 'max:5120'],
        ]);

        $storage = new StorageService($apiApplication->tenant_id);
        $oldPath = $apiApplication->logo;
        if ($oldPath && $storage->exists($oldPath)) {
            $storage->delete($oldPath);
        }

        $file = $request->file('image');
        $name = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $storage->putFileAs('api-applications/' . $apiApplication->id, $file, $name);
        $apiApplication->update(['logo' => $path]);
        $url = $storage->url($path);

        return response()->json(['url' => $url], HttpResponse::HTTP_CREATED);
    }

    public function removeLogo(ApiApplication $apiApplication): JsonResponse
    {
        $this->authorizeTenant($apiApplication);

        $storage = new StorageService($apiApplication->tenant_id);
        $oldPath = $apiApplication->logo;
        if ($oldPath && $storage->exists($oldPath)) {
            $storage->delete($oldPath);
        }
        $apiApplication->update(['logo' => null]);

        return response()->json(['success' => true]);
    }

    public function updateApiPixToggle(Request $request): RedirectResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:inherit,enabled,disabled'],
        ]);

        if ($validated['mode'] === 'inherit') {
            Setting::query()
                ->where('key', 'api_pix_enabled')
                ->where('tenant_id', $tenantId)
                ->delete();
        } else {
            Setting::set('api_pix_enabled', $validated['mode'] === 'enabled', $tenantId);
        }

        return redirect()->route('api-applications.index')->with('success', 'Configuração da API PIX atualizada.');
    }

    private function authorizeTenant(ApiApplication $apiApplication): void
    {
        $tenantId = auth()->user()->tenant_id;
        if ($apiApplication->tenant_id !== $tenantId) {
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

    private function syncApplicationFromRequest(Request $request, ApiApplication $apiApplication): void
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'webhook_url' => ['nullable', 'string', 'url', 'max:512'],
            'default_return_url' => ['nullable', 'string', 'url', 'max:512'],
            'webhook_secret' => ['nullable', 'string', 'max:64'],
            'allowed_ips' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'checkout_sidebar_bg' => ['nullable', 'string', 'max:32', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
        ]);

        $allowedIps = [];
        if (! empty($validated['allowed_ips'])) {
            $lines = preg_split('/\s*[\r\n,]+\s*/', trim($validated['allowed_ips']), -1, PREG_SPLIT_NO_EMPTY);
            $allowedIps = array_values(array_unique(array_filter(array_map('trim', $lines))));
        }

        $webhookSecret = $validated['webhook_secret'] ?? '';
        if ($webhookSecret === self::WEBHOOK_SECRET_MASK) {
            $webhookSecret = '';
        }
        $apiApplication->update([
            'name' => $validated['name'],
            'allowed_ips' => $allowedIps,
            'webhook_url' => $validated['webhook_url'] ?? null,
            'default_return_url' => $validated['default_return_url'] ?? null,
            'webhook_secret' => strlen($webhookSecret) > 0 ? $webhookSecret : $apiApplication->webhook_secret,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'checkout_sidebar_bg' => $this->normalizeCheckoutSidebarBg($validated['checkout_sidebar_bg'] ?? null),
        ]);
    }

    /** Default black (#18181b = zinc-900); store null when default. */
    private function normalizeCheckoutSidebarBg(?string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '' || $v === '#18181b') {
            return null;
        }
        return $v;
    }
}
