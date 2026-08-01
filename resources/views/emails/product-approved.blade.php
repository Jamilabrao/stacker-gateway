@extends('emails.layouts.branded')

@section('content')
    <p style="margin:0 0 16px 0;font-size:18px;font-weight:600;color:#18181b;">Seu produto foi aprovado</p>
    <p style="margin:0 0 16px 0;">Olá, {{ $recipientName }},</p>
    <p style="margin:0 0 16px 0;">O produto <strong>“{{ $productName }}”</strong> foi aprovado pela equipe da plataforma.</p>
    <p style="margin:0 0 24px 0;">Ele já está liberado para ativação e venda, conforme as configurações da sua conta e do produto.</p>
    <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 auto;">
        <tr>
            <td style="border-radius:8px;background-color:{{ $branding['theme_primary'] }};">
                <a href="{{ $panelUrl }}" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">Acessar painel</a>
            </td>
        </tr>
    </table>
@endsection
