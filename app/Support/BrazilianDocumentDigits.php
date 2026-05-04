<?php

namespace App\Support;

/**
 * CPF/CNPJ apenas dígitos para APIs (ex.: CajuPay key_owner_document).
 */
final class BrazilianDocumentDigits
{
    public static function onlyDigits(?string $input): string
    {
        if ($input === null || $input === '') {
            return '';
        }

        return preg_replace('/\D/', '', $input) ?? '';
    }

    public static function isValidCpfOrCnpjLength(string $digits): bool
    {
        $len = strlen($digits);

        return $len === 11 || $len === 14;
    }
}
