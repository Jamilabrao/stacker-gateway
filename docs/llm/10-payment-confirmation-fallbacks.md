# Confirmação de pagamento e fallbacks

Use **três camadas** para não perder confirmações PIX. Apenas a camada 1 e 2 devem liberar produto/serviço.

## Camadas

| Camada | Onde roda | Papel |
|--------|-----------|-------|
| 1. Webhook | Seu backend | Canal primário — `order.completed` com HMAC; responda 2xx rápido; idempotente por `event_id` |
| 2. Reconciliação | Job/cron no servidor | Fallback obrigatório — `GET /payments` ou `GET /payments/{order_id}` a cada 60–120 s por 6–12 h |
| 3. Polling na UI | Browser (checkout) | Apenas UX — **não** libere produto só com polling do frontend |

## Checklist de produção

- [ ] Webhook provisionado via `PUT /api/v1/webhook` com URL HTTPS
- [ ] `webhook_secret` armazenado com segurança (env/secret manager)
- [ ] Validação HMAC em todo POST recebido (`X-Webhook-Signature` no body bruto)
- [ ] Deduplicação por `event_id` e/ou `order_id` já processado
- [ ] Job de reconciliação em produção (intervalo 60–120 s)
- [ ] Mesmo handler idempotente para webhook e reconciliação
- [ ] Secret key apenas no servidor — nunca no browser
- [ ] `Idempotency-Key` em `POST /payments/pix`

## Validar webhook (PHP)

```php
$raw = file_get_contents('php://input');
$secret = 'seu_webhook_secret';
$expected = hash_hmac('sha256', $raw, $secret);
$received = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
if (!hash_equals($expected, $received)) {
    http_response_code(401);
    exit;
}
```

## Validar webhook (Node.js)

```javascript
import crypto from 'crypto';

const expected = crypto
  .createHmac('sha256', process.env.WEBHOOK_SECRET)
  .update(rawBody)
  .digest('hex');

if (req.headers['x-webhook-signature'] !== expected) {
  return res.status(401).end();
}
```

## Reconciliação (PHP — exemplo)

```php
public function handle(GetfyApiClient $getfy): void
{
    $cutoff = now()->subHours(12);

    $pendingOrders = Order::query()
        ->where('payment_status', 'pending')
        ->whereNotNull('getfy_order_id')
        ->where('created_at', '>=', $cutoff)
        ->get();

    foreach ($pendingOrders as $order) {
        $payment = $getfy->getPayment($order->getfy_order_id);
        if (($payment['status'] ?? '') === 'completed') {
            app(MarkOrderPaidAction::class)->handle($order, source: 'reconciliation');
        }
    }
}
```

## Reconciliação (Node.js — exemplo)

```javascript
import cron from 'node-cron';

cron.schedule('*/2 * * * *', async () => {
  const pending = await db.orders.findMany({
    where: { paymentStatus: 'pending', getfyOrderId: { not: null } },
  });

  for (const order of pending) {
    const res = await fetch(`${GETFY_BASE}/api/v1/payments/${order.getfyOrderId}`, {
      headers: {
        'X-Public-Key': process.env.GETFY_PUBLIC_KEY,
        'X-Secret-Key': process.env.GETFY_SECRET_KEY,
      },
    });
    const payment = await res.json();
    if (payment.status === 'completed') {
      await markOrderPaid(order.id, { source: 'reconciliation' });
    }
  }
});
```

## Handler idempotente

```php
public function markOrderPaid(Order $order, string $source): void
{
    if ($order->payment_status === 'paid') {
        return;
    }
    $order->update(['payment_status' => 'paid', 'paid_at' => now()]);
    // liberar produto, enviar e-mail, etc.
}
```

## Eventos de webhook (pagamentos)

| Evento | Quando |
|--------|--------|
| `order.pending` | Pedido criado; aguardando pagamento |
| `pix.generated` | QR Code e copia e cola disponíveis |
| `order.completed` | Pagamento confirmado — libere produto/serviço |
| `order.refunded` | Estorno concluído |
| `order.cancelled` | Pedido cancelado |
| `order.expired` | PIX expirou sem pagamento |
