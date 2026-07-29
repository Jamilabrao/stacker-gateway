# Instruções para o modelo de IA

> Pacote gerado para integração com a API de Pagamentos e Saques do gateway.
> Base URL da instalação: `{{BASE_URL}}`

## INSTRUÇÕES PARA O MODELO (obrigatório)

1. **Não invente rotas.** Use apenas endpoints documentados em `{{BASE_URL}}/api/v1`.
2. **Segredos só no servidor.** `X-Secret-Key` e `webhook_secret` nunca vão para o browser, bundle frontend ou repositório público.
3. **Valores em reais.** O campo `amount` é decimal em BRL (ex.: `97.90` = R$ 97,90), salvo quando `product_id` define o preço.
4. **Autenticação recomendada:** headers `X-Public-Key` e `X-Secret-Key` em todas as requisições.
5. **Webhook primeiro.** Confirme pagamentos via webhook `order.completed` com validação HMAC-SHA256 no body bruto.
6. **Reconciliação obrigatória.** Implemente job em background com `GET /api/v1/payments` (ou `GET /api/v1/payments/{order_id}`) a cada 60–120 s por 6–12 h.
7. **Idempotência.** Use `Idempotency-Key` em `POST /api/v1/payments/pix` e handlers idempotentes por `event_id` / `order_id`.
8. **Não libere produto só com polling do frontend.** Polling na UI é apenas UX; a liberação deve ocorrer no backend (webhook ou reconciliação).
9. **Provisionar webhook via API** com `PUT /api/v1/webhook` quando o vendedor conectar credenciais na sua plataforma.
10. **Escopos mínimos para parceiros:** `payments:read`, `payments:write`, `payments:refund`, `webhooks:write`.

## URLs oficiais desta instalação

| Recurso | URL |
|---------|-----|
| API REST | `{{BASE_URL}}/api/v1` |
| Documentação humana | `{{BASE_URL}}/docs/api-pagamentos` |
| Playground de testes | `{{BASE_URL}}/docs/api-pagamentos/testar` |
| Hub para IAs | `{{BASE_URL}}/docs/api-pagamentos/ia` |
| Download deste pacote | `{{BASE_URL}}/docs/api-pagamentos/llm/full.md` |
| Chaves da API (painel vendedor) | `{{BASE_URL}}/aplicacoes-api` |

## Fluxo mínimo PIX (parceiro)

1. Vendedor habilita **API PIX** e cria integração em `/aplicacoes-api`.
2. Vendedor informa Public key + Secret key na sua plataforma.
3. Seu backend chama `PUT /api/v1/webhook` com URL HTTPS de recebimento.
4. Armazene o `webhook_secret` retornado (somente na 1ª configuração ou com `rotate_secret: true`).
5. Crie cobranças com `POST /api/v1/payments/pix`.
6. Confirme pagamento via webhook e/ou reconciliação; libere o produto no mesmo handler idempotente.

## Pré-requisitos

- Conta de vendedor ativa e aprovada (KYC/conta operacional).
- API PIX habilitada para o tenant.
- Integração em `/aplicacoes-api` com status **ativo**.
- IPs permitidos vazio (qualquer IP) ou IP do servidor na lista.
