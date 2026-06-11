@extends('emails.layouts.branded')

@section('content')
    <p style="margin:0 0 16px 0;font-size:18px;font-weight:600;color:#18181b;">Afiliação aprovada</p>
    <p style="margin:0 0 16px 0;">Olá, {{ $recipientName }},</p>
    <p style="margin:0 0 16px 0;">Sua solicitação de afiliação ao produto <strong>{{ $productName }}</strong> foi <strong style="color:#15803d;">aprovada</strong>. Use o link exclusivo abaixo para divulgar e rastrear suas vendas.</p>

    @if($affiliateLink)
        <p style="margin:0 0 8px 0;font-size:14px;font-weight:600;color:#3f3f46;">Seu link de afiliação (checkout)</p>
        <p style="margin:0 0 16px 0;word-break:break-all;font-size:13px;color:#52525b;background:#f4f4f5;padding:12px;border-radius:8px;">{{ $affiliateLink }}</p>
    @endif

    @if($materialsUrl)
        <p style="margin:0 0 8px 0;font-size:14px;font-weight:600;color:#3f3f46;">Materiais para afiliados</p>
        <p style="margin:0 0 16px 0;word-break:break-all;font-size:13px;">
            <a href="{{ $materialsUrl }}" style="color:{{ $branding['theme_primary'] }};">{{ $materialsUrl }}</a>
        </p>
    @endif

    <p style="margin:0 0 16px 0;font-size:14px;color:#52525b;">No painel do afiliado você pode copiar o link, configurar pixels de conversão e acompanhar suas divulgações.</p>

    <table role="presentation" cellspacing="0" cellpadding="0" style="margin:24px auto 0 auto;">
        <tr>
            <td style="border-radius:8px;background-color:{{ $branding['theme_primary'] }};">
                <a href="{{ $panelUrl }}" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">Abrir painel do afiliado</a>
            </td>
        </tr>
    </table>
@endsection
