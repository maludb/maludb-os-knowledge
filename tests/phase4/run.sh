#!/usr/bin/env bash
# Phase 4 — the two MCP servers, the bridge, the three shares, app_roles, the gate, the registry: tests/phase4/run.sh
# A fresh SCRATCH database (kn_dev_p4 — never the installed one), the application on :8501 (php -S, or KN_APP=apache: a real Apache, the vhost's internal port :8507), a fake kernel (:8502 — run-facts, K6, K7),
# THE FAKE ENGINE on :8503 (tests/fake_kb_engine.php — app/knowledge/engine.php runs unchanged against it; it never calls a model), a fake MaluDB + MaluMail (:8506), and the servers under test on :8504 (records)
# and :8505 (activity). The fail-closed proofs stop the fake kernel for a moment. NOTHING REAL IS TOUCHED: no live service, Apache, unit, database or role password.
# KN_PROOFS="gate wall" runs some, in order. Needs `sudo -n -u postgres`, php, and a Python venv with FastMCP (see mcp_servers.sh). Exit 0 only when every proof passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export KN_DEV_DB=${KN_DEV_DB:-kn_dev_p4} KN_DEV_ENV=${KN_DEV_ENV:-/tmp/kn-dev-p4.env} KN_DEV_STATE=${KN_DEV_STATE:-/tmp/kn-dev-p4-state}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
mkdir -p "$KN_DEV_STATE"
if [ "${KN_STOP:-0}" = 1 ]; then "$ROOT/tests/phase4/mcp_servers.sh" stop; "$ROOT/tests/phase2/servers.sh" stop; fuser -k -n tcp 8503 >/dev/null 2>&1; echo stopped; exit 0; fi
rm -f "$KN_DEV_STATE"/world*.json
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
[ "${KN_APP:-php}" = "apache" ] && sed -i "s#^APP_INTERNAL_PORT=.*#APP_INTERNAL_PORT=8507#" "$KN_DEV_ENV"
[ "${KN_APP:-php}" != "apache" ] && sed -i "s#^APP_INTERNAL_PORT=.*#APP_INTERNAL_PORT=8501#" "$KN_DEV_ENV"
export FAKE_KB_STATE="$KN_DEV_STATE/kb.json"
echo '{"answer_route": true, "answer_mode": "ok", "scripts": [], "recall": []}' > "$FAKE_KB_STATE"; : > "$FAKE_KB_STATE.log"
KBTOKEN="kb-fake-$(head -c 8 /dev/urandom | od -An -tx1 | tr -d ' \n')"
{ echo "MALUDB_KB_API_URL=http://127.0.0.1:8503"; echo "MALUDB_KB_API_TOKEN=$KBTOKEN"; echo "MALUDB_KB_API_TIMEOUT=3"; echo "FAKE_KB_STATE=$FAKE_KB_STATE"; } >> "$KN_DEV_ENV"
kb_free() { [ -f "$KN_DEV_STATE/kb.pid" ] && kill "$(cat "$KN_DEV_STATE/kb.pid")" 2>/dev/null; fuser -k -n tcp 8503 >/dev/null 2>&1; true; }   # by port, never by a command-line pattern
cleanup() {
  [ "${KN_KEEP:-0}" = 1 ] && { echo "== left running (KN_KEEP=1): KN_STOP=1 bash $0 stops them"; return; }
  "$ROOT/tests/phase4/mcp_servers.sh" stop >/dev/null 2>&1; "$ROOT/tests/phase2/servers.sh" stop >/dev/null 2>&1; kb_free
}
trap cleanup EXIT INT TERM
cd "$ROOT"
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
# after servers.sh (its start stops every pid file in the state directory)
kb_free
(set -a; . "$KN_DEV_ENV"; set +a; exec php -S 127.0.0.1:8503 "$ROOT/tests/fake_kb_engine.php") >"$KN_DEV_STATE/kb.log" 2>&1 & echo $! > "$KN_DEV_STATE/kb.pid"
set -a; . "$KN_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$KN_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$KN_DEV_STATE/maludb.log" FAKE_MALUMAIL_LOG="$KN_DEV_STATE/malumail.log"
for i in $(seq 1 20); do curl -sf http://127.0.0.1:8503/health >/dev/null && break; sleep 0.2; done
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
"$ROOT/tests/phase4/mcp_servers.sh" start >/dev/null || { echo "mcp servers failed"; exit 1; }
: > "$KN_DEV_STATE/p4-called.txt"
status=0
proofs=${KN_PROOFS-gate bridge wall reads asking shares activity registry coverage}
for p in $proofs; do
  echo "== $p"
  php "tests/phase4/$p.php" || status=1
done
logs=$(ls "$KN_DEV_STATE/app.log" "$KN_DEV_STATE/apache/apache.err" 2>/dev/null)
if [ -n "$logs" ] && grep -q 'PHP \(Warning\|Notice\|Fatal\|Deprecated\)' $logs; then echo "  FAIL the application logged a PHP warning:"; grep -h -m5 'PHP \(Warning\|Notice\|Fatal\|Deprecated\)' $logs; status=1; else echo "  ok   no PHP warning in the application's log"; fi
if grep -q -i 'traceback' "$KN_DEV_STATE"/mcp-*.log 2>/dev/null; then echo "  FAIL a server logged a traceback:"; grep -h -i -m3 -B2 -A8 'traceback' "$KN_DEV_STATE"/mcp-*.log | head -30; status=1; else echo "  ok   no traceback in the servers' logs"; fi
[ $status -eq 0 ] && echo "ALL PHASE 4 PROOFS PASSED" || echo "SOME PHASE 4 PROOFS FAILED"
exit $status
