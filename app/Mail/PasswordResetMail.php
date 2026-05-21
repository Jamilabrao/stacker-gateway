<?php

namespace App\Mail;

use App\Services\BrandingEmailData;
use App\Support\EmailLogoHtml;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $resetUrl,
        public int $expireMinutes,
        public ?int $tenantId = null,
    ) {}

    public function build(): static
    {
        $branding = BrandingEmailData::forTenant($this->tenantId);
        $primary = e($branding['theme_primary'] ?? '#0ea5e9');
        $appName = e($branding['app_name'] ?? config('app.name'));
        $logoUrl = $branding['logo_url'] ?? null;

        $logoBlock = is_string($logoUrl) && $logoUrl !== ''
            ? EmailLogoHtml::wrap($logoUrl)
            : '';

        $url = e($this->resetUrl);
        $expire = (int) $this->expireMinutes;

        $html = '<!DOCTYPE html><html lang="pt-BR"><head>'
            .'<meta charset="utf-8"><meta name="viewport" content="width=device-width">'
            .'<meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light">'
            .'</head><body style="margin:0;padding:0;background-color:#f8fafc !important;">'
            .'<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="background-color:#f8fafc;padding:32px 16px;">'
            .'<tr><td align="center">'
            .'<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="max-width:600px;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">'
            .'<tr><td style="padding:32px 32px 8px;text-align:center;background-color:#ffffff;">'
            .$logoBlock
            .'<h1 style="margin:0;font-size:22px;font-weight:600;color:#0f172a;">Olá!</h1>'
            .'</td></tr>'
            .'<tr><td style="padding:8px 32px 28px;background-color:#ffffff;color:#334155;font-size:16px;line-height:1.6;">'
            .'<p style="margin:0 0 16px;">Você está recebendo este e-mail porque recebemos uma solicitação de redefinição de senha da sua conta.</p>'
            .'<p style="margin:0 0 24px;text-align:center;">'
            .'<a href="'.$url.'" style="display:inline-block;padding:14px 32px;background-color:'.$primary.';color:#ffffff;text-decoration:none;font-weight:600;font-size:16px;border-radius:8px;">Redefinir senha</a>'
            .'</p>'
            .'<p style="margin:0 0 16px;font-size:14px;color:#64748b;">Este link expira em '.$expire.' minutos.</p>'
            .'<p style="margin:0 0 16px;font-size:14px;color:#64748b;">Se você não solicitou a redefinição de senha, nenhuma ação é necessária.</p>'
            .'<p style="margin:0;font-size:13px;color:#94a3b8;word-break:break-all;">Ou copie e cole no navegador:<br><a href="'.$url.'" style="color:'.$primary.';">'.$url.'</a></p>'
            .'</td></tr>'
            .'<tr><td style="padding:20px 32px;background-color:#f1f5f9;border-top:1px solid #e2e8f0;">'
            .'<p style="margin:0;font-size:13px;color:#64748b;text-align:center;">Atenciosamente,<br>'.$appName.'</p>'
            .'</td></tr>'
            .'</table></td></tr></table></body></html>';

        return $this->subject('Redefinição de senha')->html($html);
    }
}
