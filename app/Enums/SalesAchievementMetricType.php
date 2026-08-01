<?php

namespace App\Enums;

enum SalesAchievementMetricType: string
{
    case Revenue = 'revenue';

    /** Reservados — sem cálculo oficial na v1. */
    case SalesCount = 'sales_count';
    case CustomersCount = 'customers_count';
    case TransactionVolume = 'transaction_volume';

    public function label(): string
    {
        return match ($this) {
            self::Revenue => 'Faturamento (GMV válido)',
            self::SalesCount => 'Quantidade de vendas',
            self::CustomersCount => 'Número de clientes',
            self::TransactionVolume => 'Volume transacionado',
        };
    }

    public function isImplemented(): bool
    {
        return $this === self::Revenue;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<string>
     */
    public static function selectableValues(): array
    {
        return [self::Revenue->value];
    }
}
