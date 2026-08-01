<?php

namespace App\Support;

/**
 * Validação de URL de destino de push.
 */
final class PanelPushTargetUrl
{
    public static function normalize(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $lower = strtolower($url);
        if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:') || str_starts_with($lower, 'vbscript:')) {
            throw new \InvalidArgumentException('URL de destino não permitida.');
        }

        if (str_starts_with($url, '/')) {
            return $url;
        }

        if (! preg_match('#^https://#i', $url)) {
            throw new \InvalidArgumentException('URLs externas devem usar HTTPS.');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('URL de destino inválida.');
        }

        return $url;
    }
}
