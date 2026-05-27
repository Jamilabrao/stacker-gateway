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






cd /opt/getfy
set -a
. .docker/stack.env
set +a
docker compose --env-file .docker/stack.env up -d --force-recreate postgres app queue
docker exec -it getfy-app-1 php artisan config:clear
curl -I http://127.0.0.1