#!/usr/bin/env bash
# Builds the SCRATCH database the Phase 2+ proofs run against — never the installed application's database.
#   tests/setup_dev.sh            (needs `sudo -n -u postgres`; recreates $KN_DEV_DB, default knowledge_dev)
# 1. drops and creates the scratch database; 2. applies db/*.sql in order as postgres (the same files the installer applies);
# 3. leaves the cluster roles' passwords ALONE when the application is installed (reads them from config/.env); else gives them a
#    scratch password (the kernel's installer sets fresh ones at `apply`); 4. writes the proofs' environment to $KN_DEV_ENV
#    (default /tmp/kn-dev.env, mode 600). Ports are Knowledge's scratch block 8501–8507: the app 8501, the fake kernel 8502,
#    the MCP ports 8504/8505 (named, not started), the fake MaluDB + MaluMail 8506, the internal vhost 8507 (Apache mode).
#    Nothing here is committed or installed.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
DB=${KN_DEV_DB:-knowledge_dev}
ENVF=${KN_DEV_ENV:-/tmp/kn-dev.env}
PW=$(openssl rand -hex 16)
PSQL="sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q"
$PSQL -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$DB'" >/dev/null
$PSQL -c "DROP DATABASE IF EXISTS $DB" -c "CREATE DATABASE $DB"
for f in "$ROOT"/db/0*.sql; do $PSQL -d "$DB" -f "$f" >/dev/null; done
LIVEENV="$ROOT/config/.env"
lv() { grep -m1 "^$1=" "$LIVEENV" | cut -d= -f2-; }
if [ -f "$LIVEENV" ] && [ -n "$(lv DB_PASSWORD)" ] && [ -n "$(lv MCP_RECORDS_DB_PASSWORD)" ] && [ -n "$(lv MCP_ACTIVITY_DB_PASSWORD)" ]; then
  PW_RW=$(lv DB_PASSWORD); PW_REC=$(lv MCP_RECORDS_DB_PASSWORD); PW_ACT=$(lv MCP_ACTIVITY_DB_PASSWORD)
else
  PW_RW=$PW; PW_REC=$PW; PW_ACT=$PW
  for r in knowledge_rw knowledge_records_ro knowledge_activity_ro; do $PSQL -c "ALTER ROLE $r WITH LOGIN PASSWORD '$PW'"; done
fi
umask 077
cat > "$ENVF" <<ENV
APP_ENV=dev
APP_DEBUG=0
APP_NAME="Knowledge"
APP_KEY=knowledge
APP_URL=http://127.0.0.1:8501
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=$DB
DB_USER=knowledge_rw
DB_PASSWORD=$PW_RW
MCP_RECORDS_DB_USER=knowledge_records_ro
MCP_RECORDS_DB_PASSWORD=$PW_REC
MCP_ACTIVITY_DB_USER=knowledge_activity_ro
MCP_ACTIVITY_DB_PASSWORD=$PW_ACT
APP_INTERNAL_PORT=8507
MCP_RECORDS_PORT=8504
MCP_ACTIVITY_PORT=8505
ACTION_TOKEN_KEY=$(openssl rand -hex 32)
ACTIONS_RELAY_KEY=$(openssl rand -hex 32)
OS_LAUNCHER_URL=https://app.example.invalid/
OS_INTERNAL_URL=http://127.0.0.1:8502
OS_APPLICATION_TOKEN=osapp_dev_$(openssl rand -hex 12)
MALUDB_API_URL=http://127.0.0.1:8506
MALUDB_API_TOKEN=dev-maludb-$(openssl rand -hex 8)
MALUMAIL_API_URL=http://127.0.0.1:8506
MALUMAIL_API_KEY=dev-malumail-$(openssl rand -hex 8)
MAIL_FROM=knowledge@example.invalid
MAIL_FROM_NAME="Knowledge"
ATTACHMENT_MAX_BYTES=52428800
ENV
echo "scratch database $DB ready; environment in $ENVF"
