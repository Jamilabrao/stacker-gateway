<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegraxSmsDispatch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'checkout_session_id',
        'order_id',
        'event_type',
        'sequence_step',
        'phone',
        'message',
        'status',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public static function alreadySentForOrder(int $orderId, string $eventType): bool
    {
        return static::alreadyQueuedForOrder($orderId, $eventType);
    }

    public static function alreadyQueuedForOrder(int $orderId, string $eventType): bool
    {
        return static::query()
            ->where('order_id', $orderId)
            ->where('event_type', $eventType)
            ->whereIn('status', [self::STATUS_SENT, self::STATUS_PENDING])
            ->exists();
    }

    /**
     * @return array<int, int>
     */
    public static function sentStepIndicesForSession(int $sessionId, string $eventType = PlatformIntegraxSetting::EVENT_CART_RECOVERY): array
    {
        return static::query()
            ->where('checkout_session_id', $sessionId)
            ->where('event_type', $eventType)
            ->where('status', self::STATUS_SENT)
            ->whereNotNull('sequence_step')
            ->pluck('sequence_step')
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    public static function hasPendingStepForSession(int $sessionId, int $stepIndex, string $eventType = PlatformIntegraxSetting::EVENT_CART_RECOVERY): bool
    {
        return static::query()
            ->where('checkout_session_id', $sessionId)
            ->where('event_type', $eventType)
            ->where('sequence_step', $stepIndex)
            ->where('status', self::STATUS_PENDING)
            ->exists();
    }

    public static function sentCountForSession(int $sessionId, string $eventType): int
    {
        return static::query()
            ->where('checkout_session_id', $sessionId)
            ->where('event_type', $eventType)
            ->where('status', self::STATUS_SENT)
            ->count();
    }

    public static function lastSentAtForSession(int $sessionId, string $eventType): ?\Illuminate\Support\Carbon
    {
        return static::query()
            ->where('checkout_session_id', $sessionId)
            ->where('event_type', $eventType)
            ->where('status', self::STATUS_SENT)
            ->orderByDesc('sent_at')
            ->value('sent_at');
    }
}
