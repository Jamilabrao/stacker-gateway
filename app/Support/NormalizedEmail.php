<?php

namespace App\Support;

use App\Models\User;

class NormalizedEmail
{
    public static function normalize(?string $email): string
    {
        return strtolower(trim((string) $email));
    }

    public static function isReservedForRegistration(string $email): bool
    {
        $normalized = self::normalize($email);
        if ($normalized === '') {
            return false;
        }

        return User::query()
            ->where('role', User::ROLE_PLATFORM_ADMIN)
            ->whereRaw('LOWER(email) = ?', [$normalized])
            ->exists();
    }

    public static function isTaken(string $email, ?int $ignoreUserId = null): bool
    {
        $normalized = self::normalize($email);
        if ($normalized === '') {
            return false;
        }

        $query = User::query()->whereRaw('LOWER(email) = ?', [$normalized]);
        if ($ignoreUserId !== null) {
            $query->where('id', '!=', $ignoreUserId);
        }

        return $query->exists();
    }
}
