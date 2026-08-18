# BSPAY API v2 — LLM Context for Cursor

> Fonte: documento "Integração com IA — BSPAY".
> Objetivo: fornecer contexto persistente para agentes/LLMs implementarem integrações BSPAY com segurança.
> Regra: não invente campos, endpoints, eventos, limites ou comportamentos que não estejam descritos aqui ou na documentação oficial.

## 1. Visão geral

- Base URL: `https://api.bspay.co`
- API: BSPAY API v2
- Formato padrão: JSON
- Header padrão: `Content-Type: application/json`
- Autenticação: OAuth2 Client Credentials
- Access token: Bearer JWT
- Validade informada do token: 1 hora
- Idempotência: use `external_id` nas operações financeiras que o suportam.
- Em operações assíncronas, trate o webhook como fonte de verdade do status final.

### Resposta padrão

Sucesso:

```json
{
  "success": true,
  "data": {},
  "request_id": "...",
  "timestamp": "..."
}
```

Erro:

```json
{
  "success": false,
  "error": {
    "code": "...",
    "message": "..."
  }
}
```

### Status HTTP relevantes

- `200`: operação síncrona concluída
- `202`: operação assíncrona aceita, como cashout/transfer/conversion quando aplicável
- `400`: payload inválido
- `401`: autenticação/HMAC
- `403`: permissão, IP ou conta
- `404`: recurso não encontrado
- `409`: duplicidade/limite/conflito
- `422`: valor/saldo/validação de negócio
- `423`: conta bloqueada
- `429`: rate limit
- `502/503`: erro de provedor/serviço; pode ser retryable

## 2. Moedas e redes

### Fiat

- BRL — PIX
- MXN — SPEI

### Cripto

- USDT
- USDC
- BTC
- ETH
- SOL
- BNB

### Chains

- USDT: `tron`, `ethereum`, `bsc`, `polygon`, `solana`
- USDC: `tron`, `ethereum`, `bsc`, `polygon`, `solana`
- BTC: `bitcoin`
- ETH: `ethereum`
- SOL: `solana`
- BNB: `bsc`

### `chain` vs `network`

- `chain` é usada em cashin/wallet e representa a blockchain de origem.
- `network` é usada em cashout e representa a rede de transmissão da transação.
- Para Tron, BTC, Solana e BSC, normalmente coincidem.
- Para ETH/USDC, podem diferir.
- Não assuma que `chain` e `network` são intercambiáveis.

## 3. Autenticação

### 3.1 Obter Bearer Token

Endpoint:

```http
POST /v2/oauth/token
```

Header:

```http
Authorization: Basic base64(client_id:client_secret)
```

Body:

```json
{
  "grant_type": "client_credentials"
}
```

Resposta esperada inclui:

```json
{
  "access_token": "JWT",
  "expires_in": 3600,
  "meta": {}
}
```

Nas demais rotas:

```http
Authorization: Bearer {access_token}
```

Rate informado para autenticação:

- 30 requisições/minuto/IP
- 5 falhas consecutivas podem causar `IP_BLOCKED_TEMPORARILY`

### Cache do token

- Não gere token em toda requisição.
- Armazene com TTL.
- Renove aproximadamente 60 segundos antes do vencimento.

## 4. HMAC para rotas financeiras

Além do Bearer Token, as seguintes rotas exigem HMAC:

```http
POST /v2/transactions/cashout
POST /v2/internal_transfers/payment
POST /v2/conversions/new
```

Headers adicionais:

```http
X-Signature: <hex hmac sha256>
X-Timestamp: <unix_seconds>
X-Nonce: <uuid_v4>
```

A assinatura é construída sobre:

```text
timestamp + "." + nonce + "." + rawBody
```

usando a signing key fornecida pela BSPAY.

Regras:

- Assine o BODY RAW exato enviado.
- Não reserialize o JSON depois de gerar a assinatura.
- Timestamp aceito: aproximadamente ±5 minutos.
- Nonce deve ser único dentro da janela de validação.
- Faça HMAC somente no backend.
- Nunca exponha `signing_key`, `client_secret` ou outros segredos no frontend.

Erros HMAC conhecidos:

- `MISSING_SIGNATURE`
- `MISSING_TIMESTAMP`
- `MISSING_NONCE`
- `INVALID_SIGNATURE`
- `INVALID_TIMESTAMP`
- `REPLAY_DETECTED`

## 5. Catálogo de endpoints

### Account / Read

```http
GET /v2/account/balance
GET /v2/account/info/profile
GET /v2/account/limits
GET /v2/account/fees
```

Objetivos:

- `balance`: saldos por moeda
- `info/profile`: perfil/KYC
- `limits`: limites da conta
- `fees`: taxas configuradas

### Transactions

```http
POST /v2/transactions/cashin
POST /v2/transactions/cashout
POST /v2/transactions/wallet
```

Objetivos:

- `cashin`: cobrança PIX, cripto ou SPEI
- `cashout`: saque/pagamento PIX, cripto ou SPEI
- `wallet`: criação de wallet/endereço fixo

### Transferências e conversão

```http
POST /v2/internal_transfers/payment
POST /v2/conversions/rate
POST /v2/conversions/simulate
POST /v2/conversions/new
```

### Listagem

```http
POST /v2/account/transactions/list
```

### Infrações PIX / MED

```http
GET  /v2/account/infractions
GET  /v2/account/infractions/detail
POST /v2/account/infractions/reply
```

## 6. Cashin

Endpoint:

```http
POST /v2/transactions/cashin
```

Estrutura documentada:

```json
{
  "amount": 100.00,
  "currency": "BRL",
  "chain": "tron",
  "external_id": "uuid-ou-id-unico",
  "postback_url": "https://seu-dominio.com/webhook",
  "payer": {
    "name": "Nome",
    "document": "CPF-ou-CNPJ",
    "email": "email@exemplo.com"
  }
}
```

Campos observados:

- `amount`: valor
- `currency`: `BRL|MXN|USDT|USDC|BTC|ETH|SOL|BNB`
- `chain`: obrigatório para cripto
- `external_id`: identificador único/idempotência
- `postback_url`: URL de webhook
- `payer.name`
- `payer.document`
- `payer.email`: opcional
- `split`: suportado segundo a documentação, com distribuição por usuário/percentual ou outra modalidade prevista pela API

A resposta contém `data.payment_info`.

Dependendo do meio de pagamento, `payment_info` pode conter:

- PIX: `qrcode`, `expires_at`
- Cripto: `address`, `chain`, `network` e metadados relacionados

Não invente campos adicionais de `payment_info`; valide na documentação oficial antes de implementar algo não listado.

## 7. Cashout

Endpoint:

```http
POST /v2/transactions/cashout
```

Requer HMAC.

Estrutura documentada:

```json
{
  "external_id": "uuid-ou-id-unico",
  "amount": 100.00,
  "currency": "BRL",
  "key": "destino",
  "key_type": "cpf",
  "network": "tron",
  "name": "Nome do recebedor",
  "bank_code": "codigo",
  "description": "descricao",
  "postback_url": "https://seu-dominio.com/webhook"
}
```

Regras por modalidade:

- `key`: chave PIX, endereço cripto ou destino SPEI conforme a moeda
- `key_type`: somente PIX — `cpf|cnpj|email|phone|random`
- `network`: somente cripto
- `name`: pode ser usado no SPEI
- `bank_code`: pode ser usado no SPEI

Resposta inicial documentada:

```json
{
  "transaction_id": "...",
  "status": "pending"
}
```

O resultado final deve ser confirmado por webhook:

- `cashout.confirmed`
- `cashout.failed`

A documentação informa que, em caso de `cashout.failed`, o saldo é refundado automaticamente.

## 8. Wallet fixa

Endpoint:

```http
POST /v2/transactions/wallet
```

Body:

```json
{
  "currency": "USDT",
  "chain": "tron"
}
```

Resposta pode conter:

- `wallet_id`
- `address` ou `pix_key` ou `clabe`
- `currency`
- `chain`
- `min_confirmations`

Depósitos em wallet fixa disparam:

```text
wallet_deposit
```

## 9. Transferência interna BSPAY

Endpoint:

```http
POST /v2/internal_transfers/payment
```

Requer HMAC.

Body:

```json
{
  "username": "destinatario",
  "amount": 100.00,
  "currency": "BRL",
  "description": "descricao",
  "external_id": "uuid-ou-id-unico"
}
```

Resposta esperada inclui:

```json
{
  "transaction_id": "...",
  "from_user": "...",
  "to_user": "...",
  "status": "confirmed"
}
```

Rate informado:

- 10/minuto
- Proteção anti-enumeração

## 10. Conversões

### Cotação rápida

```http
POST /v2/conversions/rate
```

Body:

```json
{
  "amount": 100,
  "base_currency": "USDT",
  "destination_currency": "BRL"
}
```

Resposta inclui:

- `rate`
- `estimated_amount`
- `timestamp`

### Simulação com fees/spread

```http
POST /v2/conversions/simulate
```

Body:

```json
{
  "amount": 100,
  "base_currency": "USDT",
  "destination_currency": "BRL"
}
```

Resposta inclui:

- `from_amount`
- `to_amount`
- `rate`
- `spread`
- `fee`
- `expires_at`

### Executar conversão

```http
POST /v2/conversions/new
```

Requer HMAC.

Body:

```json
{
  "amount": 80,
  "base_currency": "USDT",
  "destination_currency": "BRL",
  "external_id": "uuid-ou-id-unico"
}
```

Resposta inclui dados como:

- `conversion_id`
- `amount_from`
- `currency_from`
- `amount_to`
- `currency_to`
- `rate`

## 11. Extrato / listagem de transações

Endpoint:

```http
POST /v2/account/transactions/list
```

Body documentado:

```json
{
  "page": 1,
  "page_size": 50,
  "status": "pending",
  "type": "cashin",
  "source": "cashin",
  "currency": "BRL",
  "from_date": "YYYY-MM-DD",
  "to_date": "YYYY-MM-DD",
  "transaction_id": "...",
  "external_id": "..."
}
```

Valores documentados:

- `page_size`: padrão 50, máximo 100
- `status`: `pending|confirmed|cancelled|failed`
- `type`: `cashin|cashout`
- `source`: inclui `cashin|cashout|conversion|internal_transfer|wallet_deposit`

## 12. Infrações PIX / MED

### Listar infrações

```http
GET /v2/account/infractions
```

Filtros documentados incluem paginação e status.

### Detalhar infração

```http
GET /v2/account/infractions/detail?id=<infraction_id>
```

Resposta pode incluir:

- `infraction_id`
- `type`
- `status`
- `amount`
- `currency`
- `transaction_id`
- `e2e_id`
- replies/dados relacionados

### Responder infração

```http
POST /v2/account/infractions/reply
```

Content-Type:

```http
multipart/form-data
```

Form:

- `id`
- `message`
- `files[]`

Arquivos documentados:

- PDF
- JPG
- PNG
- até 10 MB por arquivo
- máximo de 5 arquivos

Resposta inclui:

```json
{
  "infraction_id": "...",
  "reply_id": "...",
  "status": "responded"
}
```

## 13. Webhooks

Headers enviados pela BSPAY:

```http
Content-Type: application/json
X-BSPay-Event: <nome-do-evento>
X-BSPay-Signature: <hex hmac sha256 do rawBody>
X-BSPay-Timestamp: <unix_seconds>
```

Assinatura do webhook:

```text
hex(hmac_sha256(rawBody, callback_secret))
```

Valide sempre antes de processar:

1. Capture o RAW BODY exatamente como recebido.
2. Calcule HMAC SHA-256 usando `callback_secret`.
3. Compare assinatura usando comparação em tempo constante.
4. Rejeite timestamp fora da janela de aproximadamente 300 segundos.
5. Só processe o evento após validação.
6. Faça o processamento de forma idempotente.

### Eventos documentados

- `cashin.confirmed`
- `cashin.refunded`
- `cashout.confirmed`
- `cashout.failed`
- `wallet_deposit`
- `transfer.confirmed`
- `conversion.confirmed`
- `chargeback.opened`
- `chargeback.won`
- `chargeback.lost`
- `chargeback.canceled`

Observação: o documento apresenta o bloco como "9 eventos", mas também enumera diferentes estados de chargeback. Preserve os nomes acima e valide o catálogo oficial antes de depender de uma contagem fixa.

### Significados principais

- `cashin.confirmed`: pagamento recebido/saldo creditado
- `cashin.refunded`: cashin estornado
- `cashout.confirmed`: saque liquidado
- `cashout.failed`: saque falhou e saldo é refundado automaticamente
- `wallet_deposit`: depósito em wallet fixa
- `transfer.confirmed`: transferência interna concluída
- `conversion.confirmed`: conversão concluída
- `chargeback.opened`: infração PIX MED aberta; pode envolver bloqueio de saldo e prazo de resposta

### Idempotência de webhook

Use como chave única, conforme o evento:

- `transaction_id`
- `conversion_id`
- `infraction_id`

Não processe o mesmo evento financeiro duas vezes.

### Retry de webhook informado

A BSPAY espera HTTP 2xx.

Tentativas:

1. 1 minuto
2. 5 minutos
3. 15 minutos
4. 1 hora
5. 6 horas

Timeout informado: 10 segundos.

## 14. Códigos de erro

### Auth — 401/403

- `MISSING_AUTH_HEADER`
- `INVALID_AUTH_FORMAT`
- `INVALID_CREDENTIALS`
- `TOKEN_EXPIRED`
- `UNAUTHORIZED`
- `FORBIDDEN`
- `UNAUTHORIZED_IP`
- `INVALID_OTP`
- `CREDENTIAL_PENDING_ADMIN_ACTIVATION`

### HMAC — 401

- `MISSING_SIGNATURE`
- `MISSING_TIMESTAMP`
- `MISSING_NONCE`
- `INVALID_SIGNATURE`
- `INVALID_TIMESTAMP`
- `REPLAY_DETECTED`

### Segurança — 403/423/429

- `RATE_LIMIT_EXCEEDED`
- `IP_BLACKLISTED`
- `IP_BLOCKED_TEMPORARILY`
- `SECURITY_BLOCKED`
- `ACCOUNT_UNDER_REVIEW`

Para `RATE_LIMIT_EXCEEDED`, respeite `Retry-After` quando fornecido.

### Validação — 400/422

- `INVALID_PAYLOAD`
- `INVALID_JSON`
- `MISSING_REQUIRED_FIELD`
- `INVALID_FORMAT`
- `INVALID_VALUE`
- `INVALID_AMOUNT`
- `INVALID_CURRENCY`
- `INVALID_PIX_KEY`
- `INVALID_SPLIT_CONFIG`
- `SELF_TRANSFER_BLOCKED`
- `UNSUPPORTED_CONTENT_TYPE`
- `UNSUPPORTED_CHAIN`
- `UNSUPPORTED_CURRENCY`

### Limites e saldo — 409/422

- `INSUFFICIENT_BALANCE`
- `INSUFFICIENT_FUNDS`
- `BELOW_MIN_LIMIT`
- `EXCEEDS_MAX_LIMIT`
- `LIMIT_EXCEEDED`

### Negócio — 403/404/409

- `PERMISSION_DENIED`
- `KYC_REQUIRED`
- `DUPLICATE_RESOURCE`
- `DUPLICATE_EXTERNAL_ID`
- `PENDING_APPROVAL`
- `MISSING_FEE_CONFIG`

### Sistema — 409/500/502/503

- `INTERNAL_ERROR`
- `PROVIDER_ERROR`
- `SERVICE_UNAVAILABLE`
- `INTERNAL_CONFLICT`

`PROVIDER_ERROR` e `SERVICE_UNAVAILABLE` são indicados como retryable.

`INTERNAL_CONFLICT` é indicado como retryable com novo nonce quando aplicável.

### `cashout.failed` — error_code

- `TRON_ENERGY_FAIL`
- `INSUFFICIENT_BALANCE`
- `NO_HOT_WALLET`
- `INVALID_ADDRESS`
- `BLACKLISTED`
- `BLOCKCHAIN_REVERT`
- `BROADCAST_FAIL`
- `PIX_KEY_NOT_FOUND`
- `PROVIDER_TIMEOUT`
- `UNKNOWN`

## 15. Mapeamento intenção → endpoint

Use este mapa quando o usuário descrever uma intenção em linguagem natural:

| Intenção | Endpoint |
|---|---|
| Cobrar via PIX | `POST /v2/transactions/cashin` com `currency=BRL` |
| Receber USDT em Tron | `POST /v2/transactions/cashin` com `currency=USDT`, `chain=tron` |
| Criar endereço fixo cripto | `POST /v2/transactions/wallet` |
| Pagar parceiro via PIX | `POST /v2/transactions/cashout` com `currency=BRL` |
| Sacar cripto | `POST /v2/transactions/cashout` com `network` apropriada |
| Sacar via SPEI/México | `POST /v2/transactions/cashout` com `currency=MXN` |
| Transferir entre contas BSPAY | `POST /v2/internal_transfers/payment` |
| Consultar cotação | `POST /v2/conversions/rate` |
| Simular conversão com fees | `POST /v2/conversions/simulate` |
| Executar conversão | `POST /v2/conversions/new` |
| Consultar saldo | `GET /v2/account/balance` |
| Consultar perfil/KYC | `GET /v2/account/info/profile` |
| Consultar limites | `GET /v2/account/limits` |
| Consultar taxas | `GET /v2/account/fees` |
| Consultar extrato | `POST /v2/account/transactions/list` |
| Listar infrações MED | `GET /v2/account/infractions` |
| Detalhar infração | `GET /v2/account/infractions/detail` |
| Responder/defender infração | `POST /v2/account/infractions/reply` |

## 16. Regras obrigatórias para o agente no Cursor

Ao implementar BSPAY:

1. Antes de alterar código, analise a arquitetura existente do projeto.
2. Reutilize abstrações existentes de gateway/adquirente/provider quando houver.
3. Não crie uma arquitetura paralela sem necessidade.
4. Nunca hardcode credenciais.
5. Credenciais devem vir de configuração segura/env/database conforme o padrão do projeto.
6. Nunca exponha `client_secret`, `signing_key` ou `callback_secret` no frontend.
7. Cacheie o access token.
8. Gere `external_id` por intenção/operação, não por retry.
9. Em retry da mesma operação, reutilize o mesmo `external_id`.
10. Gere nonce novo quando a tentativa HMAC precisar ser refeita.
11. Assine exatamente o raw body enviado.
12. Use comparação em tempo constante para validar assinatura de webhook.
13. Trate webhook como fonte de verdade para estados assíncronos.
14. Faça webhook idempotente.
15. Não marque cashout como confirmado apenas porque o POST retornou `202`.
16. Registre `request_id`, `transaction_id`, `external_id` e códigos de erro de forma auditável, sem registrar segredos.
17. Diferencie erro retryable de erro definitivo.
18. Respeite `Retry-After` em rate limit.
19. Não faça retry cego de erro de validação, saldo insuficiente, KYC ou permissão.
20. Antes de executar operações que movimentam dinheiro, preserve o mecanismo de confirmação/autorização existente no sistema.
21. Não invente suporte a cartão, boleto ou outro meio não documentado neste contexto.
22. Não invente parâmetros ausentes. Se um detalhe estiver faltando, consulte a documentação oficial antes de codificar.
23. Ao finalizar, crie/atualize testes para autenticação, HMAC, idempotência, webhooks, cashin/cashout e erros.
24. Relate todos os arquivos alterados, migrations, variáveis de ambiente e comandos necessários.
25. Se a tarefa inicial for apenas auditoria/análise, NÃO altere nenhum arquivo até receber autorização explícita.

## 17. Fluxo recomendado de implementação

### Cashin

```text
Aplicação
  -> obtém/cacheia Bearer token
  -> cria external_id idempotente
  -> POST /v2/transactions/cashin
  -> salva transaction_id/external_id/status inicial
  -> entrega QR/address ao checkout
  -> recebe webhook
  -> valida HMAC + timestamp
  -> processa idempotentemente
  -> atualiza status interno
```

### Cashout

```text
Aplicação
  -> valida autorização interna
  -> obtém/cacheia Bearer token
  -> cria/reutiliza external_id
  -> serializa body UMA VEZ
  -> gera timestamp + nonce
  -> assina rawBody
  -> POST /v2/transactions/cashout
  -> recebe 202/pending
  -> NÃO liquida internamente ainda
  -> aguarda cashout.confirmed/cashout.failed
  -> valida webhook
  -> reconcilia estado interno
```

## 18. Checklist antes de considerar a integração pronta

- [ ] OAuth2 Client Credentials implementado
- [ ] Token com cache e renovação antes do expiry
- [ ] Segredos apenas no backend
- [ ] HMAC implementado nas rotas obrigatórias
- [ ] Raw body preservado na assinatura
- [ ] Timestamp e nonce implementados
- [ ] `external_id` idempotente
- [ ] Cashin funcionando
- [ ] Cashout assíncrono funcionando
- [ ] Webhook validando assinatura
- [ ] Webhook validando timestamp
- [ ] Webhook idempotente
- [ ] `cashout.failed` tratado
- [ ] Retry policies diferenciadas por tipo de erro
- [ ] Rate limit tratado
- [ ] Logs sem segredos
- [ ] Testes automatizados
- [ ] Reconciliação via listagem de transações quando necessária

## 19. Regra de não alucinação

Se o código precisar de qualquer informação que não esteja neste arquivo — por exemplo:

- nome exato de um campo não documentado
- enum completo
- limite mínimo/máximo
- estrutura integral de uma resposta
- algoritmo alternativo de assinatura
- suporte a uma nova rede/moeda
- evento de webhook não listado
- endpoint de refund não listado
- cartão/boleto

não presuma.

Pare a implementação daquele detalhe e consulte a documentação oficial BSPAY ou solicite ao usuário a seção correspondente.

---

Este arquivo deve funcionar como contexto de integração. Ele não substitui a documentação oficial da BSPAY.
