#!/usr/bin/env bash
# Limpeza segura de logs Laravel + harden LOG_* no .env do host.
#
# Escopo APENAS:
#   - .env (LOG_CHANNEL / LOG_STACK / LOG_LEVEL / LOG_DAILY_DAYS)
#   - storage/logs/*.log (via artisan, volume Docker ou path host)
#   - jobs falhos (queue:prune-failed via artisan, se app up)
#
# NUNCA mexe em:
#   - Postgres / Redis / volumes de dados (postgres_data etc.)
#   - storage/app, .docker/stack.env, banco de dados
#   - docker compose down -v / volume rm
#
# Chamado por update.sh e docker/stacker-apply-update.sh.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

DAYS="${LOG_DAILY_DAYS:-7}"
MAX_MB="${LOG_MAX_MB:-50}"
MAX_TOTAL_MB="${LOG_MAX_TOTAL_MB:-200}"
MAX_BYTES=$((MAX_MB * 1024 * 1024))

echo "=== Limpeza segura storage/logs (sem tocar DB/volumes de dados) ==="

# --- Hardening LOG_* no .env do host (single+debug → daily+warning) ---
harden_host_log_env() {
  local envf="$ROOT_DIR/.env"
  [ -f "$envf" ] || return 0

  set_kv() {
    local key="$1" val="$2"
    if grep -Eq "^[[:space:]]*${key}[[:space:]]*=" "$envf" 2>/dev/null; then
      local tmp
      tmp="$(mktemp)"
      awk -v k="$key" -v v="$val" '
        $0 ~ "^[[:space:]]*" k "[[:space:]]*=" { print k "=" v; next }
        { print }
      ' "$envf" > "$tmp"
      mv "$tmp" "$envf"
    else
      echo "${key}=${val}" >> "$envf"
    fi
  }

  local stack level
  stack="$(grep -E '^[[:space:]]*LOG_STACK[[:space:]]*=' "$envf" 2>/dev/null | tail -1 | cut -d= -f2- | sed 's/[\"'\'']//g;s/\r//g;s/^[[:space:]]*//;s/[[:space:]]*$//' || true)"
  level="$(grep -E '^[[:space:]]*LOG_LEVEL[[:space:]]*=' "$envf" 2>/dev/null | tail -1 | cut -d= -f2- | sed 's/[\"'\'']//g;s/\r//g;s/^[[:space:]]*//;s/[[:space:]]*$//' || true)"

  if [ -z "$stack" ] || [ "$stack" = "single" ]; then
    set_kv LOG_STACK daily
    echo "LOG_STACK=daily (antes: ${stack:-ausente})"
  fi
  if [ -z "$level" ] || [ "$level" = "debug" ]; then
    set_kv LOG_LEVEL warning
    echo "LOG_LEVEL=warning (antes: ${level:-ausente})"
  fi
  if ! grep -Eq '^[[:space:]]*LOG_DAILY_DAYS[[:space:]]*=' "$envf" 2>/dev/null; then
    set_kv LOG_DAILY_DAYS "$DAYS"
  fi
  if ! grep -Eq '^[[:space:]]*LOG_CHANNEL[[:space:]]*=' "$envf" 2>/dev/null; then
    set_kv LOG_CHANNEL stack
  fi
}

echo "Hardening LOG_* no .env do host..."
harden_host_log_env || true

resolve_project_name() {
  local project_name="${GETFY_COMPOSE_PROJECT_NAME:-}"
  if [ -z "$project_name" ] && [ -f .docker/stack.env ]; then
    project_name="$(grep -E '^[[:space:]]*GETFY_COMPOSE_PROJECT_NAME[[:space:]]*=' .docker/stack.env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '\"'\''\r' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' || true)"
  fi
  printf '%s' "${project_name:-getfy}"
}

build_compose_cmd() {
  local compose_files="" compose_args="" f env_file project_name
  if [ -f docker/detect-compose-files.sh ]; then
    compose_files="$(sh docker/detect-compose-files.sh 2>/dev/null || true)"
  fi
  compose_files="${compose_files:-docker-compose.yml}"

  for f in $compose_files; do
    if [ -n "$f" ] && [ -f "$f" ]; then
      compose_args="$compose_args -f $f"
    fi
  done

  env_file=""
  if [ -f .docker/stack.env ]; then
    env_file=".docker/stack.env"
  elif [ -f .env ]; then
    env_file=".env"
  fi

  project_name="$(resolve_project_name)"

  # shellcheck disable=SC2086
  COMPOSE_BASE=(docker compose -p "$project_name" $compose_args)
  if [ -n "$env_file" ]; then
    COMPOSE_BASE+=(--env-file "$env_file")
  fi
  export COMPOSE_PROJECT_NAME="$project_name"
}

# --- Prune filesystem inside a mounted logs dir (host path or volume mount) ---
prune_logs_dir() {
  local logs_dir="$1"
  if [ ! -d "$logs_dir" ]; then
    echo "Diretório inexistente: $logs_dir — skip."
    return 0
  fi

  echo "Pruning filesystem: $logs_dir (idade + tamanho)..."
  find "$logs_dir" -maxdepth 1 -type f -name '*.log' -mtime "+$DAYS" -print -delete 2>/dev/null || true

  local f size keep_bytes tmp
  keep_bytes=$((2 * 1024 * 1024))
  if [ "$keep_bytes" -gt "$MAX_BYTES" ]; then
    keep_bytes="$MAX_BYTES"
  fi

  for f in "$logs_dir"/*.log; do
    [ -f "$f" ] || continue
    size="$(wc -c < "$f" 2>/dev/null | tr -d ' ' || echo 0)"
    if [ "${size:-0}" -gt "$MAX_BYTES" ] 2>/dev/null; then
      echo "Truncando: $(basename "$f") (${size} bytes → ~${keep_bytes})"
      tmp="$(mktemp)"
      {
        echo "…(truncated by cleanup-storage-logs.sh at $(date -u +%Y-%m-%dT%H:%M:%SZ))"
        tail -c "$keep_bytes" "$f" 2>/dev/null || true
      } > "$tmp"
      mv "$tmp" "$f" || rm -f "$tmp"
    fi
  done

  local max_total=$((MAX_TOTAL_MB * 1024 * 1024))
  local total
  total=0
  for f in "$logs_dir"/*.log; do
    [ -f "$f" ] || continue
    size="$(wc -c < "$f" 2>/dev/null | tr -d ' ' || echo 0)"
    total=$((total + ${size:-0}))
  done

  if [ "$total" -gt "$max_total" ] 2>/dev/null; then
    echo "Teto total ${MAX_TOTAL_MB}MB excedido (~${total} bytes) — removendo mais antigos..."
    # shellcheck disable=SC2012
    ls -1tr "$logs_dir"/*.log 2>/dev/null | while read -r oldf; do
      [ -f "$oldf" ] || continue
      total=0
      for f in "$logs_dir"/*.log; do
        [ -f "$f" ] || continue
        size="$(wc -c < "$f" 2>/dev/null | tr -d ' ' || echo 0)"
        total=$((total + ${size:-0}))
      done
      if [ "$total" -le "$max_total" ] 2>/dev/null; then
        break
      fi
      echo "Removido (teto): $(basename "$oldf")"
      rm -f "$oldf"
    done
  fi
}

# --- Preferir artisan no container app ---
run_artisan_prune() {
  if ! command -v docker >/dev/null 2>&1; then
    return 1
  fi

  build_compose_cmd

  local app_running=0
  if "${COMPOSE_BASE[@]}" ps --status running --services 2>/dev/null | grep -qx 'app'; then
    app_running=1
  elif "${COMPOSE_BASE[@]}" ps 2>/dev/null | grep -E '(^|[[:space:]])app([[:space:]]|$)' | grep -qiE 'Up|running'; then
    app_running=1
  fi

  if [ "$app_running" -ne 1 ]; then
    return 1
  fi

  echo "Container app up — logs:prune via artisan..."
  if ! "${COMPOSE_BASE[@]}" exec -T app php artisan logs:prune \
      --days="$DAYS" \
      --max-mb="$MAX_MB" \
      --max-total-mb="$MAX_TOTAL_MB"; then
    return 1
  fi

  "${COMPOSE_BASE[@]}" exec -T app php artisan queue:prune-failed --hours=168 || true
  "${COMPOSE_BASE[@]}" exec -T app php artisan config:clear || true
  return 0
}

# storage é named volume getfy_storage (projeto_volume). Host ./storage não tem os logs reais.
run_volume_prune() {
  if ! command -v docker >/dev/null 2>&1; then
    return 1
  fi

  local project vol
  project="$(resolve_project_name)"
  vol="${project}_getfy_storage"

  if ! docker volume inspect "$vol" >/dev/null 2>&1; then
    # Tenta descobrir volume de storage do projeto.
    vol="$(docker volume ls --format '{{.Name}}' 2>/dev/null | grep -E "^${project}_.*storage" | head -1 || true)"
  fi
  if [ -z "${vol:-}" ]; then
    if ! docker volume inspect getfy_getfy_storage >/dev/null 2>&1; then
      return 1
    fi
    vol="getfy_getfy_storage"
  fi

  echo "Volume storage: $vol — prune via one-shot (só logs/*.log)..."
  # Preferir imagem app já local; fallback alpine se rebuild incompleto.
  local runner="getfy_app:latest"
  if ! docker image inspect "$runner" >/dev/null 2>&1; then
    runner="alpine:3.20"
  fi

  docker run --rm \
    -v "${vol}:/var/www/html/storage" \
    -e DAYS="$DAYS" \
    -e MAX_MB="$MAX_MB" \
    -e MAX_TOTAL_MB="$MAX_TOTAL_MB" \
    --entrypoint sh \
    "$runner" \
    -c '
      set -eu
      LOGS=/var/www/html/storage/logs
      mkdir -p "$LOGS"
      DAYS="${DAYS:-7}"
      MAX_MB="${MAX_MB:-50}"
      MAX_TOTAL_MB="${MAX_TOTAL_MB:-200}"
      MAX_BYTES=$((MAX_MB * 1024 * 1024))
      KEEP=$((2 * 1024 * 1024))
      [ "$KEEP" -gt "$MAX_BYTES" ] && KEEP=$MAX_BYTES
      find "$LOGS" -maxdepth 1 -type f -name "*.log" -mtime +"$DAYS" -print -delete 2>/dev/null || true
      for f in "$LOGS"/*.log; do
        [ -f "$f" ] || continue
        size=$(wc -c < "$f" | tr -d " ")
        if [ "${size:-0}" -gt "$MAX_BYTES" ]; then
          echo "Truncando volume: $(basename "$f") ($size → ~$KEEP)"
          tmp=$(mktemp 2>/dev/null || echo /tmp/logtrim.$$)
          echo "…(truncated by cleanup-storage-logs.sh volume)" > "$tmp"
          tail -c "$KEEP" "$f" >> "$tmp" 2>/dev/null || true
          mv "$tmp" "$f"
        fi
      done
      max_total=$((MAX_TOTAL_MB * 1024 * 1024))
      total=0
      for f in "$LOGS"/*.log; do
        [ -f "$f" ] || continue
        size=$(wc -c < "$f" | tr -d " ")
        total=$((total + size))
      done
      if [ "$total" -gt "$max_total" ]; then
        echo "Teto volume ${MAX_TOTAL_MB}MB (~$total) — removendo antigos"
        ls -1tr "$LOGS"/*.log 2>/dev/null | while read -r oldf; do
          [ -f "$oldf" ] || continue
          total=0
          for f in "$LOGS"/*.log; do
            [ -f "$f" ] || continue
            size=$(wc -c < "$f" | tr -d " ")
            total=$((total + size))
          done
          [ "$total" -le "$max_total" ] && break
          echo "Removido (teto): $(basename "$oldf")"
          rm -f "$oldf"
        done
      fi
      echo "Tamanho logs no volume:"
      du -sh "$LOGS" 2>/dev/null || true
    ' || return 1
  return 0
}

if run_artisan_prune; then
  echo "Limpeza via artisan OK."
elif run_volume_prune; then
  echo "Limpeza via volume Docker OK."
else
  echo "Fallback path host ./storage/logs (sem volume Docker / app down)."
  prune_logs_dir "$ROOT_DIR/storage/logs" || true
  if [ -d "$ROOT_DIR/storage/logs" ]; then
    echo "Tamanho storage/logs (host) após limpeza:"
    du -sh "$ROOT_DIR/storage/logs" 2>/dev/null || true
  fi
fi

echo "=== Limpeza storage/logs concluída ==="
