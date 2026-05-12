# API PIX (Gateway)

Integre **PIX** (e opcionalmente cartão e boleto) na sua plataforma: marketplaces, ERPs, SaaS e parceiros criam cobranças via REST, exibem QR Code ou copia e cola no próprio fluxo e recebem **webhooks** quando o pagamento muda de estado.

**REST** · **JSON** · **Base URL:** use a URL pública da instalação do gateway, com prefixo `/api/v1` (ex.: `https://seudominio.com/api/v1`).

**Autenticação (recomendado):** headers `X-Public-Key` e `X-Secret-Key` (par obtido no painel em **Chaves da API**). Compatibilidade legada: `Authorization: Bearer …` ou `X-API-Key` com a API key antiga, se ainda estiver em uso.

Documentação interativa: `/docs/api-pagamentos`

---

## Início rápido

- Todas as rotas sob `/api/v1`.
- Envie `X-Public-Key` + `X-Secret-Key` em todas as requisições.
- **PIX transparente:** `POST /api/v1/payments/pix` → resposta com `qrcode` e `copy_paste`.
- **Status:** `GET /api/v1/payments/{order_id}`.
- **Cancelar PIX pendente:** `POST /api/v1/pix/{order_id}/cancel`.
- **Estornar PIX pago:** `POST /api/v1/pix/{order_id}/refund`.
- **Checkout hospedado:** `POST /api/v1/checkout/sessions` → link para o cliente concluir o pagamento na página hospedada.
- **Cartão / boleto (mesma conta):** `POST /api/v1/payments/card` e `POST /api/v1/payments/boleto`.

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

## Parceiros

1. O vendedor habilita a API e copia **Public key** e **Secret key** no painel.
2. O **seu backend** chama a API com esses headers.
3. Você mostra o PIX ao cliente final; o pagamento é processado pela infraestrutura ligada à conta.
4. Configure `webhook_url` na integração para receber `order.completed`, `order.pending`, `order.refunded`, `order.cancelled`.

---

## Webhooks

Eventos típicos: `order.completed`, `order.pending`, `order.refunded`, `order.cancelled`.  
Assinatura opcional: header **`X-Webhook-Signature`** — HMAC-SHA256 do body bruto (JSON) com o webhook secret configurado no painel.

---

## Boas práticas

- Chame a API apenas do servidor; não exponha a **secret** no front.
- Use **HTTPS** e **idempotency key** nas criações de pagamento.
- Use `metadata` para correlacionar com o pedido no seu sistema.

*(Detalhes completos de body, exemplos JSON e tabelas de campos estão na página `/docs/api-pagamentos`.)*
