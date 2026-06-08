<?php

namespace App\Models;

use App\Support\PanelPushSettings;
use App\Support\VapidEnvKeys;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PanelPushSubscription extends Model
{
    public const PROVIDER_VAPID = 'vapid';

    public const PROVIDER_FCM = 'fcm';

    protected $fillable = [
        'user_id',
        'tenant_id',
        'provider',
        'vapid_public_key',
        'endpoint',
        'fcm_token',
        'keys',
        'user_agent',
        'device_label',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'keys' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    public function isFcm(): bool
    {
        return $this->provider === self::PROVIDER_FCM;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function matchesActiveProvider(): bool
    {
        $active = PanelPushSettings::activeProvider();

        return $this->isFcm()
            ? $active === self::PROVIDER_FCM
            : $active === self::PROVIDER_VAPID;
    }

    public function matchesCurrentVapidKey(): bool
    {
        if ($this->isFcm()) {
            return true;
        }

        $current = VapidEnvKeys::normalize(config('getfy.pwa.vapid_public'));
        if (! $current) {
            return false;
        }

        $stored = VapidEnvKeys::normalize($this->vapid_public_key);

        return $stored !== null && $stored === $current;
    }

    public function isValidForPush(): bool
    {
        if (! $this->matchesActiveProvider()) {
            return false;
        }

        if ($this->isFcm()) {
            return is_string($this->fcm_token) && trim($this->fcm_token) !== '';
        }

        $keys = $this->keys ?? [];

        return is_string($this->endpoint) && $this->endpoint !== ''
            && is_string($keys['auth'] ?? null) && trim((string) $keys['auth']) !== ''
            && is_string($keys['p256dh'] ?? null) && trim((string) $keys['p256dh']) !== ''
            && $this->matchesCurrentVapidKey();
    }

    /**
     * @return array{push_subscribed: bool, push_needs_resubscribe: bool}
     */
    public static function pushStatusForUser(int $userId, ?int $tenantId): array
    {
        $subscriptions = self::query()
            ->where('user_id', $userId)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->get();

        return self::pushStatusFromSubscriptions($subscriptions);
    }

    /**
     * @param  Collection<int, PanelPushSubscription>  $subscriptions
     * @return array{push_subscribed: bool, push_needs_resubscribe: bool}
     */
    public static function pushStatusFromSubscriptions(Collection $subscriptions): array
    {
        $hasAny = $subscriptions->isNotEmpty();
        $hasValid = $subscriptions->contains(fn (self $sub) => $sub->isValidForPush());
        $hasStale = $subscriptions->contains(function (self $sub) {
            if (! $sub->matchesActiveProvider()) {
                return true;
            }
            if ($sub->isFcm()) {
                return ! (is_string($sub->fcm_token) && trim($sub->fcm_token) !== '');
            }

            return ! $sub->matchesCurrentVapidKey();
        });

        return [
            'push_subscribed' => $hasValid,
            'push_needs_resubscribe' => $hasAny && ($hasStale || ! $hasValid),
        ];
    }
}
