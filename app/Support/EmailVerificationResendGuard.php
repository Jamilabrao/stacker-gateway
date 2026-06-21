<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Cooldown entre reenvios do e-mail de verificação (anti-abuse / anti-spam).
 */
final class EmailVerificationResendGuard
{
    public const COOLDOWN_SECONDS = 60;

    public static function cooldownCacheKey(int $userId): string
    {
        return 'email_verify_resend_cooldown:'.$userId;
    }

    public static function secondsUntilResendAllowed(User $user): int
    {
        $availableAt = Cache::get(self::cooldownCacheKey((int) $user->id));
        if (! is_int($availableAt) && ! is_numeric($availableAt)) {
            return 0;
        }

        return max(0, (int) $availableAt - time());
    }

    public static function markResent(User $user): void
    {
        $ttl = self::COOLDOWN_SECONDS + 10;
        Cache::put(
            self::cooldownCacheKey((int) $user->id),
            time() + self::COOLDOWN_SECONDS,
            $ttl
        );
    }
}
