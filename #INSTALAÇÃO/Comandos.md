Comando para instalação.
Execute no Terminal da sua VPS:

GETFY_LEGACY_GIT_UPDATE=1 bash -c "$(curl -fsSL https://raw.githubusercontent.com/stacker-builders/stacker-gateway/main/install.sh)"

Importante: o repositório pode permanecer **privado** quando o update remoto via Stacker estiver configurado (`STACKER_AGENT_TOKEN` + releases na API).

-------------

## Updates — remoto (padrão) vs legado SSH

| Modo | Quando usar | O que faz |
|------|-------------|-----------|
| **Remoto Stacker** | Produção com `STACKER_AGENT_TOKEN` | Admin ou portal → **Atualizar** → agente baixa zip, rebuilda `app`, migrate |
| **Legado Git** | Emergência / hotfix manual | `GETFY_LEGACY_GIT_UPDATE=1 bash update.sh` — git pull + rebuild completo |

**Fluxo remoto (sem SSH):**

1. Push no GitHub (`stacker-builders/stacker-gateway`) ou botão **Gerar release** no admin Stacker.
2. CI publica zip em **Gateway → Releases** (ou upload manual).
3. **Gateway → Instalações → Atualizar** (admin) ou **Portal → Updates** (cliente).
4. Em ~30s o agente aplica: extrai zip, `docker compose build app`, migrate.

O `bash update.sh` **com token** (sem `GETFY_LEGACY_GIT_UPDATE=1`) **não** substitui código PHP — só reinicia containers e garante o agente.

-------------

Comando para Atualização local (reinício / agente):

```bash
cd /opt/getfy
bash -c "$(curl -fsSL https://raw.githubusercontent.com/stacker-builders/stacker-gateway/main/update.sh)"
```

**Com `STACKER_AGENT_TOKEN` configurado** (modo Stacker): só reinicia containers e rebuilda o **agente** — não faz `git pull` nem rebuild do `app`. Updates de código PHP vêm pelo portal Stacker (release remota).

**Para puxar código do GitHub** (scripts, middleware de licença, etc.):

```bash
cd /opt/getfy
GETFY_LEGACY_GIT_UPDATE=1 bash -c "$(curl -fsSL https://raw.githubusercontent.com/stacker-builders/stacker-gateway/main/update.sh)"
```

Se aparecer `vendor/ ausente` no build: rode o update legado acima (ele executa `install-composer-deps.sh` antes do rebuild).

Se aparecer `public/build/manifest.json: needs merge` ou `resolve your current index first` (servidor preso antes do fix no GitHub), rode uma vez:

```bash
cd /opt/getfy
git merge --abort 2>/dev/null || true
git rebase --abort 2>/dev/null || true
rm -rf public/build
git fetch --all --prune
git reset --hard origin/main
bash -c "$(curl -fsSL https://raw.githubusercontent.com/stacker-builders/stacker-gateway/main/update.sh)"
```

Não use `docker compose up` só com `docker-compose.yml` se a instalação foi com Caddy — use sempre o `update.sh` (ele detecta o compose certo).

### Redis e workers da API (obrigatório em produção)

A API PIX async, webhooks de parceiros (`PUT /api/v1/webhook`) e saques usam filas Redis:

| Fila | Uso |
|------|-----|
| `payments` | Gerar cobrança PIX após `POST /api/v1/payments/pix` |
| `webhooks-outbound` | Entregar webhooks para URL do parceiro |
| `webhooks-inbound` | Processar webhooks dos gateways (async) |
| `webhooks` | Webhooks internos (painel / integrações) |
| `payouts` | Saques async |

**Produção:** use `install.sh` (workers dedicados + Redis) ou `install-caddy.sh` (Redis + container `queue` consolidado).  
**Não use** `install-no-redis.sh` com API em produção (perfil legado, fila no PostgreSQL).

Após `install.sh` ou `update.sh`, a verificação automática roda `docker/verify-workers.sh`. Manualmente:

```bash
cd /opt/getfy
sh docker/verify-workers.sh
COMPOSE="$(sh docker/detect-compose-files.sh)"
docker compose -f "$COMPOSE" --env-file .docker/stack.env ps
docker compose -f "$COMPOSE" --env-file .docker/stack.env exec redis redis-cli LLEN queues:payments
```

### Mercado Pago PIX — diagnóstico e webhook

Se o PIX aparece **aprovado no Mercado Pago** mas o pedido fica **pendente** no sistema:

```bash
cd /opt/getfy
COMPOSE="$(sh docker/detect-compose-files.sh)"

# Diagnóstico rápido (URL, credencial, fila, pedidos pendentes)
docker compose -f "$COMPOSE" --env-file .docker/stack.env exec app \
  php artisan payments:diagnose-mercadopago

# Reconciliar manualmente pedidos MP pendentes
docker compose -f "$COMPOSE" --env-file .docker/stack.env exec app \
  php artisan payments:reconcile-mercadopago --limit=50 --min-age-minutes=0

# Fila de webhooks (deve estar em 0 com worker ativo)
docker compose -f "$COMPOSE" --env-file .docker/stack.env exec redis \
  redis-cli LLEN queues:webhooks-inbound
```

**Configuração obrigatória em `.docker/stack.env`:**

```bash
GETFY_APP_URL=https://seu-dominio.com.br
GETFY_WEBHOOK_PUBLIC_URL=https://seu-dominio.com.br   # se diferente do APP_URL
```

No painel **Mercado Pago → Suas integrações → Webhooks → Produção**, cadastre:

`https://seu-dominio.com.br/webhooks/gateways/mercadopago`

Evento: **Payments** (`payment`). A mesma URL aparece em **Configurações → Gateways → Mercado Pago** no painel da plataforma.

Após alterar `stack.env`:

```bash
docker compose -f "$COMPOSE" --env-file .docker/stack.env restart app scheduler worker-webhooks-in
```

### Site fora (502) após update pelo agente Stacker (perfil Caddy)

O **502 Bad Gateway** no Caddy quase sempre significa que o proxy não alcança o container `app` (app ainda reiniciando, compose errado ou Caddy apontando para instância antiga).

**Recuperação imediata na VPS:**

```bash
cd /opt/getfy
COMPOSE="$(sh docker/detect-compose-files.sh)"
PROJECT="$(grep -E '^GETFY_COMPOSE_PROJECT_NAME=' .docker/stack.env | tail -1 | cut -d= -f2-)"
PROJECT="${PROJECT:-getfy}"

# Garantir perfil Caddy
grep -q '^GETFY_COMPOSE_FILES=' .docker/stack.env || echo 'GETFY_COMPOSE_FILES=docker-compose.caddy.yml' >> .docker/stack.env
echo caddy > .docker/compose-profile

docker compose -p "$PROJECT" -f "$COMPOSE" --env-file .docker/stack.env --env-file .env up -d --force-recreate --no-deps app queue
# Aguardar app (~1–3 min na primeira subida após update)
for i in $(seq 1 60); do
  docker compose -p "$PROJECT" -f "$COMPOSE" --env-file .docker/stack.env exec -T app \
    php -r "exit(@file_get_contents('http://127.0.0.1/up')===false?1:0);" 2>/dev/null && break
  sleep 3
done
docker compose -p "$PROJECT" -f "$COMPOSE" --env-file .docker/stack.env --env-file .env up -d --force-recreate --no-deps caddy

curl -sI --max-time 8 http://127.0.0.1/ | head -3
```

Ou use o script completo: `sh docker/recover-stack.sh`

**Diagnóstico:** `sh docker/diagnose-stack.sh` (logs app + caddy, perfil compose detectado).

**Prevenção (já no código após deploy):** o `stacker-apply-update.sh` persiste `GETFY_COMPOSE_FILES`, recria `app`/`queue`, espera `/up` e só então recria o `caddy`.

Qualquer modificação que você fizer no código, após finalizado, basta subir o repositorio para o github novamente, usando o GitHub Desktop ou pelo comando no terminal 
git add .
git commit -m update
git push

Resetar admin:
cd /opt/getfy   # ou seu GETFY_DIR
docker compose exec app php artisan getfy:create-dev-admin --email=admin@admin.com --password="12345678" --name="Admin"

### Saldo Mercado Pago (URL oculta)

URL: `/plataforma/ops/mercadopago-saldo` (login em `/plataforma/login` como admin). **Sem** essa flag a rota devolve **404** de propósito.

Na VPS, edite o arquivo persistente do Docker (recomendado):

```bash
cd /opt/getfy
echo "GETFY_MP_BALANCE_TOOL_ENABLED=true" >> .docker/stack.env
# ou, se já existir a linha:
grep -q '^GETFY_MP_BALANCE_TOOL_ENABLED=' .docker/stack.env \
  && sed -i 's/^GETFY_MP_BALANCE_TOOL_ENABLED=.*/GETFY_MP_BALANCE_TOOL_ENABLED=true/' .docker/stack.env \
  || echo "GETFY_MP_BALANCE_TOOL_ENABLED=true" >> .docker/stack.env

COMPOSE=$(sh docker/detect-compose-files.sh)
docker compose -f $COMPOSE --env-file .docker/stack.env up -d app
docker compose -f $COMPOSE --env-file .docker/stack.env exec app php artisan config:clear
```

Alternativa (só dentro do container, até o próximo rebuild):

```bash
docker compose exec app sh -c 'echo "GETFY_MP_BALANCE_TOOL_ENABLED=true" >> .env'
docker compose exec app php artisan config:clear
```

Laragon/local: em `.env` na raiz do projeto, `GETFY_MP_BALANCE_TOOL_ENABLED=true` e `php artisan config:clear`.


cd /opt/getfy
set -a
. .docker/stack.env
set +a
unset GETFY_DB_CONNECTION GETFY_DB_HOST GETFY_DB_PORT GETFY_DB_DATABASE GETFY_DB_USERNAME GETFY_DB_PASSWORD
set -a
. .docker/stack.env
set +a
bash -c "$(curl -fsSL https://raw.githubusercontent.com/stacker-builders/stacker-gateway/main/update.sh)"






-------------

## Site fora — Cloudflare 522 / Connection timed out

O **522** significa: a Cloudflare não consegue falar com o teu VPS na porta **80/443**. Quase sempre o **Docker/Caddy/app** não está a responder (não é “só DNS”).

### Recuperação rápida (na VPS, como root)

```bash
cd /opt/getfy

# 1) Git preso (se o update falhou antes)
git merge --abort 2>/dev/null || true
git rebase --abort 2>/dev/null || true
rm -rf public/build
git fetch --all --prune
git reset --hard origin/main

# 2) Script de diagnóstico + subir stack certo
chmod +x docker/recover-stack.sh docker/detect-compose-files.sh
sh docker/recover-stack.sh
```

Se o passo 2 mostrar **`role does not exist`** ou **`Banco indisponível`**: as credenciais em `.docker/stack.env` não coincidem com o **volume antigo** do Postgres. **Não apagues** `postgres_data`.

```bash
# O volume getfy_env tem o user/senha da 1ª instalação; a raiz pode estar errada.
docker run --rm -v getfy_getfy_env:/v alpine cat /v/stack.env > .docker/stack.env

# Confirmar Postgres (troque o user se o grep mostrar outro)
docker exec getfy-postgres-1 psql -U "$(grep '^GETFY_DB_USERNAME=' .docker/stack.env | cut -d= -f2)" -d getfy -c 'SELECT 1'

# .env na raiz (Compose lê isto também)
grep '^GETFY_DB_' .docker/stack.env > .env

unset GETFY_DB_CONNECTION GETFY_DB_HOST GETFY_DB_PORT GETFY_DB_DATABASE GETFY_DB_USERNAME GETFY_DB_PASSWORD
set -a && . .docker/stack.env && set +a
COMPOSE="$(sh docker/detect-compose-files.sh)"
docker compose -f "$COMPOSE" --env-file .docker/stack.env up -d --force-recreate app queue
sleep 12
docker compose -f "$COMPOSE" --env-file .docker/stack.env logs app --tail 25
curl -sI --max-time 8 http://127.0.0.1/ | head -5
```

No teu caso (srv1606943): raiz tinha `getfy_0xvmkpqq` mas o volume tem `getfy_ymlm2rn2` — usar sempre o ficheiro do volume.
```

### Atualizar código depois que o HTTP local voltar

```bash
cd /opt/getfy
unset GETFY_DB_CONNECTION GETFY_DB_HOST GETFY_DB_PORT GETFY_DB_DATABASE GETFY_DB_USERNAME GETFY_DB_PASSWORD
set -a && . .docker/stack.env && set +a
bash -c "$(curl -fsSL https://raw.githubusercontent.com/stacker-builders/stacker-gateway/main/update.sh)"
```

### Erros comuns

| Sintoma | Causa | O que fazer |
|--------|--------|-------------|
| `curl` → **connection reset** | Subiu só `docker-compose.yml` sem **Caddy** | `sh docker/recover-stack.sh` (usa o compose detectado) |
| Log app: **role does not exist** | `GETFY_DB_USERNAME` errado vs volume Postgres | Alinhar `.docker/stack.env` com backup/volume; não recriar volume |
| `export GETFY_DB_*` na shell root | Sobrescreve `--env-file` no próximo deploy | `unset GETFY_DB_*` antes de cada `compose`/`update.sh` |
| Git **needs merge** | `public/build` no índice | Bloco git do início deste ficheiro |
| **composer install** no Docker build: `curl error 28` / `api.github.com` timeout | Rede do BuildKit no VPS não alcança GitHub a tempo | Na VPS: `cd /opt/getfy && sh docker/install-composer-deps.sh` e depois `update.sh` (versão recente instala vendor no host antes do build) |
| **composer install**: `ext-gd * -> it is missing` (imagem `composer:2`) | Imagem oficial do Composer não traz GD; `setasign/fpdf` exige a extensão | Atualize o repo (`update.sh` recente usa `docker/composer.Dockerfile`). Ou na VPS: `cd /opt/getfy && sh docker/install-composer-deps.sh` |
| Webhook **Pedido pago** não dispara (teste manual funciona) | Jobs presos em `webhooks` / worker parado | `sh docker/verify-workers.sh`; `redis-cli LLEN queues:webhooks` |
| **API PIX** fica em `pending` | Fila `payments` sem worker | `redis-cli LLEN queues:payments`; logs `worker-payments` ou `queue` |
| **Webhook parceiro** não chega | Fila `webhooks-outbound` sem worker | `redis-cli LLEN queues:webhooks-outbound` |
| Instalação **sem Redis** + API | Perfil `no-redis` legado | Migrar para `install.sh` ou `install-caddy.sh` |

#### Webhook de saída (Pedido pago)

Se o botão **Testar** em Integrações funciona mas o evento real após pagamento não chega na URL:

```bash
cd /opt/getfy
COMPOSE="$(sh docker/detect-compose-files.sh)"
# Jobs presos nas filas da API?
docker compose -f "$COMPOSE" --env-file .docker/stack.env exec redis redis-cli LLEN queues:webhooks
docker compose -f "$COMPOSE" --env-file .docker/stack.env exec redis redis-cli LLEN queues:webhooks-outbound
docker compose -f "$COMPOSE" --env-file .docker/stack.env exec redis redis-cli LLEN queues:payments
# Logs do worker (perfil standard: worker-webhooks-out; Caddy: queue)
docker compose -f "$COMPOSE" --env-file .docker/stack.env logs worker-webhooks-out queue --tail 30
sh docker/verify-workers.sh
```

Na UI: **Integrações > Webhooks > Ver logs** — após um pagamento, deve aparecer `pedido_pago` com `success: true`.

#### Push PWA (notificações do painel)

Após `update.sh`, o script roda `pwa:ensure-vapid` automaticamente. Para verificar manualmente:

```bash
cd /opt/getfy
docker compose exec app php artisan pwa:ensure-vapid
docker compose exec app php artisan config:clear
docker compose exec app php artisan tinker --execute="echo App\Support\PanelPushSettings::isPushEnabled() ? 'ok' : 'falta VAPID';"
```

Checklist UI:
- **Plataforma → App**: VAPID válido / push habilitado
- **Painel → sidebar notificações → Ativar**: deve permanecer **Ativo** (não voltar para inativo)
- Eventos: PIX gerado, venda aprovada (PIX/cartão/boleto), saque concluído

Se o botão voltar para "Ativar" após update, reative uma vez; inscrições antigas com chave VAPID desatualizada exigem reativação (`push_needs_resubscribe`).

#### Update falha no composer (só em um VPS)

Se o frontend compila mas o build Docker quebra em `composer install` com timeout no GitHub:

```bash
cd /opt/getfy
git fetch --all --prune && git reset --hard origin/main
sh docker/install-composer-deps.sh
# Se ainda falhar, aumente timeout:
GETFY_COMPOSER_PROCESS_TIMEOUT=1800 GETFY_COMPOSER_HTTP_TIMEOUT=600 sh docker/install-composer-deps.sh
unset GETFY_DB_CONNECTION GETFY_DB_HOST GETFY_DB_PORT GETFY_DB_DATABASE GETFY_DB_USERNAME GETFY_DB_PASSWORD
set -a && . .docker/stack.env && set +a
bash -c "$(curl -fsSL https://raw.githubusercontent.com/stacker-builders/stacker-gateway/main/update.sh)"
```

### Limpeza de imagens Docker (espaço em disco)

Cada `update.sh` com rebuild deixa imagens antigas sem tag (`<none>`). O script remove automaticamente **só órfãs** — volumes e dados do Postgres/storage **não são tocados**.

| O quê | Comando / comportamento |
|-------|-------------------------|
| Automático no update | `bash update.sh` (final do script) |
| Manual | `cd /opt/getfy && sh docker/prune-docker-images.sh` |
| Desativar no update | `GETFY_SKIP_DOCKER_PRUNE=1 bash update.sh` |
| Mais agressivo (imagens não usadas) | `GETFY_DOCKER_PRUNE_UNUSED=1 bash update.sh` |

O padrão roda `docker image prune -f` (dangling) + cache de build com mais de 14 dias.

**Nunca em produção:** `docker volume prune`, `docker system prune --volumes` ou `docker compose down -v` — apagam `postgres_data`, `getfy_env`, `getfy_storage`.

Conferir espaço sem update:

```bash
docker system df
docker image prune -f
```

### Só diagnóstico (sem reiniciar)

```bash
cd /opt/getfy && sh docker/diagnose-stack.sh
```

### Stacker Agent (métricas + licença no painel)

No **install** e no **update**, se `STACKER_AGENT_TOKEN` estiver vazio, o script pede o token (painel Stacker → Gateway → Instalações). Enter pula — configure depois em `.env`.

Sem TTY (`curl | bash`): exporte antes `STACKER_AGENT_TOKEN=...` ou edite `.env` manualmente.

Desativar prompt: `GETFY_SKIP_STACKER_TOKEN_PROMPT=1`

#### Corrigir agente offline (comando automático)

Na VPS, na pasta da instalação:

```bash
cd /opt/getfy && sh docker/fix-stacker-agent.sh
```

O script:
1. Sincroniza `STACKER_AGENT_TOKEN` entre `.env` e `.docker/stack.env`
2. Recria o container `stacker-agent`
3. Aguarda heartbeat e mostra logs

Só diagnóstico (sem reiniciar):

```bash
cd /opt/getfy && sh docker/fix-stacker-agent.sh --check-only
```

Rebuild da imagem do agente (após `git pull` com mudanças em `agent/`):

```bash
cd /opt/getfy && sh docker/fix-stacker-agent.sh --rebuild
```

Atalho equivalente: `sh docker/restart-stacker-agent.sh`

**Códigos de saída:** `0` = OK · `1` = token ausente · `2` = token não chegou ao container · `3` = subiu mas heartbeat não confirmado (rede/token inválido)

Se `getfy-stacker-agent-1` estiver em **Restarting** ou o painel mostrar "Aguardando agente":

```bash
cd /opt/getfy
grep -E '^STACKER_|^APP_URL=' .env
COMPOSE=$(sh docker/detect-compose-files.sh)
docker compose -f "$COMPOSE" --env-file .docker/stack.env logs stacker-agent --tail 50
```

**Token:** use o valor gerado no painel Stacker (Gateway → Instalações → Nova instalação). Não use token aleatório local.

No `/opt/getfy/.env`:

```env
STACKER_API_URL=https://api.stacker.builders
STACKER_AGENT_TOKEN=<token do painel Stacker>
APP_URL=https://app.kursa.com.br
```

Após `git pull` (fix compose que sobrescrevia o token), recrie o agente:

```bash
cd /opt/getfy
git pull
COMPOSE=$(sh docker/detect-compose-files.sh)
docker compose -f "$COMPOSE" --env-file .docker/stack.env up -d --force-recreate stacker-agent
docker compose -f "$COMPOSE" --env-file .docker/stack.env logs stacker-agent --tail 20
```

Deve aparecer heartbeat a cada ~30s; o painel atualiza CPU/RAM em até 1 minuto.
