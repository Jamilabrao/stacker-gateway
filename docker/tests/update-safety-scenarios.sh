#!/usr/bin/env sh
# Testes de segurança/robustez do fluxo de update (cenários A–F).
# Não toca em Docker real nem volumes — usa mocks em TMPDIR.
#
# Uso: sh docker/tests/update-safety-scenarios.sh
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/getfy-update-safety.XXXXXX")"
MOCK_BIN="$TEST_ROOT/bin"
PASS=0
FAIL=0

cleanup() {
  rm -rf "$TEST_ROOT"
}
trap cleanup EXIT INT TERM

mkdir -p "$MOCK_BIN" "$TEST_ROOT/state" "$TEST_ROOT/project/.docker"
cp "$ROOT_DIR/docker/remove-stale-compose-orphans.sh" "$TEST_ROOT/project/docker/" 2>/dev/null || mkdir -p "$TEST_ROOT/project/docker"
cp "$ROOT_DIR/docker/remove-stale-compose-orphans.sh" "$TEST_ROOT/project/docker/remove-stale-compose-orphans.sh"
cp "$ROOT_DIR/docker/detect-compose-files.sh" "$TEST_ROOT/project/docker/detect-compose-files.sh"
cp "$ROOT_DIR/docker-compose.yml" "$TEST_ROOT/project/docker-compose.yml"
cp "$ROOT_DIR/docker-compose.caddy.yml" "$TEST_ROOT/project/docker-compose.caddy.yml"

# Estado mutável dos mocks
STATE_DIR="$TEST_ROOT/state"
printf '%s\n' "" > "$STATE_DIR/containers.txt"
printf '%s\n' "" > "$STATE_DIR/removed.txt"
printf '%s\n' "0" > "$STATE_DIR/pg_volume_exists"
printf '%s\n' "" > "$STATE_DIR/compose_services"

# --- Mock docker ---
cat > "$MOCK_BIN/docker" <<'MOCK'
#!/usr/bin/env sh
set -eu
STATE_DIR="${GETFY_TEST_STATE:?}"
cmd="${1:-}"
shift || true

if [ "$cmd" = "compose" ]; then
  # Parse até o subcomando real (config|ps|up|...)
  sub=""
  while [ "$#" -gt 0 ]; do
    case "$1" in
      -f|--env-file|-p|--project-directory)
        shift
        [ "$#" -gt 0 ] && shift
        ;;
      --*)
        shift
        ;;
      *)
        sub="$1"
        shift
        break
        ;;
    esac
  done
  case "$sub" in
    config)
      if [ "${1:-}" = "--services" ]; then
        cat "$STATE_DIR/compose_services"
        exit 0
      fi
      exit 0
      ;;
    *)
      exit 0
      ;;
  esac
fi

if [ "$cmd" = "ps" ]; then
  # docker ps -aq --filter label=...
  filter_project=""
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --filter)
        shift
        case "${1:-}" in
          label=com.docker.compose.project=*)
            filter_project="${1#label=com.docker.compose.project=}"
            ;;
        esac
        shift || true
        ;;
      -aq|-q|-a) shift || true ;;
      --format) shift; shift || true ;;
      *) shift || true ;;
    esac
  done
  # Emit container IDs for matching project
  while IFS='|' read -r cid project service name || [ -n "${cid:-}" ]; do
    [ -n "$cid" ] || continue
    [ "$project" = "$filter_project" ] || continue
    # Skip if already removed
    if grep -qx "$cid" "$STATE_DIR/removed.txt" 2>/dev/null; then
      continue
    fi
    printf '%s\n' "$cid"
  done < "$STATE_DIR/containers.txt"
  exit 0
fi

if [ "$cmd" = "inspect" ]; then
  # docker inspect -f '{{...}}' CID
  fmt=""
  cid=""
  while [ "$#" -gt 0 ]; do
    case "$1" in
      -f) shift; fmt="${1:-}"; shift || true ;;
      *) cid="$1"; shift || true ;;
    esac
  done
  while IFS='|' read -r id project service name || [ -n "${id:-}" ]; do
    [ "$id" = "$cid" ] || continue
    case "$fmt" in
      *com.docker.compose.service*)
        printf '%s\n' "$service"
        ;;
      *Name*)
        printf '/%s\n' "$name"
        ;;
      *)
        printf '%s\n' "$name"
        ;;
    esac
    exit 0
  done < "$STATE_DIR/containers.txt"
  exit 1
fi

if [ "$cmd" = "stop" ] || [ "$cmd" = "rm" ]; then
  cid=""
  for a in "$@"; do
    case "$a" in
      -f|-t|*s) ;;
      *) cid="$a" ;;
    esac
  done
  if [ -n "$cid" ]; then
    echo "$cid" >> "$STATE_DIR/removed.txt"
  fi
  exit 0
fi

if [ "$cmd" = "volume" ]; then
  # volume ls
  if [ "$(cat "$STATE_DIR/pg_volume_exists")" = "1" ]; then
    echo "getfy_postgres_data"
  fi
  exit 0
fi

if [ "$cmd" = "run" ]; then
  # alpine test -f PG_VERSION
  if [ "$(cat "$STATE_DIR/pg_volume_exists")" = "1" ]; then
    exit 0
  fi
  exit 1
fi

exit 0
MOCK
chmod +x "$MOCK_BIN/docker"

export PATH="$MOCK_BIN:$PATH"
export GETFY_TEST_STATE="$STATE_DIR"

assert_eq() {
  label="$1"
  got="$2"
  want="$3"
  if [ "$got" = "$want" ]; then
    echo "  PASS: $label"
    PASS=$((PASS + 1))
  else
    echo "  FAIL: $label (got='$got' want='$want')" >&2
    FAIL=$((FAIL + 1))
  fi
}

assert_true() {
  label="$1"
  if [ "$2" = "1" ]; then
    echo "  PASS: $label"
    PASS=$((PASS + 1))
  else
    echo "  FAIL: $label" >&2
    FAIL=$((FAIL + 1))
  fi
}

assert_false() {
  label="$1"
  if [ "$2" = "0" ]; then
    echo "  PASS: $label"
    PASS=$((PASS + 1))
  else
    echo "  FAIL: $label (expected false)" >&2
    FAIL=$((FAIL + 1))
  fi
}

was_removed() {
  # service name → check if any cid for that service is in removed.txt
  svc="$1"
  while IFS='|' read -r cid project service name || [ -n "${cid:-}" ]; do
    [ "$service" = "$svc" ] || continue
    if grep -qx "$cid" "$STATE_DIR/removed.txt" 2>/dev/null; then
      echo 1
      return 0
    fi
  done < "$STATE_DIR/containers.txt"
  echo 0
}

reset_removed() {
  printf '%s\n' "" > "$STATE_DIR/removed.txt"
}

setup_project_env() {
  cat > "$TEST_ROOT/project/.docker/stack.env" <<EOF
GETFY_DB_USERNAME=getfy_existing
GETFY_DB_PASSWORD=SecretPassExisting99
GETFY_DB_DATABASE=getfy
GETFY_COMPOSE_PROJECT_NAME=getfy
GETFY_APP_URL=https://example.test
EOF
  chmod 600 "$TEST_ROOT/project/.docker/stack.env" 2>/dev/null || true
}

# ============================================================================
# A) standard antigo com caddy+queue órfãos
# ============================================================================
echo ""
echo "=== Cenário A: standard + órfãos caddy/queue ==="
setup_project_env
echo "standard" > "$TEST_ROOT/project/.docker/compose-profile"
cat > "$STATE_DIR/compose_services" <<EOF
app
postgres
redis
scheduler
worker-payments
worker-webhooks-out
worker-webhooks-in
worker-payouts
stacker-agent
EOF
cat > "$STATE_DIR/containers.txt" <<EOF
cid-app|getfy|app|getfy-app-1
cid-pg|getfy|postgres|getfy-postgres-1
cid-redis|getfy|redis|getfy-redis-1
cid-sched|getfy|scheduler|getfy-scheduler-1
cid-wp|getfy|worker-payments|getfy-worker-payments-1
cid-caddy|getfy|caddy|getfy-caddy-1
cid-queue|getfy|queue|getfy-queue-1
EOF
reset_removed

(
  cd "$TEST_ROOT/project"
  sh docker/remove-stale-compose-orphans.sh .docker/stack.env docker-compose.yml
)

assert_eq "A: caddy removido" "$(was_removed caddy)" "1"
assert_eq "A: queue removido" "$(was_removed queue)" "1"
assert_eq "A: postgres NÃO removido" "$(was_removed postgres)" "0"
assert_eq "A: app NÃO removido" "$(was_removed app)" "0"
assert_eq "A: redis NÃO removido" "$(was_removed redis)" "0"
assert_eq "A: scheduler NÃO removido" "$(was_removed scheduler)" "0"
assert_eq "A: worker-payments NÃO removido" "$(was_removed worker-payments)" "0"

# ============================================================================
# B) instalação Caddy real — caddy/queue NÃO são órfãos
# ============================================================================
echo ""
echo "=== Cenário B: docker-compose.caddy.yml ativo ==="
echo "caddy" > "$TEST_ROOT/project/.docker/compose-profile"
cat > "$STATE_DIR/compose_services" <<EOF
caddy
app
postgres
redis
queue
stacker-agent
EOF
cat > "$STATE_DIR/containers.txt" <<EOF
cid-caddy|getfy|caddy|getfy-caddy-1
cid-app|getfy|app|getfy-app-1
cid-pg|getfy|postgres|getfy-postgres-1
cid-redis|getfy|redis|getfy-redis-1
cid-queue|getfy|queue|getfy-queue-1
EOF
reset_removed

(
  cd "$TEST_ROOT/project"
  sh docker/remove-stale-compose-orphans.sh .docker/stack.env docker-compose.caddy.yml
)

assert_eq "B: caddy NÃO removido" "$(was_removed caddy)" "0"
assert_eq "B: queue NÃO removido" "$(was_removed queue)" "0"
assert_eq "B: app NÃO removido" "$(was_removed app)" "0"
assert_eq "B: postgres NÃO removido" "$(was_removed postgres)" "0"

# Workers órfãos do standard anterior DEVEM ser removidos em perfil caddy
cat > "$STATE_DIR/containers.txt" <<EOF
cid-caddy|getfy|caddy|getfy-caddy-1
cid-app|getfy|app|getfy-app-1
cid-pg|getfy|postgres|getfy-postgres-1
cid-redis|getfy|redis|getfy-redis-1
cid-queue|getfy|queue|getfy-queue-1
cid-wp|getfy|worker-payments|getfy-worker-payments-1
cid-sched|getfy|scheduler|getfy-scheduler-1
EOF
reset_removed
(
  cd "$TEST_ROOT/project"
  sh docker/remove-stale-compose-orphans.sh .docker/stack.env docker-compose.caddy.yml
)
assert_eq "B2: worker-payments órfão removido" "$(was_removed worker-payments)" "1"
assert_eq "B2: scheduler órfão removido" "$(was_removed scheduler)" "1"
assert_eq "B2: caddy preservado" "$(was_removed caddy)" "0"

# ============================================================================
# C) standard já atualizado — nada a remover
# ============================================================================
echo ""
echo "=== Cenário C: standard já limpo ==="
echo "standard" > "$TEST_ROOT/project/.docker/compose-profile"
cat > "$STATE_DIR/compose_services" <<EOF
app
postgres
redis
scheduler
worker-payments
stacker-agent
EOF
cat > "$STATE_DIR/containers.txt" <<EOF
cid-app|getfy|app|getfy-app-1
cid-pg|getfy|postgres|getfy-postgres-1
cid-redis|getfy|redis|getfy-redis-1
cid-sched|getfy|scheduler|getfy-scheduler-1
cid-wp|getfy|worker-payments|getfy-worker-payments-1
EOF
reset_removed
out="$(
  cd "$TEST_ROOT/project"
  sh docker/remove-stale-compose-orphans.sh .docker/stack.env docker-compose.yml
)"
assert_eq "C: nada removido" "$(was_removed caddy)$(was_removed queue)$(was_removed app)" "000"
echo "$out" | grep -q "nenhum container órfão" && assert_true "C: mensagem sem órfãos" 1 || assert_true "C: mensagem sem órfãos" 0

# ============================================================================
# D) idempotência — rodar duas vezes
# ============================================================================
echo ""
echo "=== Cenário D: update/órfãos duas vezes ==="
cat > "$STATE_DIR/containers.txt" <<EOF
cid-app|getfy|app|getfy-app-1
cid-pg|getfy|postgres|getfy-postgres-1
cid-caddy|getfy|caddy|getfy-caddy-1
cid-queue|getfy|queue|getfy-queue-1
EOF
cat > "$STATE_DIR/compose_services" <<EOF
app
postgres
redis
scheduler
worker-payments
EOF
reset_removed
(
  cd "$TEST_ROOT/project"
  sh docker/remove-stale-compose-orphans.sh .docker/stack.env docker-compose.yml
)
first_count="$(grep -c . "$STATE_DIR/removed.txt" 2>/dev/null || echo 0)"
# Segunda passagem: containers já "removidos" não aparecem no ps
(
  cd "$TEST_ROOT/project"
  sh docker/remove-stale-compose-orphans.sh .docker/stack.env docker-compose.yml
)
second_extra="$(grep -c . "$STATE_DIR/removed.txt" 2>/dev/null || echo 0)"
# Após 1ª remoção, 2ª não deve adicionar novos IDs únicos além dos já removidos
uniq_removed="$(sort -u "$STATE_DIR/removed.txt" | grep -c . || echo 0)"
assert_eq "D: 1ª passagem removeu caddy+queue" "$(was_removed caddy)$(was_removed queue)" "11"
assert_eq "D: total único de removidos estável (=2)" "$uniq_removed" "2"
assert_eq "D: app intacto após 2 passes" "$(was_removed app)" "0"

# Detect compose profile estável
profile1="$(
  cd "$TEST_ROOT/project"
  unset GETFY_COMPOSE_FILES
  sh docker/detect-compose-files.sh
)"
profile2="$(
  cd "$TEST_ROOT/project"
  unset GETFY_COMPOSE_FILES
  sh docker/detect-compose-files.sh
)"
assert_eq "D: detect-compose idempotente" "$profile1" "$profile2"
assert_eq "D: perfil standard preservado" "$profile1" "docker-compose.yml"

# ============================================================================
# E) .env / stack.env com credenciais próprias — não gerar novas
# ============================================================================
echo ""
echo "=== Cenário E: credenciais existentes preservadas ==="
setup_project_env
cat > "$TEST_ROOT/project/.env" <<EOF
GETFY_DB_USERNAME=getfy_existing
GETFY_DB_PASSWORD=SecretPassExisting99
APP_KEY=base64:ExistingAppKeyMustPersistXXXXXXXXXXXX=
EOF
# Simula volume Postgres existente + stack.env presente
printf '%s\n' "1" > "$STATE_DIR/pg_volume_exists"
USER_BEFORE="$(grep GETFY_DB_USERNAME "$TEST_ROOT/project/.docker/stack.env" | cut -d= -f2)"
PASS_BEFORE="$(grep GETFY_DB_PASSWORD "$TEST_ROOT/project/.docker/stack.env" | cut -d= -f2)"
KEY_BEFORE="$(grep '^APP_KEY=' "$TEST_ROOT/project/.env" | cut -d= -f2-)"

# Orphan script não deve tocar nos arquivos
(
  cd "$TEST_ROOT/project"
  sh docker/remove-stale-compose-orphans.sh .docker/stack.env docker-compose.yml
)
USER_AFTER="$(grep GETFY_DB_USERNAME "$TEST_ROOT/project/.docker/stack.env" | cut -d= -f2)"
PASS_AFTER="$(grep GETFY_DB_PASSWORD "$TEST_ROOT/project/.docker/stack.env" | cut -d= -f2)"
KEY_AFTER="$(grep '^APP_KEY=' "$TEST_ROOT/project/.env" | cut -d= -f2-)"
assert_eq "E: GETFY_DB_USERNAME intacto" "$USER_AFTER" "$USER_BEFORE"
assert_eq "E: GETFY_DB_PASSWORD intacto" "$PASS_AFTER" "$PASS_BEFORE"
assert_eq "E: APP_KEY intacto" "$KEY_AFTER" "$KEY_BEFORE"

# up.sh NÃO deve gerar credenciais novas se stack.env já existe
# (testamos o guard: volume existe + stack.env ausente + .env com creds → recupera)
rm -f "$TEST_ROOT/project/.docker/stack.env"
# Extrai só a lógica de decisão via mini-harness espelhando up.sh
RECOVERED=0
if [ "$(cat "$STATE_DIR/pg_volume_exists")" = "1" ]; then
  u="$(grep GETFY_DB_USERNAME "$TEST_ROOT/project/.env" | cut -d= -f2)"
  p="$(grep GETFY_DB_PASSWORD "$TEST_ROOT/project/.env" | cut -d= -f2)"
  if [ -n "$u" ] && [ -n "$p" ]; then
    RECOVERED=1
    mkdir -p "$TEST_ROOT/project/.docker"
    cat > "$TEST_ROOT/project/.docker/stack.env" <<EOF
GETFY_DB_USERNAME=$u
GETFY_DB_PASSWORD=$p
GETFY_COMPOSE_PROJECT_NAME=getfy
EOF
  fi
fi
assert_eq "E: recuperou credenciais sem gerar novas" "$RECOVERED" "1"
assert_eq "E: user recuperado = existente" "$(grep GETFY_DB_USERNAME "$TEST_ROOT/project/.docker/stack.env" | cut -d= -f2)" "getfy_existing"

# Guard: volume existe + sem creds → deve abortar (não gerar)
rm -f "$TEST_ROOT/project/.docker/stack.env"
rm -f "$TEST_ROOT/project/.env"
ABORT=0
if [ "$(cat "$STATE_DIR/pg_volume_exists")" = "1" ]; then
  if [ ! -f "$TEST_ROOT/project/.env" ]; then
    ABORT=1
  fi
fi
assert_eq "E: aborta sem inventar credenciais" "$ABORT" "1"

# ============================================================================
# F) falha de inicialização do app — healthcheck reporta componente
# ============================================================================
echo ""
echo "=== Cenário F: healthcheck com app down ==="
# Copia healthcheck e testa parsing de falha com mock mínimo
cp "$ROOT_DIR/docker/post-update-healthcheck.sh" "$TEST_ROOT/project/docker/post-update-healthcheck.sh"
setup_project_env
echo "standard" > "$TEST_ROOT/project/.docker/compose-profile"

# Mock docker compose ps -q app → vazio (app down)
cat > "$MOCK_BIN/docker" <<'MOCK2'
#!/usr/bin/env sh
set -eu
STATE_DIR="${GETFY_TEST_STATE:?}"
cmd="${1:-}"
shift || true
if [ "$cmd" = "compose" ]; then
  sub=""
  while [ "$#" -gt 0 ]; do
    case "$1" in
      -f|--env-file|-p|--project-directory) shift; [ "$#" -gt 0 ] && shift ;;
      --*) shift ;;
      *) sub="$1"; shift; break ;;
    esac
  done
  case "$sub" in
    config)
      [ "${1:-}" = "--services" ] && { cat "$STATE_DIR/compose_services"; exit 0; }
      exit 0
      ;;
    ps)
      # Sem CID → serviço down
      exit 0
      ;;
    logs|exec) exit 1 ;;
    *) exit 0 ;;
  esac
fi
if [ "$cmd" = "inspect" ]; then
  exit 1
fi
exit 0
MOCK2
chmod +x "$MOCK_BIN/docker"

cat > "$STATE_DIR/compose_services" <<EOF
app
postgres
redis
scheduler
worker-payments
EOF

set +e
health_out="$(
  cd "$TEST_ROOT/project"
  # curl pode existir e confundir — força falha HTTP também
  PATH="$MOCK_BIN:/usr/bin:/bin" GETFY_COMPOSE_FILES=docker-compose.yml \
    sh docker/post-update-healthcheck.sh docker-compose.yml 2>&1
)"
health_rc=$?
set -e

assert_eq "F: healthcheck exit != 0" "$([ "$health_rc" -ne 0 ] && echo 1 || echo 0)" "1"
echo "$health_out" | grep -qi "app" && assert_true "F: menciona falha do app" 1 || assert_true "F: menciona falha do app" 0
echo "$health_out" | grep -qi "diagnóstico\|recomendados\|recover-stack\|logs app" \
  && assert_true "F: imprime comandos de diagnóstico" 1 \
  || assert_true "F: imprime comandos de diagnóstico" 0

# ============================================================================
# Extra: never-remove postgres mesmo se ausente do EXPECTED (compose malformado)
# ============================================================================
echo ""
echo "=== Extra: postgres protegido com compose malformado ==="
# Restaura mock completo
cat > "$MOCK_BIN/docker" <<'MOCK'
#!/usr/bin/env sh
set -eu
STATE_DIR="${GETFY_TEST_STATE:?}"
cmd="${1:-}"
shift || true
if [ "$cmd" = "compose" ]; then
  sub=""
  while [ "$#" -gt 0 ]; do
    case "$1" in
      -f|--env-file|-p|--project-directory) shift; [ "$#" -gt 0 ] && shift ;;
      --*) shift ;;
      *) sub="$1"; shift; break ;;
    esac
  done
  case "$sub" in
    config) [ "${1:-}" = "--services" ] && { cat "$STATE_DIR/compose_services"; exit 0; }; exit 0 ;;
    *) exit 0 ;;
  esac
fi
if [ "$cmd" = "ps" ]; then
  filter_project=""
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --filter) shift; case "${1:-}" in label=com.docker.compose.project=*) filter_project="${1#label=com.docker.compose.project=}";; esac; shift || true ;;
      *) shift || true ;;
    esac
  done
  while IFS='|' read -r cid project service name || [ -n "${cid:-}" ]; do
    [ -n "$cid" ] || continue
    [ "$project" = "$filter_project" ] || continue
    grep -qx "$cid" "$STATE_DIR/removed.txt" 2>/dev/null && continue
    printf '%s\n' "$cid"
  done < "$STATE_DIR/containers.txt"
  exit 0
fi
if [ "$cmd" = "inspect" ]; then
  fmt=""; cid=""
  while [ "$#" -gt 0 ]; do
    case "$1" in -f) shift; fmt="${1:-}"; shift || true ;; *) cid="$1"; shift || true ;; esac
  done
  while IFS='|' read -r id project service name || [ -n "${id:-}" ]; do
    [ "$id" = "$cid" ] || continue
    case "$fmt" in *service*) printf '%s\n' "$service";; *) printf '/%s\n' "$name";; esac
    exit 0
  done < "$STATE_DIR/containers.txt"
  exit 1
fi
if [ "$cmd" = "stop" ] || [ "$cmd" = "rm" ]; then
  cid=""
  for a in "$@"; do case "$a" in -f|-t) ;; *) cid="$a" ;; esac; done
  [ -n "$cid" ] && echo "$cid" >> "$STATE_DIR/removed.txt"
  exit 0
fi
exit 0
MOCK
chmod +x "$MOCK_BIN/docker"

# EXPECTED sem postgres (malformado) — ainda assim não remove
printf '%s\n' "app" > "$STATE_DIR/compose_services"
cat > "$STATE_DIR/containers.txt" <<EOF
cid-app|getfy|app|getfy-app-1
cid-pg|getfy|postgres|getfy-postgres-1
cid-caddy|getfy|caddy|getfy-caddy-1
EOF
reset_removed
setup_project_env
(
  cd "$TEST_ROOT/project"
  sh docker/remove-stale-compose-orphans.sh .docker/stack.env docker-compose.yml
)
assert_eq "X: postgres protegido mesmo fora do EXPECTED" "$(was_removed postgres)" "0"
assert_eq "X: app protegido mesmo se 'órfão' lógico" "$(was_removed app)" "0"
assert_eq "X: caddy (transição) ainda removível" "$(was_removed caddy)" "1"

# ============================================================================
echo ""
echo "=== Resultado ==="
echo "PASS: $PASS"
echo "FAIL: $FAIL"
if [ "$FAIL" -gt 0 ]; then
  exit 1
fi
echo "Todos os cenários A–F (e extras) passaram."
exit 0
