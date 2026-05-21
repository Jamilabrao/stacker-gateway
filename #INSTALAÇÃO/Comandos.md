Comando para instalação.
Execute no Terminal da sua VPS:

bash -c "$(curl -fsSL https://raw.githubusercontent.com/seu-usuario/seu-repositorio/main/install.sh)"

Exemplo:
bash -c "$(curl -fsSL https://raw.githubusercontent.com/LeonardoIsrael0516/getfy-gateway/main/install.sh)"

Importante: você precisa fazer upload dos arquivos para um novo repositorio no GitHub.
(Quando for fazer a instalação ou atualização, deixe o repositório público temporariamente)

-------------

Comando para Atualização:

bash -c "$(curl -fsSL https://raw.githubusercontent.com/LeonardoIsrael0516/getfy-gateway/main/update.sh)"

Qualquer modificação que você fizer no código, após finalizado, basta subir o repositorio para o github novamente, usando o GitHub Desktop ou pelo comando no terminal 
git add .
git commit -m update
git push

O script de atualização (`update.sh`) já roda `npm run build` automaticamente (via Docker) antes de reiniciar os containers — não é obrigatório commitar `public/build` no Git, mas pode commitar se quiser builds reproduzíveis offline.

Para pular o build (ex.: VPS com pouca RAM): `GETFY_SKIP_FRONTEND_BUILD=1` antes do update.sh

**API PIX (`POST /api/v1/payments/pix`):** após atualizar, rode migrações no servidor (`php artisan migrate --force` ou o que o `update.sh` já executar). Se aparecer erro `api_application_id does not exist` em PostgreSQL, a migration `2026_05_21_120000_ensure_api_fields_on_orders_table` corrige — confira com `php artisan migrate:status`.

**Storage Cloudflare R2:** após **Transferir arquivos locais**, o sistema atualiza referências no banco. Se capas da área de membros quebrarem, rode a migração de novo ou atualize o código (as URLs passam a ser resolvidas na URL pública do R2). Confira `R2_PUBLIC_URL` / URL pública no painel de storage.

**Uploads (Member Builder, KYC, etc.):** PDFs no Member Builder até **50 MB** (imagens até 10 MB). `install.sh` / `update.sh` sincronizam `docker/php/uploads.ini` e `public/.user.ini` (PHP: 64M upload / 70M post). Docker monta o ini em runtime — após `update.sh`, reinicie o stack. Variáveis opcionais no `.env`: `MEMBER_BUILDER_UPLOAD_PDF_MAX_KB`, `GETFY_UPLOAD_MAX_FILESIZE`, `GETFY_POST_MAX_SIZE`. KYC: até 20 MB por arquivo.

**Ambiente:** `install.sh`, `update.sh` e `docker/up.sh` fixam `GETFY_APP_ENV=production` e `GETFY_APP_DEBUG=false` no stack Docker.

**Mercado Pago vs CajuPay:** com os dois conectados, quem cobra é o **primeiro da ordem** em **Plataforma → Financeiro → Adquirentes** (PIX / Cartão / Boleto). Coloque **Mercado Pago** no topo e salve. Se em **Plataforma → Usuários** o infoprodutor tiver ordem própria com Caju primeiro, ela prevalece sobre a global.

**Segurança do checkout (anti-flood):** rate limit automático (PIX: 3 tentativas / min por IP; checkout: 10 / min). Opcional: **Plataforma → Configurações → Segurança** — Cloudflare Turnstile (modo recomendado: **PIX e boleto**, widget **Managed** no painel Cloudflare). Em produção com muito tráfego, use `CACHE_STORE=redis` no `.env` para contadores de limite precisos. Variáveis opcionais: `CHECKOUT_MIN_SECONDS_BEFORE_PAY`, `CHECKOUT_DUPLICATE_PENDING_MINUTES`, `CHECKOUT_MAX_PENDING_PER_EMAIL`.

**CajuPay cartão (checkout HTTP local):** em `http://*.test` o SDK usa proxy same-origin (`/checkout/cajupay/sdk-api`) para evitar CORS; em HTTPS usa `api.cajupay.com.br` direto. Desative o proxy com `CAJUPAY_SDK_BROWSER_PROXY=false` no `.env`. Se `/api/checkout/track` retornar 500, rode `php artisan migrate` (migration `2026_05_21_140000_add_customer_contact_to_checkout_sessions_table` — colunas `cpf`/`phone`).

**Checklist de segurança (produção):**

- [ ] `APP_DEBUG=false`, `APP_ENV=production`, `APP_INSTALLED=true`
- [ ] `INSTALLER_ENABLED=false` (Docker: instalar só via `install.sh`)
- [ ] `INSTALLER_TOKEN` ou arquivo `.install-token` se o instalador web ficar habilitado
- [ ] `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, `SESSION_LIFETIME` adequado (ex. 1440)
- [ ] `TRUSTED_PROXIES` = IP(s) do load balancer
- [ ] `CRON_SECRET` forte e rotacionado
- [ ] Gateways Asaas, PushinPay, Spacepag e Woovi com **webhook_secret** (alerta em Plataforma → Financeiro)
- [ ] Turnstile ativo no checkout (PIX/boleto)
- [ ] API applications com `allowed_ips` quando possível
- [ ] `CREATE_FIRST_ADMIN=false` — primeiro admin via `php artisan getfy:create-dev-admin`
- [ ] Revisar pixels `custom_script` nos produtos (somente URLs allowlist)

**Testes de segurança:**

```bash
php artisan test --filter=CheckoutSecurityTest
php artisan test --filter=PurchasePixelAckTest
php artisan test --filter=GatewayInboundWebhookAuthTest
composer audit
npm audit
```

