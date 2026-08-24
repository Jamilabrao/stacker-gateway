<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprovante Pix — Saque #{{ $withdrawal_id }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f1115;
            color: #f4f4f5;
            min-height: 100vh;
            padding: 24px 16px 48px;
        }
        .wrap { max-width: 420px; margin: 0 auto; }
        .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; }
        .brand img { height: 32px; width: auto; max-width: 180px; object-fit: contain; display: block; }
        .brand span { font-weight: 700; font-size: 18px; letter-spacing: 0.02em; }
        h1 { font-size: 20px; font-weight: 700; margin-bottom: 20px; }
        .summary {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 24px;
        }
        .icon {
            width: 44px; height: 44px; border-radius: 50%;
            background: {{ $theme_primary }};
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; color: #052e16; flex-shrink: 0;
        }
        .amount-block .label { font-size: 12px; color: #a1a1aa; margin-bottom: 2px; }
        .amount-block .value { font-size: 22px; font-weight: 700; }
        .status-block {
            margin-left: auto;
            text-align: right;
            border-left: 3px solid {{ $theme_primary }};
            padding-left: 12px;
        }
        .status-block .status {
            color: {{ $theme_primary }};
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 4px;
        }
        .status-block .datetime { font-size: 12px; color: #d4d4d8; }
        section { margin-bottom: 20px; padding-top: 16px; border-top: 1px solid #27272a; }
        section h2 { font-size: 14px; font-weight: 700; margin-bottom: 12px; }
        .row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-size: 13px;
            margin-bottom: 8px;
        }
        .row .k { color: #a1a1aa; flex-shrink: 0; }
        .row .v { text-align: right; word-break: break-word; max-width: 62%; }
        .footer { margin-top: 28px; font-size: 11px; color: #71717a; line-height: 1.5; }
        .footer .row .v { font-family: ui-monospace, monospace; font-size: 10px; }
        .print-btn {
            display: block; width: 100%; margin-top: 24px;
            padding: 12px; border: 1px solid #3f3f46; border-radius: 10px;
            background: #18181b; color: #fafafa; font-size: 14px; cursor: pointer;
        }
        @media print {
            body { background: #fff; color: #111; padding: 0; }
            .print-btn { display: none; }
            .status-block .status { color: #15803d; }
            .icon { background: #dcfce7; }
            section { border-color: #e4e4e7; }
            .row .k { color: #52525b; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand">
            @if($app_logo !== '')
                <img src="{{ $app_logo }}" alt="{{ $app_name }}">
            @else
                <span>{{ $app_name }}</span>
            @endif
        </div>

        <h1>Comprovante Pix</h1>

        <div class="summary">
            <div class="icon">$</div>
            <div class="amount-block">
                <div class="label">Valor</div>
                <div class="value">{{ $amount_formatted }}</div>
            </div>
            <div class="status-block">
                <div class="status">Pagamento realizado</div>
                <div class="datetime">{{ $paid_at_formatted }}</div>
            </div>
        </div>

        @if(!empty($show_payer_section))
        <section>
            <h2>Quem pagou</h2>
            <div class="row"><span class="k">Nome</span><span class="v">{{ $payer_name }}</span></div>
            <div class="row"><span class="k">Documento</span><span class="v">{{ $payer_document }}</span></div>
            <div class="row"><span class="k">Instituição</span><span class="v">{{ $payer_institution }}</span></div>
        </section>
        @endif

        <section>
            <h2>Quem recebeu</h2>
            <div class="row"><span class="k">Nome</span><span class="v">{{ $receiver_name }}</span></div>
            <div class="row"><span class="k">Documento</span><span class="v">{{ $receiver_document }}</span></div>
            <div class="row"><span class="k">Instituição</span><span class="v">{{ $receiver_institution }}</span></div>
            <div class="row"><span class="k">Chave Pix</span><span class="v">{{ $pix_key }}</span></div>
        </section>

        <section class="footer">
            <div class="row"><span class="k">Autenticação</span><span class="v">{{ $authentication }}</span></div>
            <div class="row"><span class="k">Identificação</span><span class="v">{{ $identification }}</span></div>
        </section>

        <button type="button" class="print-btn" onclick="window.print()">Imprimir / Salvar PDF</button>
    </div>
</body>
</html>
