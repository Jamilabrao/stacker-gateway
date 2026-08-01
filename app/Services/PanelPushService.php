<?php

namespace App\Services;

use App\Models\PanelNotification;
use App\Models\PanelPushSubscription;
use App\Services\Push\PanelPushDispatcher;
use App\Support\UserPushPreferences;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PanelPushService
{
    public function __construct(
        protected PanelPushDispatcher $dispatcher,
    ) {}

    /**
     * Envia push para o tenant e persiste uma notificação por usuário (para o centro de notificações).
     *
     * @param  string  $type  Tipo para o centro de notificações: sale_approved, pix_generated, boleto_generated, etc.
     * @param  string|null  $eventKey  Chave única do evento (ex: order_123). Quando informada, evita duplicar notificação para o mesmo evento.
     */
    public function sendAndPersistToTenant(?int $tenantId, string $type, string $title, string $body, ?string $url = null, ?string $eventKey = null): int
    {
        if ($tenantId && ! UserPushPreferences::allowsEvent((int) $tenantId, $type)) {
            Log::info('PanelPushService: evento desativado nas preferências', [
                'tenant_id' => $tenantId,
                'type' => $type,
            ]);

            return 0;
        }

        $subscriptions = $this->subscriptionsForDelivery(
            PanelPushSubscription::where('tenant_id', $tenantId)->get()
        );

        if ($subscriptions->isEmpty()) {
            return 0;
        }

        $userIds = $subscriptions->pluck('user_id')->unique()->filter()->values();

        foreach ($userIds as $userId) {
            $attrs = [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'url' => $url,
            ];
            if ($eventKey !== null && $eventKey !== '') {
                PanelNotification::firstOrCreate(
                    [
                        'user_id' => $userId,
                        'event_key' => $eventKey,
                    ],
                    array_merge($attrs, ['event_key' => $eventKey])
                );
            } else {
                PanelNotification::create($attrs);
            }
        }

        $shouldSendPush = true;
        if ($eventKey !== null && $eventKey !== '') {
            if (! Cache::add('panel_push_sent:'.$eventKey, 1, now()->addSeconds(60))) {
                $shouldSendPush = false;
            }
        }

        if (! $shouldSendPush) {
            Log::info('PanelPushService: push omitido (event_key já enviado recentemente)', [
                'tenant_id' => $tenantId,
                'type' => $type,
                'event_key' => $eventKey,
            ]);

            return 0;
        }

        $result = $this->sendToSubscriptions($subscriptions, $title, $body, $url, $eventKey);
        $sent = (int) ($result['sent'] ?? 0);

        Log::info('PanelPushService: sendAndPersistToTenant', [
            'tenant_id' => $tenantId,
            'type' => $type,
            'event_key' => $eventKey,
            'sent' => $sent,
            'total_subscriptions' => $subscriptions->count(),
        ]);

        return $sent;
    }

    /**
     * Envia push global para todos os assinantes do painel e persiste no centro de notificações.
     *
     * @return array{sent:int,failed:int,invalid:int,expired:int,total:int}
     */
    public function sendAndPersistToAll(string $type, string $title, string $body, ?string $url = null): array
    {
        $subscriptions = $this->subscriptionsForDelivery(PanelPushSubscription::query()->get());
        $subscriptions = $subscriptions->filter(function (PanelPushSubscription $sub) use ($type) {
            return UserPushPreferences::allowsEvent((int) $sub->user_id, $type);
        })->values();

        $userIds = $subscriptions->pluck('user_id')->unique()->filter()->values();
        foreach ($userIds as $userId) {
            PanelNotification::create([
                'tenant_id' => null,
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'url' => $url,
            ]);
        }

        return $this->sendToSubscriptions($subscriptions, $title, $body, $url);
    }

    public function sendToTenant(?int $tenantId, string $title, string $body, ?string $url = null): int
    {
        $subscriptions = $this->subscriptionsForDelivery(
            PanelPushSubscription::where('tenant_id', $tenantId)->get()
        );
        if ($subscriptions->isEmpty()) {
            Log::warning('PanelPushService: nenhuma inscrição push para o tenant', ['tenant_id' => $tenantId]);

            return 0;
        }

        $result = $this->sendToSubscriptions($subscriptions, $title, $body, $url);

        return (int) ($result['sent'] ?? 0);
    }

    /**
     * @param  Collection<int, PanelPushSubscription>  $subscriptions
     * @return array{sent:int,failed:int,invalid:int,expired:int,total:int}
     */
    public function sendToSubscriptions(Collection $subscriptions, string $title, string $body, ?string $url = null, ?string $tag = null): array
    {
        try {
            \App\Support\PanelPushSettings::applyToConfig();
        } catch (\Throwable) {
            //
        }

        if (! \App\Support\PanelPushSettings::isPushEnabled()) {
            Log::warning('PanelPushService: push não configurado no admin');

            return ['sent' => 0, 'failed' => 0, 'invalid' => 0, 'expired' => 0, 'total' => $subscriptions->count()];
        }

        $deliverable = $this->subscriptionsForDelivery($subscriptions);
        $result = $this->dispatcher->send($deliverable, $title, $body, $url, $tag);

        if (($result['sent'] ?? 0) > 0) {
            Log::info('PanelPushService: push enviado', $result);
        } elseif (($result['total'] ?? 0) > 0) {
            Log::warning('PanelPushService: nenhum push entregue', $result);
        }

        return $result;
    }

    /**
     * @param  Collection<int, PanelPushSubscription>  $subscriptions
     * @return Collection<int, PanelPushSubscription>
     */
    public function filterSubscriptionsForDelivery(Collection $subscriptions): Collection
    {
        return $this->subscriptionsForDelivery($subscriptions);
    }

    /**
     * Uma entrega por usuário (inscrição mais recente válida), evitando push duplicado no mesmo aparelho.
     *
     * @param  Collection<int, PanelPushSubscription>  $subscriptions
     * @return Collection<int, PanelPushSubscription>
     */
    private function subscriptionsForDelivery(Collection $subscriptions): Collection
    {
        return $subscriptions
            ->filter(fn (PanelPushSubscription $subscription) => $subscription->isValidForPush())
            ->sortByDesc(fn (PanelPushSubscription $subscription) => $subscription->updated_at?->getTimestamp() ?? $subscription->id)
            ->unique('user_id')
            ->unique('endpoint')
            ->values();
    }
}
