<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprovante indisponível — Saque #{{ $withdrawal_id }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f1115;
            color: #f4f4f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }
        .card {
            max-width: 420px;
            width: 100%;
            background: #18181b;
            border: 1px solid #27272a;
            border-radius: 14px;
            padding: 28px 24px;
        }
        h1 { font-size: 18px; font-weight: 700; margin-bottom: 10px; }
        p { font-size: 14px; color: #a1a1aa; line-height: 1.5; }
        .meta { margin-top: 16px; font-size: 12px; color: #71717a; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Comprovante indisponível</h1>
        <p>
            O comprovante PIX só fica disponível depois que o saque
            <strong>#{{ $withdrawal_id }}</strong> é marcado como pago.
        </p>
        <p class="meta">Status atual: {{ $status !== '' ? $status : '—' }}</p>
    </div>
</body>
</html>
