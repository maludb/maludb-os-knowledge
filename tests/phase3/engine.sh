#!/usr/bin/env bash
# tests/phase3/engine.sh start|stop — the REAL engine for the Phase 3 proofs that need one (slice 2's forget, slice 4's builds, slice 5's ask):
# the MaluDB API 0.4.0 (M1 worktree) on :8503 over a SCRATCH memory database, and the fake model provider on :8504 (tests/phase0/engine/
# fake_provider.py). Factored from tests/phase0/engine.sh (its README §2 steps, the two it omits: grant_memory_access and maludb_secret_consumer).
#   start  refuses if the scratch database or role exists or a port is taken; creates them; starts both servers with a private auth store;
#          mints a token by proving the login; APPENDS to the proofs' env file ($KN_DEV_ENV): MALUDB_KB_API_URL, MALUDB_KB_API_TOKEN,
#          KN_PROOF_FAKE_URL (the provider for a namespace's configuration), KN_PROOF_PROVIDER_SECRET.
#   stop   stops the servers, drops the database and the role, removes the state directory (also safe when nothing was started).
# Never touched: the live API on :8000, its auth store, any tenant database, any /etc file. The role is kn_engine_p3, never knowledge_mem.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
STATE=${KN_ENGINE_STATE:-/tmp/kn-engine-p3}
ENVF=${KN_DEV_ENV:-/tmp/kn-dev.env}
PY=/home/maludb/maludb-python-api-server/.venv/bin/python
API_M1=/home/maludb/maludb-api-m1
DB=kn_engine_p3
ROLE=kn_engine_p3
P_API=8503; P_FAKE=8504
PSQL=(sudo -n -u postgres psql -v ON_ERROR_STOP=1 -qtA)
die() { printf 'engine: %s\n' "$*" >&2; exit 2; }

stop() {
  for f in "$STATE"/*.pid; do [ -e "$f" ] && kill "$(cat "$f")" 2>/dev/null; done
  sleep 0.5
  for f in "$STATE"/*.pid; do [ -e "$f" ] && kill -9 "$(cat "$f")" 2>/dev/null; rm -f "$f"; done
  if [ -e "$STATE/created" ]; then
    "${PSQL[@]}" -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$DB'" >/dev/null 2>&1
    "${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS $DB" >/dev/null 2>&1 || echo "engine: WARN could not drop $DB"
    "${PSQL[@]}" -d postgres -c "DROP ROLE IF EXISTS $ROLE" >/dev/null 2>&1 || echo "engine: WARN could not drop role $ROLE"
  fi
  rm -rf "$STATE"
}

case "${1:-}" in
  stop) stop ;;
  restart-api)
    # the proofs' "engine down, then back" step: stop the API alone, or start it again on the same auth store
    if [ -e "$STATE/api.pid" ]; then kill "$(cat "$STATE/api.pid")" 2>/dev/null; rm -f "$STATE/api.pid"; echo "api stopped"; exit 0; fi
    export MALUDB_AUTH_STORE="$STATE/auth.db" MALUDB_PG_HOST=127.0.0.1 MALUDB_LOG_DIR="$STATE/log" MALUDB_HTTP_TIMEOUT=20
    unset MALUDB_STORE_KEY MALUDB_EMBED_BASE_URL MALUDB_EMBED_TOKEN MALUDB_EMBED_MODEL MALUDB_LLM_TOKEN
    (cd "$API_M1" && exec "$PY" -m uvicorn app.main:app --host 127.0.0.1 --port $P_API --log-level warning) >>"$STATE/api.log" 2>&1 & echo $! > "$STATE/api.pid"; disown
    for i in $(seq 1 60); do curl -sf "http://127.0.0.1:$P_API/health" >/dev/null && { echo "api started"; exit 0; }; sleep 0.5; done
    die "the API did not come back" ;;
  start)
    [ -x "$PY" ] || die "$PY missing"
    [ -d "$API_M1/app" ] || die "$API_M1 (the M1 worktree, API 0.4.0) missing"
    sudo -n -u postgres true 2>/dev/null || die "passwordless sudo -u postgres is required"
    [ -e "$STATE" ] && stop
    exists=$("${PSQL[@]}" -d postgres -c "SELECT (SELECT count(*) FROM pg_database WHERE datname='$DB') + (SELECT count(*) FROM pg_roles WHERE rolname='$ROLE')")
    [ "$exists" = 0 ] || die "database or role $DB exists — left by an earlier run? check, then: sudo -u postgres dropdb $DB; sudo -u postgres dropuser $ROLE"
    for p in $P_API $P_FAKE; do ss -ltn "sport = :$p" | grep -q LISTEN && die "port $p is in use"; done
    mkdir -p "$STATE/log"; chmod 700 "$STATE"; touch "$STATE/created"
    PASS="kp3_$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')"
    "${PSQL[@]}" -d postgres -c "CREATE DATABASE $DB" >/dev/null || die "createdb failed"
    "${PSQL[@]}" -d $DB -c "CREATE EXTENSION maludb_core CASCADE" >/dev/null || die "CREATE EXTENSION failed"
    "${PSQL[@]}" -d $DB -c "CREATE USER $ROLE PASSWORD '$PASS'" >/dev/null || die "CREATE USER failed"
    "${PSQL[@]}" -d $DB -c "CREATE SCHEMA $ROLE AUTHORIZATION $ROLE" >/dev/null || die "CREATE SCHEMA failed"
    "${PSQL[@]}" -d $DB -c "SELECT * FROM maludb_core.enable_memory_schema('$ROLE')" >/dev/null || die "enable_memory_schema failed"
    "${PSQL[@]}" -d $DB -c "SELECT maludb_core.grant_memory_access('$ROLE', 'write')" >/dev/null || die "grant_memory_access failed"
    "${PSQL[@]}" -d postgres -c "GRANT maludb_secret_consumer TO $ROLE" >/dev/null || die "GRANT maludb_secret_consumer failed"
    export MALUDB_AUTH_STORE="$STATE/auth.db" MALUDB_PG_HOST=127.0.0.1 MALUDB_LOG_DIR="$STATE/log" MALUDB_HTTP_TIMEOUT=20
    unset MALUDB_STORE_KEY MALUDB_EMBED_BASE_URL MALUDB_EMBED_TOKEN MALUDB_EMBED_MODEL MALUDB_LLM_TOKEN
    "$PY" "$ROOT/tests/phase0/engine/fake_provider.py" $P_FAKE >"$STATE/fake.log" 2>&1 & echo $! > "$STATE/fake.pid"; disown
    (cd "$API_M1" && exec "$PY" -m uvicorn app.main:app --host 127.0.0.1 --port $P_API --log-level warning) >"$STATE/api.log" 2>&1 & echo $! > "$STATE/api.pid"; disown
    ok=0
    for i in $(seq 1 60); do
      curl -sf "http://127.0.0.1:$P_API/health" >/dev/null && curl -sf "http://127.0.0.1:$P_FAKE/health" >/dev/null && { ok=1; break; }; sleep 0.5
    done
    [ $ok = 1 ] || { tail -20 "$STATE"/*.log; die "the servers did not start"; }
    TOKEN=$(curl -s -X POST "http://127.0.0.1:$P_API/v1/tokens" -H 'Content-Type: application/json' \
      -d "{\"pg_dbname\":\"$DB\",\"pg_user\":\"$ROLE\",\"pg_password\":\"$PASS\",\"device_name\":\"knowledge-phase3-proof\"}" \
      | "$PY" -c 'import json,sys;print(json.load(sys.stdin).get("token",""))')
    [ -n "$TOKEN" ] || die "could not mint a token"
    umask 077
    { echo "MALUDB_KB_API_URL=http://127.0.0.1:$P_API"; echo "MALUDB_KB_API_TOKEN=$TOKEN"; echo "KN_PROOF_FAKE_URL=http://127.0.0.1:$P_FAKE";
      echo "KN_PROOF_PROVIDER_SECRET=fake-provider-credential-$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \n')"; } >> "$ENVF"
    echo "engine up: API 0.4.0 on :$P_API over $DB, fake provider on :$P_FAKE" ;;
  *) echo "usage: $0 start|stop|restart-api" >&2; exit 2 ;;
esac
