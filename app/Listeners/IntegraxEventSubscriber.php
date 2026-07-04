<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Events\PixGenerated;
use App\Models\Order;
use App\Models\PlatformIntegraxSetting;
use App\Services\Integrax\IntegraxMessageBuilder;
use App\Services\Integrax\IntegraxSmsDispatcher;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

class IntegraxEventSubscriber
{
    public function __construct(
        private IntegraxSmsDispatcher $dispatcher,
        private IntegraxMessageBuilder $messageBuilder
    ) {}

    /**
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderCompleted::class => 'handleOrderCompleted',
            PixGenerated::class => 'handlePixGenerated',
        ];
    }

    public function handleOrderCompleted(OrderCompleted $event): void
    {
        $order = $event->order->fresh() ?? $event->order;
        $phone = $this->resolveOrderPhone($order);
        if ($phone === null) {
            Log::debug('IntegraxEventSubscriber: order_paid skipped (sem telefone)', ['order_id' => $order->id]);

            return;
        }

        $vars = $this->messageBuilder->fromOrder($order);

        if ($this->dispatcher->dispatch(
            PlatformIntegraxSetting::EVENT_ORDER_PAID,
            $phone,
            $vars,
            $order->tenant_id,
            null,
            $order->id
        )) {
            Log::info('IntegraxEventSubscriber: order_paid enfileirado', ['order_id' => $order->id]);
        }

        if ($this->messageBuilder->shouldSendAccessGranted($order)) {
            if ($this->dispatcher->dispatch(
                PlatformIntegraxSetting::EVENT_ACCESS_GRANTED,
                $phone,
                $vars,
                $order->tenant_id,
                null,
                $order->id
            )) {
                Log::info('IntegraxEventSubscriber: access_granted enfileirado', ['order_id' => $order->id]);
            }
        }
    }

    public function handlePixGenerated(PixGenerated $event): void
    {
        $order = $event->order->fresh() ?? $event->order;
        $phone = $this->resolveOrderPhone($order);
        if ($phone === null) {
            Log::debug('IntegraxEventSubscriber: pix_generated skipped (sem telefone)', ['order_id' => $order->id]);

            return;
        }

        $vars = $this->messageBuilder->fromOrder($order);

        if ($this->dispatcher->dispatch(
            PlatformIntegraxSetting::EVENT_PIX_GENERATED,
            $phone,
            $vars,
            $order->tenant_id,
            null,
            $order->id
        )) {
            Log::info('IntegraxEventSubscriber: pix_generated enfileirado', ['order_id' => $order->id]);
        }
    }

    private function resolveOrderPhone(Order $order): ?string
    {
        $phone = trim((string) ($order->phone ?? ''));
        if ($phone !== '') {
            return $phone;
        }

        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $metaPhone = trim((string) ($metadata['phone'] ?? $metadata['customer_phone'] ?? ''));
        if ($metaPhone !== '') {
            return $metaPhone;
        }

        return null;
    }
}
