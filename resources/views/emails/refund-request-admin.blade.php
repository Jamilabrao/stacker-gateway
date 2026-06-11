@extends('emails.layouts.branded')

@section('content')
    <p style="margin:0 0 16px 0;font-size:18px;font-weight:600;color:#18181b;">Nova solicitação de reembolso</p>
    <p style="margin:0 0 16px 0;">Um cliente solicitou reembolso de uma compra. O infoprodutor foi notificado; acompanhe na plataforma se necessário.</p>
    <table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;margin:0 0 24px 0;background-color:#fafafa;border-radius:8px;border:1px solid #e4e4e7;">
        <tr>
            <td style="padding:16px 20px;">
                @if($productName)
                    <p style="margin:0 0 8px 0;font-size:13px;text-transform:uppercase;letter-spacing:0.05em;color:#71717a;">Produto</p>
                    <p style="margin:0 0 16px 0;font-weight:600;color:#18181b;">{{ $productName }}</p>
                @endif
                @if($customerEmail)
                    <p style="margin:0 0 8px 0;font-size:13px;text-transform:uppercase;letter-spacing:0.05em;color:#71717a;">Cliente</p>
                    <p style="margin:0 0 16px 0;font-weight:600;color:#18181b;">{{ $customerEmail }}</p>
                @endif
                @if($order)
                    <p style="margin:0 0 8px 0;font-size:13px;text-transform:uppercase;letter-spacing:0.05em;color:#71717a;">Pedido</p>
                    <p style="margin:0 0 16px 0;font-weight:600;color:#18181b;">#{{ $order->id }} — R$ {{ number_format((float) $order->amount, 2, ',', '.') }}</p>
                @endif
                @if($refundRequest->customer_reason)
                    <p style="margin:0 0 8px 0;font-size:13px;text-transform:uppercase;letter-spacing:0.05em;color:#71717a;">Motivo informado</p>
                    <p style="margin:0;color:#18181b;">{{ $refundRequest->customer_reason }}</p>
                @endif
            </td>
        </tr>
    </table>
    <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 auto;">
        <tr>
            <td style="border-radius:8px;background-color:{{ $branding['theme_primary'] }};">
                <a href="{{ $transactionsUrl }}" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">Ver transações na plataforma</a>
            </td>
        </tr>
    </table>
    <p style="margin:24px 0 0 0;font-size:13px;color:#71717a;">Se o botão não funcionar, copie e cole este link no navegador:<br><span style="word-break:break-all;color:#52525b;">{{ $transactionsUrl }}</span></p>
@endsection
