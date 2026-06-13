# API PIX (Gateway)

Integre **PIX** (e opcionalmente cartão e boleto) na sua plataforma: marketplaces, ERPs, SaaS e parceiros criam cobranças via REST, exibem QR Code ou copia e cola no próprio fluxo e recebem **webhooks** quando o pagamento muda de estado.

**REST** · **JSON** · **Base URL:** `https://seudominio.com/api/v1` (substitua pelo domínio da instalação).

**Autenticação (recomendado):** headers `X-Public-Key` e `X-Secret-Key` (par obtido no painel em **Chaves da API** → `/aplicacoes-api`). Legado: `Authorization: Bearer …` ou `X-API-Key`.

- Documentação interativa: `/docs/api-pagamentos`
- Testar endpoints: `/docs/api-pagamentos/testar`

---

## Pré-requisitos

- Conta de infoprodutor ativa
- **API PIX** habilitada para o tenant
- Gateway PIX conectado (Integrações → Gateways)
- Aplicação criada em `/aplicacoes-api` com status **ativo**
- Webhook URL em HTTPS (recomendado)
- IPs permitidos vazio ou IP do seu servidor na lista

---

## Integração em 5 passos

1. Copie Public key e Secret key em `/aplicacoes-api`
2. `POST /api/v1/payments/pix` com `customer.email` e valor
3. Exiba `copy_paste` e/ou `qrcode` ao cliente
4. Confirme via webhook `order.completed` ou `GET /api/v1/payments/{order_id}`
5. Correlacione com `metadata.external_id` (ou campo que você enviar)

---

## Regra importante: amount vs product_id

| Cenário | Comportamento |
|---------|---------------|
| **Sem** `product_id` | Valor cobrado = `amount` enviado no body |
| **Com** `product_id` | Valor = preço do produto, oferta ou plano — **`amount` do body é ignorado** |

---

## Exemplo curl — criar PIX

```bash
curl -X POST 'https://seudominio.com/api/v1/payments/pix' \
  -H 'Content-Type: application/json' \
  -H 'X-Public-Key: gpk_sua_public_key' \
  -H 'X-Secret-Key: gsk_sua_secret_key' \
  -H 'Idempotency-Key: pedido-123-pix' \
  -d '{
    "customer": {
      "email": "cliente@exemplo.com",
      "name": "Cliente Teste",
      "cpf": "52998224725"
    },
    "amount": 97.90,
    "currency": "BRL",
    "metadata": { "external_id": "ped-1001" }
  }'
```

**Resposta 201:**

```json
{
  "order_id": 456,
  "transaction_id": "efi-tx-abc123",
  "qrcode": "data:image/png;base64,...",
  "copy_paste": "00020126580014br.gov.bcb.pix...",
  "status": "pending"
}
```

---

## Body mínimo (POST /payments/pix)

| Campo | Obrigatório | Descrição |
|-------|-------------|-----------|
| `customer.email` | Sim | E-mail do comprador |
| `customer.name` | Não | Nome (default: e-mail) |
| `customer.cpf` | Não | CPF |
| `amount` | Sim* | Valor em reais (*ignorado se `product_id` definir preço) |
| `currency` | Não | BRL (default), USD ou EUR |
| `product_id` | Não | UUID do produto no catálogo |
| `metadata` | Não | Objeto livre — devolvido no webhook |
| `idempotency_key` | Não | Ou header `Idempotency-Key` (máx. 128 chars) |

---

## Consultar status

`GET /api/v1/payments/{order_id}` — retorna pedidos **somente da mesma aplicação** autenticada.

Status comuns: `pending`, `completed`, `cancelled`, `refunded`, `disputed`.

---

## Resumo dos endpoints

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| POST | /api/v1/payments/pix | Criar cobrança PIX (QR + copia e cola) |
| GET | /api/v1/payments/{order_id} | Consultar status do pedido |
| POST | /api/v1/pix/{order_id}/cancel | Cancelar PIX pendente |
| POST | /api/v1/pix/{order_id}/refund | Estornar PIX pago / em disputa |
| POST | /api/v1/checkout/sessions | Sessão Checkout Pro (link) |
| POST | /api/v1/payments/card | Pagamento com cartão |
| POST | /api/v1/payments/boleto | Boleto |

---

## Webhooks

Configure `webhook_url` na aplicação. Eventos: `order.pending`, `order.completed`, `order.refunded`, `order.cancelled`.

**Payload exemplo (`order.completed`):**

```json
{
  "event": "order.completed",
  "order_id": 456,
  "amount": 97.90,
  "status": "completed",
  "email": "cliente@exemplo.com",
  "metadata": { "external_id": "ped-1001", "source": "api" },
  "customer": { "name": "Cliente", "email": "cliente@exemplo.com", "document": "52998224725" },
  "created_at": "2026-06-13T14:00:00.000000Z",
  "updated_at": "2026-06-13T14:05:12.000000Z"
}
```

**Assinatura:** header `X-Webhook-Signature` = HMAC-SHA256 do body bruto com o webhook secret.

---

## Erros frequentes

| HTTP | Mensagem | Ação |
|------|----------|------|
| 401 | Missing or invalid API key. | Envie `X-Public-Key` + `X-Secret-Key` |
| 401 | Invalid API key. | Verifique o par em `/aplicacoes-api` |
| 403 | API application is disabled. | Ative a aplicação |
| 403 | IP not allowed. | Adicione IP ou deixe lista vazia |
| 403 | API PIX disabled for this tenant. | Habilite API PIX |
| 404 | Pedido não encontrado. | Use `order_id` da mesma app |
| 422 | Não foi possível gerar o PIX. | Verifique gateway PIX conectado |

---

## Boas práticas

- Chame a API **apenas do servidor**; não exponha a Secret no frontend
- Use **HTTPS** e **Idempotency-Key** em toda criação de pagamento
- Use `metadata` para correlacionar com o pedido no seu sistema
- Valide assinatura do webhook em produção

Detalhes completos, exemplos Node.js e tabelas: `/docs/api-pagamentos`
