@extends('emails.layouts.branded')

@section('content')
    <p style="margin:0 0 16px 0;font-size:18px;font-weight:600;color:#18181b;">Recuperação do PIN de aprovação manual</p>
    <p style="margin:0 0 16px 0;">Um administrador da plataforma solicitou a redefinição do PIN usado para aprovar saques manualmente.</p>
    <table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;margin:0 0 24px 0;background-color:#fafafa;border-radius:8px;border:1px solid #e4e4e7;">
        <tr>
            <td style="padding:16px 20px;">
                <p style="margin:0 0 8px 0;font-size:13px;text-transform:uppercase;letter-spacing:0.05em;color:#71717a;">Solicitado por</p>
                <p style="margin:0 0 16px 0;font-weight:600;color:#18181b;">{{ $requestedBy->name }} ({{ $requestedBy->email }})</p>
                <p style="margin:0 0 8px 0;font-size:13px;text-transform:uppercase;letter-spacing:0.05em;color:#71717a;">Novo PIN temporário</p>
                <p style="margin:0;font-size:24px;font-weight:700;letter-spacing:0.2em;color:#18181b;font-family:monospace;">{{ $pin }}</p>
            </td>
        </tr>
    </table>
    <p style="margin:0 0 16px 0;color:#52525b;">Use este PIN para aprovar saques enquanto o automático estiver desligado. Por segurança, altere-o em <strong>Financeiro &gt; Saques</strong> assim que possível.</p>
    <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 auto;">
        <tr>
            <td style="border-radius:8px;background-color:{{ $branding['theme_primary'] }};">
                <a href="{{ $settingsUrl }}" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">Abrir política de saques</a>
            </td>
        </tr>
    </table>
    <p style="margin:24px 0 0 0;font-size:13px;color:#71717a;">Se você não solicitou esta alteração, revise o acesso ao painel da plataforma imediatamente.</p>
@endsection
