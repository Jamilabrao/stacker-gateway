#!/bin/sh
set -e

cd /var/www/html

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache .docker
chmod -R 777 storage bootstrap/cache .docker 2>/dev/null || true

# Caddy (compose.caddy) importa este arquivo; vazio quebra o import — placeholder até docker-setup.
if [ ! -s .docker/Caddyfile.domains ] 2>/dev/null; then
  printf '%s\n' '# Blocos de domínio HTTPS são gravados pelo /docker-setup.' > .docker/Caddyfile.domains
fi

if [ ! -f .docker/app.key ]; then
  php -r 'echo "base64:".base64_encode(random_bytes(32));' > .docker/app.key
fi

if [ ! -f .env ]; then
  cp .env.example .env
fi

rm -f public/hot 2>/dev/null || true

# VAPID compartilhado (.docker/pwa_vapid.env): só no worker (GETFY_RUN_SETUP!=true).
# No container "app" (setup), injetar sempre no boot sobrescrevia um .env válido com chaves antigas/truncadas
# do volume e impedia php artisan pwa:vapid de rodar (linhas já preenchidas, porém inválidas).
if [ "${GETFY_RUN_SETUP:-true}" != "true" ]; then
  if [ -f .docker/pwa_vapid.env ]; then
    set -a
    # shellcheck disable=SC1091
    . .docker/pwa_vapid.env || true
    set +a
  fi

  # Injeta no .env local: o worker não roda pwa:vapid; precisa das mesmas chaves que o app gravou no volume.
  php -r '
$sharedFile = ".docker/pwa_vapid.env";
$envFile = ".env";
if (!is_file($sharedFile) || !is_file($envFile)) { exit(0); }
$shared = (string) file_get_contents($sharedFile);
$env = (string) file_get_contents($envFile);
$env = str_replace("\r\n", "\n", $env);
$shared = str_replace("\r\n", "\n", $shared);
foreach (["PWA_VAPID_PUBLIC","PWA_VAPID_PRIVATE"] as $k) {
  if (!preg_match("/^\\s*".$k."\\s*=\\s*(.+)\\s*$/mi", $shared, $m)) { continue; }
  $v = trim((string) ($m[1] ?? ""));
  $v = trim($v, " \\t\\n\\r\\0\\x0B\\\"\\x27`");
  if ($v === "") { continue; }
  $needsQuotes = (bool) preg_match("/\\s|#|\"|\\x27|`/", $v);
  $escaped = $needsQuotes ? ("\"" . str_replace("\"", "\\\"", $v) . "\"") : $v;
  $line = $k . "=" . $escaped;
  $pattern = "/^\\s*" . preg_quote($k, "/") . "\\s*=.*$/m";
  if (preg_match($pattern, $env)) {
    $env = (string) preg_replace($pattern, $line, $env);
  } else {
    $env = rtrim($env, "\r\n") . "\n" . $line . "\n";
  }
}
file_put_contents($envFile, $env);
';
fi

# Se houver cache de config, pode "prender" env antigo. Limpa de forma segura (sem falhar o boot).
rm -f bootstrap/cache/config.php 2>/dev/null || true

# vendor/ pode estar ausente no volume nomeado de dev (getfy_dev_vendor) ou em workers
# que sobem em paralelo com o app. Lock em .docker (bind mount compartilhado).
ensure_vendor() {
  if [ -f vendor/autoload.php ]; then
    return 0
  fi

  mkdir -p .docker
  waited=0
  while [ -f .docker/composer.installing ] && [ "$waited" -lt 180 ]; do
    sleep 1
    waited=$((waited + 1))
    if [ -f vendor/autoload.php ]; then
      return 0
    fi
  done

  if [ -f vendor/autoload.php ]; then
    return 0
  fi

  echo "vendor/ ausente — instalando dependências Composer..."
  printf '%s' "$$" > .docker/composer.installing
  # shellcheck disable=SC2064
  trap 'rm -f .docker/composer.installing' EXIT INT TERM
  git config --global --add safe.directory /var/www/html 2>/dev/null || true

  composer_ok=0
  if [ "${APP_ENV:-production}" = "local" ]; then
    if composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts; then
      composer_ok=1
    fi
  else
    if composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts; then
      composer_ok=1
    fi
  fi
  rm -f .docker/composer.installing
  trap - EXIT INT TERM

  if [ "$composer_ok" -ne 1 ]; then
    echo "composer install falhou. A imagem Docker deve usar PHP 8.3+ (rebuild: docker compose build --no-cache app)." >&2
    return 1
  fi

  php artisan package:discover --ansi 2>/dev/null || true
  return 0
}

ensure_vendor || exit 1

# Worker/scheduler: .env NÃO é volume compartilhado (só storage + .docker). Sem sync do
# APP_KEY, decrypt de GatewayCredential falha e reconciliação/webhooks inbound viram no-op
# silencioso — enquanto "Reconciliar agora" no container app continua funcionando.
# Não rodar o setup completo aqui (regravar .env do app reinicia php artisan serve).
if [ "${GETFY_RUN_SETUP:-true}" != "true" ]; then
  if [ -f .docker/app.key ]; then
    KEY="$(tr -d '\n\r' < .docker/app.key)"
    if [ -n "$KEY" ]; then
      export APP_KEY="$KEY"
      if [ -f .env ]; then
        if grep -qE '^APP_KEY=' .env 2>/dev/null; then
          sed -i "s|^APP_KEY=.*|APP_KEY=$KEY|" .env
        else
          echo "APP_KEY=$KEY" >> .env
        fi
      fi
    fi
  fi
  exec "$@"
fi

php -r '
$envFile = ".env";
$content = file_exists($envFile) ? (string) file_get_contents($envFile) : "";
$content = str_replace("\r\n", "\n", $content);
$setupDoneInEnv = (bool) preg_match("/^\\s*DOCKER_SETUP_DONE\\s*=\\s*[\"\\x27]?true[\"\\x27]?\\s*(?:#|$)/mi", $content);
$sharedAppUrl = trim((string) @file_get_contents(".docker/app.url"));
$sharedAppUrl = trim($sharedAppUrl, " \t\n\r\0\x0B\"`");
$sharedAppUrl = str_replace(["\r", "\n", "\t"], "", $sharedAppUrl);
$sharedAppUrl = str_replace(["`", "\"", "\x27"], "", $sharedAppUrl);
$setupDoneShared = is_file(".docker/setup.done") && $sharedAppUrl !== "" && preg_match("#^https?://#i", $sharedAppUrl);
$setupDone = $setupDoneInEnv || $setupDoneShared;

$existingAppUrl = null;
if (preg_match("/^\\s*APP_URL\\s*=\\s*(.+)\\s*$/mi", $content, $m)) {
    $existingAppUrl = trim((string) ($m[1] ?? ""), " \\t\\n\\r\\0\\x0B\\\"\\x27`");
}
$existingCronSecret = null;
if (preg_match("/^\\s*CRON_SECRET\\s*=\\s*(.*)\\s*$/mi", $content, $m)) {
    $existingCronSecret = trim((string) ($m[1] ?? ""), " \\t\\n\\r\\0\\x0B\\\"\\x27`");
}
$cronSecret = $existingCronSecret;
if (!is_string($cronSecret) || $cronSecret === "") {
    $cronSecret = rtrim(strtr(base64_encode(random_bytes(24)), "+/", "-_"), "=");
}
$webhookPublic = getenv("GETFY_WEBHOOK_PUBLIC_URL");
$webhookPublic = is_string($webhookPublic) ? trim($webhookPublic) : "";
$webhookPublic = $webhookPublic !== "" ? $webhookPublic : null;
$appUrl = $setupDone ? ($sharedAppUrl !== "" ? $sharedAppUrl : $existingAppUrl) : ((getenv("GETFY_APP_URL") ?: getenv("APP_URL")) ?: "http://localhost");
$parts = parse_url((string) $appUrl);
$scheme = strtolower((string) ($parts["scheme"] ?? ""));
$host = strtolower((string) ($parts["host"] ?? ""));
// Só "sobe" HTTP -> HTTPS automaticamente para hostnames (não-IP) **após** o docker-setup.
// Antes do setup, o acesso costuma ser http://IP:porta (sem SSL). Promover APP_URL para https://hostname
// e SESSION_SECURE_COOKIE=true quebra a sessão no POST (419 Page Expired).
if ($setupDone && $scheme === "http" && $host !== "" && $host !== "localhost" && $host !== "127.0.0.1" && $host !== "::1" && filter_var($host, FILTER_VALIDATE_IP) === false) {
    $appUrl = "https://" . $host;
}
$parts = parse_url((string) $appUrl);
$scheme = strtolower((string) ($parts["scheme"] ?? ""));
$sessionSecure = $setupDone && ($scheme === "https");
$vars = [
    "APP_NAME" => getenv("APP_NAME") ?: "Getfy",
    "APP_ENV" => getenv("APP_ENV") ?: "production",
    "APP_DEBUG" => getenv("APP_DEBUG") ?: "false",
    "APP_URL" => $appUrl ?: null,
    "APP_KEY" => getenv("APP_KEY") ?: (trim((string) @file_get_contents(".docker/app.key")) ?: ""),
    "APP_INSTALLED" => getenv("APP_INSTALLED") ?: "true",
    "DOCKER_SETUP_DONE" => $setupDone ? "true" : null,
    "APP_AUTO_MIGRATE" => getenv("APP_AUTO_MIGRATE") ?: "false",
    "CRON_SECRET" => $cronSecret ?: null,
    "GETFY_WEBHOOK_PUBLIC_URL" => $webhookPublic,
    "DB_CONNECTION" => getenv("DB_CONNECTION") ?: "pgsql",
    "DB_HOST" => getenv("DB_HOST") ?: "postgres",
    "DB_PORT" => getenv("DB_PORT") ?: "5432",
    "DB_DATABASE" => getenv("DB_DATABASE") ?: "getfy",
    "DB_USERNAME" => getenv("DB_USERNAME") ?: "getfy",
    "DB_PASSWORD" => getenv("DB_PASSWORD") ?: "getfy",
    "CACHE_STORE" => getenv("CACHE_STORE") ?: "redis",
    "QUEUE_CONNECTION" => getenv("QUEUE_CONNECTION") ?: "redis",
    "SESSION_DRIVER" => getenv("SESSION_DRIVER") ?: "file",
    "SESSION_SECURE_COOKIE" => $sessionSecure ? "true" : "false",
    "REDIS_CLIENT" => getenv("REDIS_CLIENT") ?: "predis",
    "REDIS_HOST" => getenv("REDIS_HOST") ?: "redis",
    "REDIS_PORT" => getenv("REDIS_PORT") ?: "6379",
    "REDIS_PASSWORD" => getenv("REDIS_PASSWORD") ?: "null",
    "TRUSTED_PROXIES" => getenv("TRUSTED_PROXIES") ?: ($setupDone ? "*" : null),
];
foreach ($vars as $key => $value) {
    if ($value === null) {
        continue;
    }
    $value = (string) $value;
    $needsQuotes = (bool) preg_match("/\\s|#|\"|\\x27|`/", $value);
    if ($value === "null") {
        $line = $key . "=null";
    } else {
        $escaped = $needsQuotes ? ("\"" . str_replace("\"", "\\\"", $value) . "\"") : $value;
        $line = $key . "=" . $escaped;
    }
    $pattern = "/^\\s*" . preg_quote($key, "/") . "\\s*=.*$/m";
    if (preg_match($pattern, $content)) {
        $content = (string) preg_replace($pattern, $line, $content);
    } else {
        $content = rtrim($content, "\r\n") . "\n" . $line . "\n";
    }
}
file_put_contents($envFile, $content);
'

if ! grep -qE '^APP_KEY=base64:' .env 2>/dev/null; then
  if [ ! -f .docker/app.key ]; then
    php -r 'echo "base64:".base64_encode(random_bytes(32));' > .docker/app.key
  fi
  KEY="$(tr -d '\n\r' < .docker/app.key)"
  if [ -n "$KEY" ]; then
    if grep -qE '^APP_KEY=' .env 2>/dev/null; then
      sed -i "s|^APP_KEY=.*|APP_KEY=$KEY|" .env
    else
      echo "APP_KEY=$KEY" >> .env
    fi
  fi
fi

DB_CONNECTION="${DB_CONNECTION:-pgsql}"
DB_DATABASE="${DB_DATABASE:-getfy}"
DB_USERNAME="${DB_USERNAME:-getfy}"
DB_PASSWORD="${DB_PASSWORD:-getfy}"

if [ "$DB_CONNECTION" = "pgsql" ]; then
  DB_HOST="${DB_HOST:-postgres}"
  DB_PORT="${DB_PORT:-5432}"
else
  DB_HOST="${DB_HOST:-mysql}"
  DB_PORT="${DB_PORT:-3306}"
fi

DB_OK=0
for i in $(seq 1 60); do
  if [ "$DB_CONNECTION" = "pgsql" ]; then
    DSN="pgsql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}"
  else
    DSN="mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}"
  fi
  if php -r "try { new PDO('${DSN}', '${DB_USERNAME}', '${DB_PASSWORD}', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); } catch (Throwable \$e) { exit(1); }" >/dev/null 2>&1; then
    DB_OK=1
    break
  fi
  sleep 1
done

if [ "$DB_OK" -ne 1 ]; then
  echo "Banco indisponível (DB_CONNECTION=${DB_CONNECTION}). Verifique DB_HOST/DB_PORT e o serviço do banco no compose."
  exit 1
fi

# Dependências precisam existir em *todo* container da mesma imagem (app, queue, etc.).
# O serviço "queue" usa GETFY_RUN_SETUP=false: sem isto, só o "app" rodaria composer install
# na camada efêmera dele e o worker reiniciaria em loop (vendor ausente).
GETFY_VENDOR_JUST_INSTALLED=0
if [ ! -f vendor/autoload.php ]; then
  git config --global --add safe.directory /var/www/html 2>/dev/null || true
  if ! composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts; then
    echo "composer install falhou. A imagem Docker deve usar PHP 8.3+ (rebuild: docker compose build --no-cache app)." >&2
    exit 1
  fi
  GETFY_VENDOR_JUST_INSTALLED=1
fi

if [ "${GETFY_RUN_SETUP:-true}" = "true" ]; then
  php artisan package:discover --ansi
  php artisan migrate --force
  if ! php -r '
require "vendor/autoload.php";
$valid = false;
$c = (string) @file_get_contents(".env");
$c = str_replace("\r\n", "\n", $c);
$val = static function (string $c, string $k): ?string {
  if (!preg_match("/^\\s*" . preg_quote($k, "/") . "\\s*=\\s*(.+)\\s*$/mi", $c, $m)) { return null; }
  $v = trim((string) ($m[1] ?? ""), " \\t\\n\\r\\0\\x0B\\\"\\x27`");
  return $v === "" ? null : $v;
};
$pub = $val($c, "PWA_VAPID_PUBLIC");
$priv = $val($c, "PWA_VAPID_PRIVATE");
if (\App\Support\VapidEnvKeys::normalizedPairLooksValid($pub, $priv)) {
  $valid = true;
}
if (! $valid) {
  try {
    $boot = require "bootstrap/app.php";
    $boot->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $row = \App\Models\BrandingSetting::query()->whereNull("tenant_id")->first();
    $data = is_array($row?->data) ? $row->data : [];
    $merged = \App\Support\PanelPushSettings::mergeWithEnvFallback($data);
    if (\App\Support\VapidEnvKeys::normalizedPairLooksValid($merged["pwa_vapid_public"] ?? null, $merged["pwa_vapid_private"] ?? null)) {
      $valid = true;
    }
    if (($merged["push_provider"] ?? "vapid") === "fcm"
        && ! empty($merged["firebase_service_account"])
        && ! empty($merged["firebase_api_key"])
        && ! empty($merged["firebase_project_id"])) {
      $valid = true;
    }
  } catch (\Throwable $e) {
    // schema ainda não pronto
  }
}
exit($valid ? 0 : 1);
' >/dev/null 2>&1; then
    php artisan pwa:ensure-vapid || true
  fi
elif [ "$GETFY_VENDOR_JUST_INSTALLED" = "1" ]; then
  php artisan package:discover --ansi
fi

if [ ! -L public/storage ] && [ ! -d public/storage ]; then
  php artisan storage:link 2>/dev/null || true
fi

# Persiste VAPID em arquivo compartilhado no volume .docker para que "queue" e "app" usem as mesmas chaves.
php -r '
$envFile = ".env";
$sharedFile = ".docker/pwa_vapid.env";
if (!is_file($envFile)) { exit(0); }
$env = (string) file_get_contents($envFile);
$env = str_replace("\r\n", "\n", $env);
$out = "";
foreach (["PWA_VAPID_PUBLIC","PWA_VAPID_PRIVATE"] as $k) {
  if (!preg_match("/^\\s*".$k."\\s*=\\s*(.+)\\s*$/mi", $env, $m)) { continue; }
  $v = trim((string) ($m[1] ?? ""));
  $v = trim($v, " \\t\\n\\r\\0\\x0B\\\"\\x27`");
  if ($v === "") { continue; }
  $out .= $k . "=\"" . str_replace("\"", "\\\"", $v) . "\"\n";
}
if ($out !== "") {
  @mkdir(dirname($sharedFile), 0777, true);
  file_put_contents($sharedFile, $out);
}
';

exec "$@"
