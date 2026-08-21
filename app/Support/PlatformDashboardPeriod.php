<?php

namespace App\Support;

use Carbon\Carbon;

final class PlatformDashboardPeriod
{
    public const PERIODS = ['hoje', 'ontem', '7dias', 'mes', 'ano', 'total'];

    public static function normalize(?string $period): string
    {
        $period = is_string($period) ? $period : 'hoje';

        return in_array($period, self::PERIODS, true) ? $period : 'hoje';
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    public static function range(string $period): array
    {
        $now = Carbon::now();
        $start = null;
        $end = null;

        switch ($period) {
            case 'hoje':
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
            case 'ontem':
                $start = $now->copy()->subDay()->startOfDay();
                $end = $now->copy()->subDay()->endOfDay();
                break;
            case '7dias':
                $start = $now->copy()->subDays(6)->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
            case 'mes':
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                break;
            case 'ano':
                $start = $now->copy()->startOfYear();
                $end = $now->copy()->endOfYear();
                break;
            case 'total':
                break;
        }

        return [$start?->toDateTimeString(), $end?->toDateTimeString()];
    }

    /**
     * Janela imediatamente anterior, com a mesma duração.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function previousRange(?string $start, ?string $end): array
    {
        if ($start === null || $end === null || $start === '' || $end === '') {
            return [null, null];
        }

        $from = Carbon::parse($start);
        $to = Carbon::parse($end);
        $seconds = max(0, $to->getTimestamp() - $from->getTimestamp());
        $prevEnd = $from->copy()->subSecond();
        $prevStart = $prevEnd->copy()->subSeconds($seconds);

        return [$prevStart->toDateTimeString(), $prevEnd->toDateTimeString()];
    }

    public static function granularity(string $period): string
    {
        return match ($period) {
            'hoje', 'ontem' => 'hour',
            'ano', 'total' => 'month',
            default => 'day',
        };
    }

    /**
     * @return float|null null = período anterior zerado com valor atual (exibir "novo")
     */
    public static function deltaPercent(float $current, float $previous): ?float
    {
        if (abs($previous) < 0.00001) {
            return abs($current) < 0.00001 ? 0.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
