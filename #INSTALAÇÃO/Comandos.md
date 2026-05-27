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

Se aparecer `public/build/manifest.json: needs merge` ou `resolve your current index first` (servidor preso antes do fix no GitHub), rode uma vez:

```bash
cd /opt/getfy
git merge --abort 2>/dev/null || true
git rebase --abort 2>/dev/null || true
rm -rf public/build
git fetch --all --prune
git reset --hard origin/main
bash -c "$(curl -fsSL https://raw.githubusercontent.com/LeonardoIsrael0516/getfy-gateway/main/update.sh)"
```

Não use `docker compose up` só com `docker-compose.yml` se a instalação foi com Caddy — use sempre o `update.sh` (ele detecta o compose certo).

Qualquer modificação que você fizer no código, após finalizado, basta subir o repositorio para o github novamente, usando o GitHub Desktop ou pelo comando no terminal 
git add .
git commit -m update
git push

Resetar admin:
cd /opt/getfy   # ou seu GETFY_DIR
docker compose exec app php artisan getfy:create-dev-admin --email=admin@admin.com --password="12345678" --name="Admin"




cd /opt/getfy
set -a
. .docker/stack.env
set +a
unset GETFY_DB_CONNECTION GETFY_DB_HOST GETFY_DB_PORT GETFY_DB_DATABASE GETFY_DB_USERNAME GETFY_DB_PASSWORD
set -a
. .docker/stack.env
set +a
bash -c "$(curl -fsSL https://raw.githubusercontent.com/LeonardoIsrael0516/getfy-gateway/main/update.sh)"






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
bash -c "$(curl -fsSL https://raw.githubusercontent.com/LeonardoIsrael0516/getfy-gateway/main/update.sh)"
```

### Erros comuns

| Sintoma | Causa | O que fazer |
|--------|--------|-------------|
| `curl` → **connection reset** | Subiu só `docker-compose.yml` sem **Caddy** | `sh docker/recover-stack.sh` (usa o compose detectado) |
| Log app: **role does not exist** | `GETFY_DB_USERNAME` errado vs volume Postgres | Alinhar `.docker/stack.env` com backup/volume; não recriar volume |
| `export GETFY_DB_*` na shell root | Sobrescreve `--env-file` no próximo deploy | `unset GETFY_DB_*` antes de cada `compose`/`update.sh` |
| Git **needs merge** | `public/build` no índice | Bloco git do início deste ficheiro |

### Só diagnóstico (sem reiniciar)

```bash
cd /opt/getfy && sh docker/diagnose-stack.sh
```