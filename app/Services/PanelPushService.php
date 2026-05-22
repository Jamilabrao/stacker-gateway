<?php

namespace App\Services;

use App\Models\PanelNotification;
use App\Models\PanelPushSubscription;
use App\Services\Push\PanelPushDispatcher;
use Illuminate\Support\Collection;
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
        $subscriptions = PanelPushSubscription::where('tenant_id', $tenantId)->get();
        $userIds = $subscriptions->pluck('user_id')->unique()->filter()->values();
        $anyNewNotification = false;

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
                $notification = PanelNotification::firstOrCreate(
                    [
                        'user_id' => $userId,
                        'event_key' => $eventKey,
                    ],
                    array_merge($attrs, ['event_key' => $eventKey])
                );
                if ($notification->wasRecentlyCreated) {
                    $anyNewNotification = true;
                }
            } else {
                PanelNotification::create($attrs);
                $anyNewNotification = true;
            }
        }

        if ($eventKey !== null && $eventKey !== '' && ! $anyNewNotification) {
            return 0;
        }

        $result = $this->sendToSubscriptions($subscriptions, $title, $body, $url);

        return (int) ($result['sent'] ?? 0);
    }

    /**
     * Envia push global para todos os assinantes do painel e persiste no centro de notificações.
     *
     * @return array{sent:int,failed:int,invalid:int,expired:int,total:int}
     */
    public function sendAndPersistToAll(string $type, string $title, string $body, ?string $url = null): array
    {
        $subscriptions = PanelPushSubscription::query()->get();
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
        $subscriptions = PanelPushSubscription::where('tenant_id', $tenantId)->get();
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
    public function sendToSubscriptions(Collection $subscriptions, string $title, string $body, ?string $url = null): array
    {
        if (! \App\Support\PanelPushSettings::isPushEnabled()) {
            Log::warning('PanelPushService: push não configurado no admin');

            return ['sent' => 0, 'failed' => 0, 'invalid' => 0, 'expired' => 0, 'total' => $subscriptions->count()];
        }

        $result = $this->dispatcher->send($subscriptions, $title, $body, $url);

        if (($result['sent'] ?? 0) > 0) {
            Log::info('PanelPushService: push enviado', $result);
        } elseif (($result['total'] ?? 0) > 0) {
            Log::warning('PanelPushService: nenhum push entregue', $result);
        }

        return $result;
    }
}
