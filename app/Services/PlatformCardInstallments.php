<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\CardInstallments;

/**
 * Política global de parcelamento no cartão (admin → Financeiro → Parcelamento).
 * O teto e o interruptor da plataforma limitam o que o infoprodutor e o checkout podem oferecer;
 * as taxas 1x–12x continuam em merchant_fee_rules.card_installments.
 */
class PlatformCardInstallments
{
    public const SETTING_ENABLED = 'platform_card_installments_enabled';

    public const SETTING_MAX = 'platform_card_installments_max';

    public const DEFAULT_MAX = 12;

    public const MIN_SELECTABLE = 2;

    /**
     * Ausência da setting = habilitado, para não desligar produtos já cadastrados.
     */
    public static function globalEnabled(): bool
    {
        $raw = Setting::get(self::SETTING_ENABLED, null, null);
        if ($raw === null || $raw === '') {
            return true;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    public static function maxAllowed(): int
    {
        $raw = Setting::get(self::SETTING_MAX, self::DEFAULT_MAX, null);

        return self::normalizePlatformMax((int) $raw);
    }

    public static function normalizePlatformMax(int $max): int
    {
        return max(self::MIN_SELECTABLE, min(CardInstallments::MAX_ALLOWED, $max));
    }

    public static function setEnabled(bool $enabled): void
    {
        Setting::set(self::SETTING_ENABLED, $enabled ? '1' : '0', null);
    }

    public static function setMaxAllowed(int $max): void
    {
        Setting::set(self::SETTING_MAX, (string) self::normalizePlatformMax($max), null);
    }

    /**
     * @return array{enabled: bool, max: int}
     */
    public static function publicConfig(): array
    {
        return [
            'enabled' => self::globalEnabled(),
            'max' => self::maxAllowed(),
        ];
    }

    /**
     * Flags efetivas no checkout: plataforma desligada ou assinatura = só à vista.
     *
     * @param  array<string, mixed>  $cardInstallments
     * @return array{enabled: bool, max: int}
     */
    public static function forProductConfig(array $cardInstallments, bool $isSubscription = false): array
    {
        if ($isSubscription || ! self::globalEnabled()) {
            return ['enabled' => false, 'max' => 1];
        }

        if (empty($cardInstallments['enabled'])) {
            return ['enabled' => false, 'max' => 1];
        }

        $productMax = CardInstallments::normalizeMax((int) ($cardInstallments['max'] ?? 1));
        $max = min($productMax, self::maxAllowed());
        if ($max < self::MIN_SELECTABLE) {
            return ['enabled' => false, 'max' => 1];
        }

        return ['enabled' => true, 'max' => $max];
    }

    /**
     * Normaliza o payload do seller. Null = não alterar o que já está salvo
     * (plataforma desligada ou campo ausente).
     *
     * @param  array<string, mixed>|null  $input
     * @return array{enabled: bool, max: int}|null
     */
    public static function normalizeSellerInput(?array $input, string $billingType): ?array
    {
        if ($billingType === 'subscription') {
            return ['enabled' => false, 'max' => 1];
        }
        if (! self::globalEnabled()) {
            return null;
        }
        if (! is_array($input)) {
            return null;
        }

        $enabled = ! empty($input['enabled']) && $input['enabled'] !== '0';
        if (! $enabled) {
            return ['enabled' => false, 'max' => 1];
        }

        $max = min(
            CardInstallments::normalizeMax((int) ($input['max'] ?? self::MIN_SELECTABLE)),
            self::maxAllowed()
        );
        $max = max(self::MIN_SELECTABLE, $max);

        return ['enabled' => true, 'max' => $max];
    }
}
