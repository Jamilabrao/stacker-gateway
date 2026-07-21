<?php

namespace App\Services;

use App\Models\ReferralCommission;
use App\Models\User;
use App\Support\ReferralProgramSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

class ReferralAttributionService
{
    public static function resolveCodeFromRequest(Request $request): ?string
    {
        $fromBody = trim((string) $request->input('ref', ''));
        if ($fromBody !== '') {
            return self::normalizeCode($fromBody);
        }

        $fromQuery = trim((string) $request->query('ref', ''));
        if ($fromQuery !== '') {
            return self::normalizeCode($fromQuery);
        }

        $fromCookie = trim((string) $request->cookie(ReferralProgramSettings::COOKIE_NAME, ''));
        if ($fromCookie !== '') {
            return self::normalizeCode($fromCookie);
        }

        return null;
    }

    public static function normalizeCode(?string $code): ?string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code) ?? '');
        if ($code === '' || strlen($code) > 32) {
            return null;
        }

        return $code;
    }

    public static function findReferrerByCode(?string $code): ?User
    {
        $code = self::normalizeCode($code);
        if ($code === null || ! Schema::hasColumn('users', 'referral_code')) {
            return null;
        }

        $user = User::query()->where('referral_code', $code)->first();
        if ($user === null || ! ReferralCommissionRecorder::isEligibleReferrer($user)) {
            return null;
        }

        return $user;
    }

    public static function makeReferralCookie(string $code): SymfonyCookie
    {
        $minutes = ReferralProgramSettings::cookieDays() * 24 * 60;

        return Cookie::make(
            ReferralProgramSettings::COOKIE_NAME,
            self::normalizeCode($code) ?? $code,
            $minutes,
            '/',
            null,
            config('session.secure'),
            true,
            false,
            config('session.same_site', 'lax')
        );
    }

    public static function forgetReferralCookie(): SymfonyCookie
    {
        return Cookie::forget(ReferralProgramSettings::COOKIE_NAME);
    }

    /**
     * Atribui o referrer a um novo seller (uma vez).
     */
    public static function attachOnRegistration(User $newUser, ?string $code): bool
    {
        if (! ReferralProgramSettings::isEnabled()) {
            return false;
        }

        if (! Schema::hasColumn('users', 'referred_by_user_id')) {
            return false;
        }

        if ($newUser->referred_by_user_id) {
            return false;
        }

        $referrer = self::findReferrerByCode($code);
        if ($referrer === null) {
            return false;
        }

        if ((int) $referrer->id === (int) $newUser->id) {
            return false;
        }

        $newUser->forceFill([
            'referred_by_user_id' => $referrer->id,
            'referred_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Atribuição / troca manual pelo admin da plataforma.
     *
     * @throws ValidationException
     */
    public static function assignReferrer(User $referred, ?int $referrerUserId, bool $force = false): void
    {
        if (! Schema::hasColumn('users', 'referred_by_user_id')) {
            throw ValidationException::withMessages(['referred_by_user_id' => 'Recurso indisponível.']);
        }

        if ($referrerUserId === null) {
            $referred->forceFill([
                'referred_by_user_id' => null,
                'referred_at' => null,
            ])->save();

            return;
        }

        if ((int) $referrerUserId === (int) $referred->id) {
            throw ValidationException::withMessages([
                'referred_by_user_id' => 'Um usuário não pode indicar a si mesmo.',
            ]);
        }

        $referrer = User::query()->find($referrerUserId);
        if ($referrer === null || (! $referrer->canAccessSellerPanel() && ! $referrer->isInfoprodutor())) {
            throw ValidationException::withMessages([
                'referred_by_user_id' => 'Indicador inválido.',
            ]);
        }

        if (! $force && $referred->referred_by_user_id) {
            $hasCommissions = Schema::hasTable('referral_commissions')
                && ReferralCommission::query()
                    ->where('referred_user_id', $referred->id)
                    ->whereIn('status', [
                        ReferralCommission::STATUS_AVAILABLE,
                        ReferralCommission::STATUS_PENDING,
                    ])
                    ->exists();

            if ($hasCommissions && (int) $referred->referred_by_user_id !== (int) $referrerUserId) {
                throw ValidationException::withMessages([
                    'referred_by_user_id' => 'Este usuário já gerou comissões. Confirme a troca forçada para continuar.',
                    'requires_force' => true,
                ]);
            }
        }

        $referred->forceFill([
            'referred_by_user_id' => $referrer->id,
            'referred_at' => $referred->referred_at ?? now(),
        ])->save();
    }
}
