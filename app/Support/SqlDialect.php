<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Expressões SQL portáveis entre MySQL/MariaDB, PostgreSQL e SQLite.
 */
class SqlDialect
{
    public static function hourExpression(string $column = 'created_at'): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "EXTRACT(HOUR FROM {$column})::int",
            'sqlite' => "CAST(strftime('%H', {$column}) AS INTEGER)",
            default => "HOUR({$column})",
        };
    }

    public static function dateExpression(string $column = 'created_at'): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "({$column})::date",
            'sqlite' => "date({$column})",
            default => "DATE({$column})",
        };
    }

    public static function monthExpression(string $column = 'created_at'): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            'sqlite' => "strftime('%Y-%m', {$column})",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }

    public static function bucketExpression(string $granularity, string $column = 'created_at'): string
    {
        return match ($granularity) {
            'hour' => self::hourExpression($column),
            'month' => self::monthExpression($column),
            default => self::dateExpression($column),
        };
    }
}
