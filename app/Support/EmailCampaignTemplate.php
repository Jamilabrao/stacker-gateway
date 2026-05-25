<?php

namespace App\Support;

/**
 * Template HTML de campanhas: o usuário edita só texto; o layout fica fixo no envio.
 */
final class EmailCampaignTemplate
{
    public const BODY_MARKER = 'data-campaign-body="1"';

    public static function defaultMessageText(): string
    {
        return "Temos uma novidade importante para compartilhar com você.\n\n"
            ."Escreva aqui o conteúdo da sua mensagem. Você pode usar parágrafos separando com uma linha em branco.\n\n"
            ."Qualquer dúvida, basta responder este e-mail.\n\n"
            ."Abraços!";
    }

    public static function wrapContent(string $plainText): string
    {
        $inner = self::plainTextToHtmlBlock(trim($plainText) !== '' ? $plainText : self::defaultMessageText());
        $primary = '#0ea5e9';

        return '<!DOCTYPE html><html lang="pt-BR"><head>'
            .'<meta charset="utf-8"><meta name="viewport" content="width=device-width">'
            .'<meta name="color-scheme" content="light only">'
            .'</head><body style="margin:0;padding:0;background-color:#f1f5f9 !important;">'
            .'<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="background-color:#f1f5f9;padding:32px 16px;">'
            .'<tr><td align="center">'
            .'<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="max-width:600px;background-color:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(15,23,42,0.08);">'
            .'<tr><td style="padding:28px 32px 20px;background:linear-gradient(135deg,'.$primary.' 0%,#0284c7 100%);text-align:center;">'
            .'<p style="margin:0;font-size:13px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:rgba(255,255,255,0.9);">Mensagem para você</p>'
            .'<h1 style="margin:12px 0 0;font-size:26px;font-weight:700;color:#ffffff;line-height:1.3;">Olá, {nome}!</h1>'
            .'</td></tr>'
            .'<tr><td '.self::BODY_MARKER.' style="padding:32px 36px 28px;color:#334155;font-size:16px;line-height:1.65;">'
            .$inner
            .'</td></tr>'
            .'<tr><td style="padding:20px 32px 28px;background-color:#f8fafc;border-top:1px solid #e2e8f0;">'
            .'<p style="margin:0 0 8px;font-size:13px;color:#64748b;line-height:1.5;">Este e-mail foi enviado para <strong style="color:#475569;">{email}</strong>.</p>'
            .'<p style="margin:0;font-size:12px;color:#94a3b8;">Se não deseja mais receber este tipo de comunicação, responda solicitando o descadastro.</p>'
            .'</td></tr>'
            .'</table></td></tr></table></body></html>';
    }

    public static function extractPlainText(string $bodyHtml): string
    {
        if (preg_match('/<td[^>]*'.preg_quote(self::BODY_MARKER, '/').'[^>]*>(.*)<\/td>/is', $bodyHtml, $m)) {
            $text = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $m[1]));
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

            return trim($text);
        }

        $text = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $bodyHtml));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text) !== '' ? trim($text) : self::defaultMessageText();
    }

    private static function plainTextToHtmlBlock(string $plainText): string
    {
        $parts = preg_split("/\r\n\r\n|\n\n/", $plainText) ?: [$plainText];
        $html = '';
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $line = nl2br(e($part), false);
            $html .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.65;color:#334155;">'.$line.'</p>';
        }

        return $html !== '' ? $html : '<p style="margin:0;font-size:16px;line-height:1.65;color:#334155;">'.e($plainText).'</p>';
    }
}
