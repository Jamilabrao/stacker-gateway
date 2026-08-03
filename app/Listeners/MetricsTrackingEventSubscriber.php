<?php

namespace App\Listeners;

use App\Events\OrderCancelled;
use App\Events\OrderCompleted;
use App\Events\OrderPending;
use App\Events\OrderRefunded;
use App\Events\OrderRejected;
use App\Events\PixGenerated;
use App\Models\MetricsEvent;
use App\Services\MetricsTracking\MetricsCaptureService;

class MetricsTrackingEventSubscriber
{
    public function __construct(
        private readonly MetricsCaptureService $capture,
    ) {}

    /**
     * @param  \Illuminate\Events\Dispatcher  $events
     */
    public function subscribe($events): void
    {
        $events->listen(PixGenerated::class, [self::class, 'onPixGenerated']);
        $events->listen(OrderPending::class, [self::class, 'onOrderPending']);
        $events->listen(OrderCompleted::class, [self::class, 'onOrderCompleted']);
        $events->listen(OrderRefunded::class, [self::class, 'onOrderRefunded']);
        $events->listen(OrderRejected::class, [self::class, 'onOrderRejected']);
        $events->listen(OrderCancelled::class, [self::class, 'onOrderCancelled']);
    }

    public function onPixGenerated(PixGenerated $event): void
    {
        $this->capture->recordOrderEvent($event->order, MetricsEvent::PIX_CREATED, 'pix_created');
    }

    public function onOrderPending(OrderPending $event): void
    {
        $this->capture->recordOrderEvent($event->order, MetricsEvent::PAYMENT_PENDING, 'pending');
    }

    public function onOrderCompleted(OrderCompleted $event): void
    {
        $this->capture->recordOrderEvent($event->order, MetricsEvent::PAYMENT_APPROVED, 'approved');
    }

    public function onOrderRefunded(OrderRefunded $event): void
    {
        $this->capture->recordOrderEvent($event->order, MetricsEvent::PAYMENT_REFUNDED, 'refunded');
    }

    public function onOrderRejected(OrderRejected $event): void
    {
        $this->capture->recordOrderEvent($event->order, MetricsEvent::PAYMENT_REFUSED, 'refused');
    }

    public function onOrderCancelled(OrderCancelled $event): void
    {
        $this->capture->recordOrderEvent($event->order, MetricsEvent::PAYMENT_CANCELLED, 'cancelled');
    }
}
