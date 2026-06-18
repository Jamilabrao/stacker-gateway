@extends('emails.layouts.branded')

@section('content')
    <p style="margin:0 0 16px 0;font-size:18px;font-weight:600;color:#18181b;">MED encerrada</p>
    <p style="margin:0 0 16px 0;">Disputa #{{ $dispute->id }} — resultado: <strong>{{ $dispute->outcome ?? $dispute->status }}</strong>.</p>
    @if($merchantName)
        <p style="margin:0 0 8px 0;color:#52525b;">Infoprodutor: {{ $merchantName }}</p>
    @endif
    @if($order)
        <p style="margin:0 0 8px 0;color:#52525b;">Pedido #{{ $order->id }}</p>
    @endif
    <table role="presentation" cellspacing="0" cellpadding="0" style="margin:16px auto 0;">
        <tr>
            <td style="border-radius:8px;background-color:{{ $branding['theme_primary'] }};">
                <a href="{{ $disputeUrl }}" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">Ver disputa</a>
            </td>
        </tr>
    </table>
@endsection
