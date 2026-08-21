<?php

namespace App\Services\Versell;

/**
 * Parser sanitizado para erros RFC 7807 (application/problem+json) da Versell.
 */
final class VersellProblemDetails
{
    /**
     * @param  array<string, mixed>|null  $json
     * @return array{status: ?int, title: ?string, detail: ?string, type: ?string, message: string}
     */
    public static function fromResponse(?array $json, int $httpStatus, string $fallbackBody = ''): array
    {
        $json = is_array($json) ? $json : [];

        $status = isset($json['status']) && is_numeric($json['status'])
            ? (int) $json['status']
            : $httpStatus;
        $title = self::sanitizeString($json['title'] ?? null);
        $detail = self::sanitizeString($json['detail'] ?? null);
        $type = self::sanitizeString($json['type'] ?? null);

        $message = $detail ?? $title;
        if ($message === null || $message === '') {
            $message = self::defaultMessageForStatus($status);
        }

        if ($fallbackBody !== '' && ($detail === null || $detail === '')) {
            $snippet = self::sanitizeString(mb_substr($fallbackBody, 0, 200));
            if ($snippet !== null && $snippet !== '' && ! self::looksSensitive($snippet)) {
                $message = $snippet;
            }
        }

        return [
            'status' => $status > 0 ? $status : null,
            'title' => $title,
            'detail' => $detail,
            'type' => $type,
            'message' => $message,
        ];
    }

    public static function defaultMessageForStatus(int $status): string
    {
        return match (true) {
            $status === 400 => 'Requisição inválida na Versell.',
            $status === 401 => 'Token Versell inválido ou expirado.',
            $status === 403 => 'Credencial Versell sem permissão para o recurso.',
            $status === 404 => 'Recurso Versell não encontrado.',
            $status === 412 => 'Chave de idempotência Versell já utilizada.',
            $status === 422 => 'Regra de negócio Versell violada.',
            $status === 429 => 'Limite de requisições Versell excedido.',
            $status >= 500 => 'Falha técnica temporária na Versell.',
            default => 'Erro na comunicação com a Versell.',
        };
    }

    private static function sanitizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (self::looksSensitive($trimmed)) {
            return '[redacted]';
        }

        return $trimmed;
    }

    private static function looksSensitive(string $value): bool
    {
        $lower = strtolower($value);

        if (
            str_contains($lower, 'bearer ')
            || str_contains($lower, 'client_secret')
            || str_contains($lower, 'clientsecret')
            || str_contains($lower, 'begin private key')
            || str_contains($lower, 'begin certificate')
            || str_contains($lower, 'access_token')
        ) {
            return true;
        }

        // Tokens longos típicos
        if (preg_match('/^[A-Za-z0-9_\-\.]{80,}$/', $value) === 1) {
            return true;
        }

        return false;
    }
}
