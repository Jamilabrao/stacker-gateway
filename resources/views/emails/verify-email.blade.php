@extends('emails.layouts.branded')

@section('content')
    <p style="margin:0 0 16px 0;font-size:18px;font-weight:600;color:#18181b;">Confirme seu e-mail</p>
    <p style="margin:0 0 16px 0;">Olá, {{ $recipientName }},</p>
    <p style="margin:0 0 16px 0;">Para continuar o cadastro na <strong>{{ $branding['app_name'] }}</strong>, confirme que este endereço de e-mail é seu.</p>
    <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 auto 24px auto;">
        <tr>
            <td style="border-radius:8px;background-color:{{ $branding['theme_primary'] }};">
                <a href="{{ $verificationUrl }}" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">Confirmar e-mail</a>
            </td>
        </tr>
    </table>
    <p style="margin:0 0 16px 0;font-size:14px;color:#71717a;">Este link expira em {{ $expireMinutes }} minutos.</p>
    <p style="margin:0 0 8px 0;font-size:13px;color:#a1a1aa;word-break:break-all;">Se o botão não funcionar, copie e cole no navegador:<br><a href="{{ $verificationUrl }}" style="color:{{ $branding['theme_primary'] }};">{{ $verificationUrl }}</a></p>
    <p style="margin:16px 0 0 0;font-size:14px;color:#71717a;">Se você não criou uma conta, ignore este e-mail.</p>
@endsection
