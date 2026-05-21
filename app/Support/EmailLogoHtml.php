<?php

namespace App\Support;

final class EmailLogoHtml
{
    /**
     * Logo para e-mails com fundo claro fixo (evita PNG transparente virar “caixa preta” no modo escuro do cliente).
     */
    public static function wrap(string $logoUrl): string
    {
        $src = e($logoUrl);

        return '<div data-email-logo="1" style="margin:0 auto 20px;text-align:center;background-color:#ffffff !important;background:#ffffff !important;">'
            .'<!--[if mso]><table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="background-color:#ffffff;"><tr><td style="padding:12px 16px;background-color:#ffffff;"><![endif]-->'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto;background-color:#ffffff !important;background-image:linear-gradient(#ffffff,#ffffff);mso-table-lspace:0pt;mso-table-rspace:0pt;">'
            .'<tr><td bgcolor="#ffffff" align="center" style="padding:14px 20px;background-color:#ffffff !important;background-image:linear-gradient(#ffffff,#ffffff);border-radius:8px;">'
            .'<img src="'.$src.'" alt="Logo" width="240" style="display:block;margin:0 auto;max-height:64px;max-width:240px;width:auto;height:auto;border:0;outline:none;text-decoration:none;background-color:#ffffff !important;-ms-interpolation-mode:bicubic;" />'
            .'</td></tr></table>'
            .'<!--[if mso]></td></tr></table><![endif]-->'
            .'</div>';
    }
}
