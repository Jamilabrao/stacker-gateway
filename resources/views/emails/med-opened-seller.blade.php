@extends('emails.layouts.branded')

@section('content')
    <p style="margin:0 0 16px 0;font-size:18px;font-weight:600;color:#18181b;">Disputa MED aberta</p>
    <p style="margin:0 0 16px 0;">Uma disputa MED (Mecanismo Especial de Devolução) foi aberta sobre um pagamento PIX via API. O valor pode estar retido até a resolução.</p>
    <table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;margin:0 0 24px 0;background-color:#fafafa;border-radius:8px;border:1px solid #e4e4e7;">
        <tr>
            <td style="padding:16px 20px;">
                @if($productName)
                    <p style="margin:0 0 8px 0;font-size:13px;text-transform:uppercase;letter-spacing:0.05em;color:#71717a;">Produto</p>
                    <p style="margin:0 0 16px 0;font-weight:600;color:#18181b;">{{ $productName }}</p>
                @endif
                @if($order)
                    <p style="margin:0 0 8px 0;font-size:13px;text-transform:uppercase;letter-spacing:0.05em;color:#71717a;">Pedido</p>
                    <p style="margin:0 0 16px 0;font-weight:600;color:#18181b;">#{{ $order->public_reference ?? $order->id }} — R$ {{ number_format((float) $order->amount, 2, ',', '.') }}</p>
                @endif
                @if($dispute->reason)
                    <p style="margin:0 0 8px 0;font-size:13px;text-transform:uppercase;letter-spacing:0.05em;color:#71717a;">Motivo</p>
                    <p style="margin:0;color:#18181b;">{{ $dispute->reason }}</p>
                @endif
            </td>
        </tr>
    </table>
    <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 auto;">
        <tr>
            <td style="border-radius:8px;background-color:{{ $branding['theme_primary'] }};">
                <a href="{{ $disputeUrl }}" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">Ver disputa e enviar defesa</a>
            </td>
        </tr>
    </table>
@endsection
