<?php

namespace App\Support;

class HtmlSanitizer
{
    /**
     * Lista de tags permitidas para conteúdo de aula/seção (evita XSS).
     */
    private const ALLOWED_TAGS = '<p><br><strong><em><b><i><u><s><a><ul><ol><li><h1><h2><h3><h4><blockquote><pre><code><span><div>';

    /**
     * Sanitiza HTML para exibição segura (remove script, eventos, javascript:).
     */
    public static function sanitize(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }
        $html = strip_tags($html, self::ALLOWED_TAGS);
        $html = self::removeEventHandlers($html);
        $html = self::removeJavascriptUrls($html);
        $html = self::removeDataUrls($html);

        return $html;
    }

    private static function removeEventHandlers(string $html): string
    {
        return (string) preg_replace_callback(
            '/<(\w+)([^>]*)>/i',
            function (array $m): string {
                $attrs = (string) preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $m[2]);
                return '<' . $m[1] . $attrs . '>';
            },
            $html
        );
    }

    private static function removeJavascriptUrls(string $html): string
    {
        $html = (string) preg_replace(
            '/href\s*=\s*["\']\s*javascript\s*:[^"\']*["\']/i',
            'href="#"',
            $html
        );
        $html = (string) preg_replace(
            '/src\s*=\s*["\']\s*javascript\s*:[^"\']*["\']/i',
            'src=""',
            $html
        );

        return $html;
    }

    private static function removeDataUrls(string $html): string
    {
        return (string) preg_replace(
            '/\b(src|href)\s*=\s*["\']\s*data\s*:[^"\']*["\']/i',
            '$1=""',
            $html
        );
    }

    /**
     * Sanitiza texto puro (sem HTML) para armazenar em campos comuns (nome, endereço, etc.).
     * Remove tags, caracteres de controlo e normaliza espaços.
     */
    public static function plainText(?string $text, int $maxLen = 255): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $s = strip_tags($text);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $s) ?? '';
        $s = preg_replace('/\s+/u', ' ', (string) $s) ?? '';
        $s = trim($s);

        if ($maxLen > 0 && mb_strlen($s) > $maxLen) {
            $s = mb_substr($s, 0, $maxLen);
        }

        return $s;
    }

    /**
     * Texto puro preservando quebras de linha (ex.: observações). Remove tags e caracteres de controlo.
     */
    public static function plainTextMultiline(?string $text, int $maxLen = 2000): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $s = strip_tags($text);
        $s = str_replace(["\r\n", "\r"], "\n", (string) $s);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $s) ?? '';
        // Normaliza múltiplas linhas vazias
        $s = preg_replace("/\n{3,}/", "\n\n", (string) $s) ?? '';
        $s = trim($s);

        if ($maxLen > 0 && mb_strlen($s) > $maxLen) {
            $s = mb_substr($s, 0, $maxLen);
        }

        return $s;
    }
}
