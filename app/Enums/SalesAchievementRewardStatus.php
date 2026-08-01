<?php

namespace App\Enums;

enum SalesAchievementRewardStatus: string
{
    case Pending = 'pending';
    case InProduction = 'in_production';
    case Sent = 'sent';
    case Cancelled = 'cancelled';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando processamento',
            self::InProduction => 'Prêmio em produção',
            self::Sent => 'Prêmio enviado',
            self::Cancelled => 'Premiação cancelada',
            self::NotApplicable => 'Não aplicável',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Statuses usados na UI operacional da v1.
     *
     * @return list<string>
     */
    public static function operationalValues(): array
    {
        return [
            self::Pending->value,
            self::InProduction->value,
            self::Sent->value,
        ];
    }

    public function canTransitionTo(self $to): bool
    {
        if ($this === $to) {
            return false;
        }

        return match ($this) {
            self::Pending => in_array($to, [self::InProduction, self::Cancelled, self::NotApplicable], true),
            self::InProduction => in_array($to, [self::Sent, self::Cancelled], true),
            self::Sent => $to === self::Cancelled,
            self::Cancelled, self::NotApplicable => false,
        };
    }

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }
}
