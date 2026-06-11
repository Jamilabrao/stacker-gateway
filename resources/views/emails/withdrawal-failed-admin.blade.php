@extends('emails.layouts.branded')

@section('content')
    <p style="margin:0 0 16px 0;font-size:18px;font-weight:600;color:#18181b;">Saque com falha definitiva</p>
    <p style="margin:0 0 16px 0;">Um saque de infoprodutor foi marcado como <strong>falhou</strong> e pode exigir ação na plataforma.</p>
    <table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;margin:0 0 24px 0;background-color:#fafafa;border-radius:8px;border:1px solid #e4e4e7;">
        <tr>
            <td style="padding:16px 20px;">
                <p style="margin:0 0 8px 0;font-size:13px;text-transform:uppercase;letter-spacing:0.05em;color:#71717a;">Saque</p>
                <p style="margin:0 0 16px 0;font-weight:600;color:#18181b;">#{{ $withdrawal->id }} — R$ {{ number_format((float) $withdrawal->amount, 2, ',', '.') }}</p>
                @if($merchantName)
                    <p style="margin:0 0 8px 0;font-size:13px;text-transform:uppercase;letter-spacing:0.05em;color:#71717a;">Infoprodutor</p>
                    <p style="margin:0 0 4px 0;font-weight:600;color:#18181b;">{{ $merchantName }}</p>
                    @if($merchantEmail)
                        <p style="margin:0 0 16px 0;color:#52525b;">{{ $merchantEmail }}</p>
                    @endif
                @endif
                <p style="margin:0 0 8px 0;font-size:13px;text-transform:uppercase;letter-spacing:0.05em;color:#71717a;">Motivo</p>
                <p style="margin:0;color:#18181b;">{{ $reason }}</p>
            </td>
        </tr>
    </table>
    <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 auto;">
        <tr>
            <td style="border-radius:8px;background-color:{{ $branding['theme_primary'] }};">
                <a href="{{ $reviewUrl }}" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">Ver saques na plataforma</a>
            </td>
        </tr>
    </table>
    <p style="margin:24px 0 0 0;font-size:13px;color:#71717a;">Se o botão não funcionar, copie e cole este link no navegador:<br><span style="word-break:break-all;color:#52525b;">{{ $reviewUrl }}</span></p>
@endsection
