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






# Recuperar stack (só se o update.sh não puder ser usado agora):
# COMPOSE="$(sh docker/detect-compose-files.sh)"
# docker compose -f "$COMPOSE" --env-file .docker/stack.env up -d --remove-orphans