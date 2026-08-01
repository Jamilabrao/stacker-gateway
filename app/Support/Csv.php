<?php

namespace App\Support;

/**
 * Helpers para exportação CSV (Excel BR) com proteção contra CSV Injection.
 */
final class Csv
{
    public const DELIMITER = ';';

    /**
     * Neutraliza células que o Excel interpretaria como fórmula.
     */
    public static function sanitizeCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $text = (string) $value;
        $text = str_replace("\0", '', $text);

        if ($text === '') {
            return '';
        }

        $first = $text[0];
        if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$text;
        }

        return $text;
    }

    /**
     * @param  resource  $handle
     * @param  list<mixed>  $row
     */
    public static function writeRow($handle, array $row): void
    {
        $safe = array_map(static fn ($cell) => self::sanitizeCell($cell), $row);
        fputcsv($handle, $safe, self::DELIMITER);
    }

    /**
     * @param  resource  $handle
     */
    public static function writeBom($handle): void
    {
        fwrite($handle, chr(0xEF).chr(0xBB).chr(0xBF));
    }
}
