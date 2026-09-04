<?php

namespace App\Services\Platform;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;

class PlatformTotpService
{
    private const PERIOD = 30;

    private const DIGITS = 6;

    public static function isEnabledFor(User $user): bool
    {
        return $user->totp_enabled_at !== null
            && is_string($user->totp_secret)
            && $user->totp_secret !== '';
    }

    public static function isRequiredFor(User $user): bool
    {
        return $user->canAccessPlatformPanel() && self::isEnabledFor($user);
    }

    public static function requiresLoginChallenge(User $user): bool
    {
        return self::isEnabledFor($user);
    }

    /**
     * @return array{secret: string, otpauth_url: string}
     */
    public static function beginEnrollment(User $user): array
    {
        $secret = self::generateSecret();
        $user->totp_secret = Crypt::encryptString($secret);
        $user->totp_enabled_at = null;
        $user->save();

        $issuer = self::totpIssuerName();
        $account = (string) ($user->email ?? 'admin');
        $issuerEncoded = rawurlencode($issuer);
        $accountEncoded = rawurlencode($account);

        return [
            'secret' => $secret,
            'otpauth_url' => 'otpauth://totp/'.$issuerEncoded.':'.$accountEncoded
                .'?secret='.$secret
                .'&issuer='.$issuerEncoded
                .'&digits='.self::DIGITS
                .'&period='.self::PERIOD,
        ];
    }

    public static function confirmEnrollment(User $user, string $code): bool
    {
        if (! self::verifyCodeForUser($user, $code, allowPending: true)) {
            return false;
        }

        $user->totp_enabled_at = now();
        $user->save();

        return true;
    }

    public static function disable(User $user, string $code): bool
    {
        if (! self::verifyCodeForUser($user, $code)) {
            return false;
        }

        self::clearTotp($user);

        return true;
    }

    /**
     * Remove 2FA sem exigir o código TOTP da conta (ex.: reset administrativo).
     */
    public static function forceDisable(User $user): void
    {
        self::clearTotp($user);
    }

    private static function clearTotp(User $user): void
    {
        $user->totp_secret = null;
        $user->totp_enabled_at = null;
        $user->save();
    }

    public static function verifyCodeForUser(User $user, string $code, bool $allowPending = false): bool
    {
        $secret = self::decryptSecret($user);
        if ($secret === null) {
            return false;
        }

        if (! $allowPending && ! self::isEnabledFor($user)) {
            return false;
        }

        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $timeSlice = (int) floor(time() / self::PERIOD);

        for ($i = -1; $i <= 1; $i++) {
            if (hash_equals(self::codeForSecret($secret, $timeSlice + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    private static function totpIssuerName(): string
    {
        $name = trim((string) config('getfy.app_name', ''));
        if ($name !== '') {
            return $name;
        }

        $name = trim((string) config('app.name', ''));
        if ($name !== '') {
            return $name;
        }

        return 'Getfy';
    }

    private static function decryptSecret(User $user): ?string
    {
        $raw = $user->totp_secret;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function generateSecret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    private static function codeForSecret(string $secret, int $timeSlice): string
    {
        $key = self::base32Decode($secret);
        $time = pack('N*', 0, $timeSlice);
        $hash = hash_hmac('sha1', $time, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );
        $otp = $truncated % (10 ** self::DIGITS);

        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(preg_replace('/\s+/', '', $secret) ?? '');
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        foreach (str_split($secret) as $char) {
            $val = strpos($alphabet, $char);
            if ($val === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}
