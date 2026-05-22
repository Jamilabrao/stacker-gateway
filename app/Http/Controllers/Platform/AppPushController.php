<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PanelPushSubscription;
use App\Services\PanelPushService;
use App\Support\PanelPushSettings;
use App\Support\VapidEnvKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppPushController extends Controller
{
    public function clientConfig(): JsonResponse
    {
        if (! PanelPushSettings::isFcmConfigured()) {
            return response()->json(['enabled' => false]);
        }

        $client = PanelPushSettings::publicClientConfig();

        return response()->json([
            'enabled' => true,
            'push_provider' => PanelPushSettings::PROVIDER_FCM,
            'firebase' => $client['firebase'] ?? null,
            'firebase_web_vapid_key' => $client['firebase_web_vapid_key'] ?? null,
        ]);
    }

    public function data(): JsonResponse
    {
        return response()->json([
            'push' => PanelPushSettings::adminPayload(),
            'subscribers_count' => PanelPushSubscription::query()->count(),
            'subscribers_by_provider' => [
                'vapid' => PanelPushSubscription::query()->where('provider', PanelPushSubscription::PROVIDER_VAPID)->orWhereNull('provider')->count(),
                'fcm' => PanelPushSubscription::query()->where('provider', PanelPushSubscription::PROVIDER_FCM)->count(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'push_provider' => ['nullable', 'string', Rule::in([PanelPushSettings::PROVIDER_VAPID, PanelPushSettings::PROVIDER_FCM])],
            'pwa_vapid_public' => ['nullable', 'string', 'max:2048'],
            'pwa_vapid_private' => ['nullable', 'string', 'max:4096'],
            'firebase_project_id' => ['nullable', 'string', 'max:255'],
            'firebase_api_key' => ['nullable', 'string', 'max:512'],
            'firebase_messaging_sender_id' => ['nullable', 'string', 'max:64'],
            'firebase_app_id' => ['nullable', 'string', 'max:128'],
            'firebase_web_vapid_key' => ['nullable', 'string', 'max:2048'],
        ]);

        PanelPushSettings::saveGlobal($validated);

        return response()->json(['ok' => true, 'push' => PanelPushSettings::adminPayload()]);
    }

    public function uploadServiceAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:64', 'mimes:json,txt'],
        ]);

        $json = file_get_contents($validated['file']->getRealPath());
        if (! is_string($json) || trim($json) === '') {
            return response()->json(['message' => 'Arquivo vazio.'], 422);
        }

        try {
            PanelPushSettings::storeFirebaseServiceAccount($json);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'push' => PanelPushSettings::adminPayload()]);
    }

    public function generateVapid(): JsonResponse
    {
        $keys = PanelPushSettings::generateVapidKeyPair();
        PanelPushSettings::storeVapidKeys($keys['publicKey'], $keys['privateKey']);

        return response()->json([
            'ok' => true,
            'public_key' => $keys['publicKey'],
            'push' => PanelPushSettings::adminPayload(),
            'message' => 'Par VAPID gerado. Usuários com notificações ativas precisam reativar nos dispositivos.',
        ]);
    }

    public function clearOtherProviderSubscriptions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in([PanelPushSubscription::PROVIDER_VAPID, PanelPushSubscription::PROVIDER_FCM])],
        ]);

        $deleted = PanelPushSubscription::query()
            ->where('provider', $validated['provider'])
            ->delete();

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }

    public function subscribers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['nullable', 'string', Rule::in([PanelPushSubscription::PROVIDER_VAPID, PanelPushSubscription::PROVIDER_FCM])],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = PanelPushSubscription::query()
            ->with('user:id,name,email,tenant_id')
            ->orderByDesc('updated_at');

        if (! empty($validated['provider'])) {
            $query->where('provider', $validated['provider']);
        }

        if (! empty($validated['search'])) {
            $search = '%'.$validated['search'].'%';
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('email', 'like', $search)->orWhere('name', 'like', $search);
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 20);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (PanelPushSubscription $sub) => [
                'id' => $sub->id,
                'provider' => $sub->provider ?? PanelPushSubscription::PROVIDER_VAPID,
                'user_id' => $sub->user_id,
                'user_name' => $sub->user?->name,
                'user_email' => $sub->user?->email,
                'tenant_id' => $sub->tenant_id,
                'device_label' => $sub->device_label,
                'endpoint_preview' => $sub->isFcm()
                    ? (strlen((string) $sub->fcm_token) > 24 ? substr((string) $sub->fcm_token, 0, 12).'…' : $sub->fcm_token)
                    : (strlen((string) $sub->endpoint) > 40 ? substr((string) $sub->endpoint, 0, 40).'…' : $sub->endpoint),
                'last_used_at' => $sub->last_used_at?->toIso8601String(),
                'created_at' => $sub->created_at?->toIso8601String(),
                'updated_at' => $sub->updated_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function destroySubscriber(PanelPushSubscription $subscription): JsonResponse
    {
        $subscription->delete();

        return response()->json(['ok' => true]);
    }

    public function test(Request $request, PanelPushService $panelPushService): JsonResponse
    {
        if (! PanelPushSettings::isPushEnabled()) {
            return response()->json(['ok' => false, 'message' => 'Configure o provedor de push antes de testar.'], 422);
        }

        $user = $request->user();
        $subs = PanelPushSubscription::query()->where('user_id', $user->id)->get();
        if ($subs->isEmpty()) {
            return response()->json([
                'ok' => false,
                'message' => 'Nenhuma inscrição neste usuário. Abra o painel no PWA e ative notificações.',
            ], 422);
        }

        $result = $panelPushService->sendToSubscriptions(
            $subs,
            'Teste Getfy',
            'Notificação de teste enviada pelo painel da plataforma.',
            '/dashboard'
        );

        return response()->json([
            'ok' => ($result['sent'] ?? 0) > 0,
            'message' => ($result['sent'] ?? 0) > 0 ? null : 'Nenhum push entregue. Verifique o provedor e a inscrição.',
            'result' => $result,
        ]);
    }

    public function sendBroadcast(Request $request, PanelPushService $panelPushService): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:500'],
            'url' => ['nullable', 'string', 'max:2048'],
        ]);

        if (! PanelPushSettings::isPushEnabled()) {
            return response()->json([
                'ok' => false,
                'message' => 'Push não configurado. Configure em App → Notificações push.',
                'result' => ['sent' => 0, 'failed' => 0, 'invalid' => 0, 'expired' => 0, 'total' => 0],
            ], 422);
        }

        $subscriptionsCount = PanelPushSubscription::query()->count();
        if ($subscriptionsCount === 0) {
            return response()->json([
                'ok' => false,
                'message' => 'Nenhum dispositivo inscrito no painel.',
                'result' => ['sent' => 0, 'failed' => 0, 'invalid' => 0, 'expired' => 0, 'total' => 0],
            ], 422);
        }

        $activeProvider = PanelPushSettings::activeProvider();
        $matchingCount = PanelPushSubscription::query()->where('provider', $activeProvider)->count();
        if ($activeProvider === PanelPushSettings::PROVIDER_VAPID) {
            $matchingCount = PanelPushSubscription::query()
                ->where(function ($q) {
                    $q->where('provider', PanelPushSubscription::PROVIDER_VAPID)->orWhereNull('provider');
                })
                ->count();
        }

        if ($matchingCount === 0) {
            return response()->json([
                'ok' => false,
                'message' => 'Nenhuma inscrição para o provedor ativo ('.$activeProvider.'). Peça aos usuários que reativem notificações.',
                'result' => ['sent' => 0, 'failed' => 0, 'invalid' => 0, 'expired' => 0, 'total' => $subscriptionsCount],
            ], 422);
        }

        $result = $panelPushService->sendAndPersistToAll(
            'system',
            trim($validated['title']),
            trim($validated['body']),
            isset($validated['url']) && trim((string) $validated['url']) !== '' ? trim((string) $validated['url']) : null
        );

        $message = null;
        if (($result['sent'] ?? 0) === 0) {
            $message = 'Nenhum push foi entregue. Verifique credenciais e inscrições.';
        }

        return response()->json([
            'ok' => true,
            'message' => $message,
            'result' => $result,
        ]);
    }
}
