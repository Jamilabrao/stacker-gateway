<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * Verificação de e-mail opcional no cadastro de infoprodutores (config admin).
 */
final class RegistrationEmailVerificationSettings
{
    public static function isEnabled(): bool
    {
        return Setting::get('registration_email_verification_enabled', '0', null) === '1';
    }

    public static function requiresVerificationFor(User $user): bool
    {
        if (! self::isEnabled()) {
            return false;
        }

        if (! $user->canAccessSellerPanel()) {
            return false;
        }

        return $user->email_verified_at === null;
    }

    /**
     * Ao ativar a opção, infoprodutores já existentes não precisam re-verificar.
     */
    public static function grandfatherExistingInfoprodutors(): void
    {
        User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function forSettingsForm(): array
    {
        return [
            'registration_email_verification_enabled' => self::isEnabled() ? '1' : '0',
        ];
    }
}
