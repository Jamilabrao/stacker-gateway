@extends('emails.layouts.branded')

@section('content')
    <p style="margin:0 0 16px 0;font-size:18px;font-weight:600;color:#18181b;">Disputa MED encerrada</p>
    <p style="margin:0 0 16px 0;">A disputa MED do pedido #{{ $order->public_reference ?? $order->id }} foi encerrada com resultado: <strong>{{ $dispute->outcome ?? $dispute->status }}</strong>.</p>
    @if($productName)
        <p style="margin:0 0 8px 0;color:#52525b;">Produto: {{ $productName }}</p>
    @endif
    <table role="presentation" cellspacing="0" cellpadding="0" style="margin:16px auto 0;">
        <tr>
            <td style="border-radius:8px;background-color:{{ $branding['theme_primary'] }};">
                <a href="{{ $disputeUrl }}" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">Ver detalhes</a>
            </td>
        </tr>
    </table>
@endsection
