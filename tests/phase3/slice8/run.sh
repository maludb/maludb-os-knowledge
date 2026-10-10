#!/usr/bin/env bash
# Phase 3, slice 8 — agents, proposals and feeds: tests/phase3/slice8/run.sh
# A fresh SCRATCH database (kn_dev8), the application on :8501 (php -S, or KN_APP=apache), the fake kernel (:8502 — chat in each state, the K7 read), THE FAKE ENGINE on :8503
# (tests/fake_kb_engine.php — config and a small project store switched on by the proofs; it never calls a model), the proof's web server (:8505, web.php — reached as
# docs.example.test through KN_URL_FETCH_TEST_MAP; dead.example.test maps to a closed port), the fake MaluDB + MaluMail (:8506). The worker is run pass by pass (php bin/worker.php,
# KN_WORKER_NOW moving its clock). One line per check; exit 0 only if all passed.   PROOFS="dispatch poll" tests/phase3/slice8/run.sh runs a subset, in order.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export KN_DEV_DB=${KN_DEV_DB:-kn_dev8} KN_DEV_ENV=${KN_DEV_ENV:-/tmp/kn-dev8.env} KN_DEV_STATE=${KN_DEV_STATE:-/tmp/kn-dev8-state} SHOTS=${SHOTS:-/tmp/kn-shots-s8}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
mkdir -p "$SHOTS" "$KN_DEV_STATE"
rm -f "$ROOT/storage/cache/health-engine.json"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
export FAKE_KB_STATE="$KN_DEV_STATE/kb.json" KN_WEB_STATE="$KN_DEV_STATE/web.json"
echo '{"answer_route": false, "scripts": [], "recall": []}' > "$FAKE_KB_STATE"; : > "$FAKE_KB_STATE.log"; rm -f "$FAKE_KB_STATE.proj"
echo '{}' > "$KN_WEB_STATE"
KBTOKEN="kb-fake-$(head -c 8 /dev/urandom | od -An -tx1 | tr -d ' \n')"
{ echo "MALUDB_KB_API_URL=http://127.0.0.1:8503"; echo "MALUDB_KB_API_TOKEN=$KBTOKEN"; echo "MALUDB_KB_API_TIMEOUT=3"; echo "FAKE_KB_STATE=$FAKE_KB_STATE";
  echo "KN_URL_FETCH_TEST_MAP=\"docs.example.test=93.184.215.14@127.0.0.1:8505;dead.example.test=93.184.215.15@127.0.0.1:8599\""; echo "KN_WEB_STATE=$KN_WEB_STATE"; } >> "$KN_DEV_ENV"
kb_free() { [ -f "$KN_DEV_STATE/kb.pid" ] && kill "$(cat "$KN_DEV_STATE/kb.pid")" 2>/dev/null; fuser -k -n tcp 8503 >/dev/null 2>&1; true; }   # by port, never by a command-line pattern
web_free() { [ -f "$KN_DEV_STATE/web.pid" ] && kill "$(cat "$KN_DEV_STATE/web.pid")" 2>/dev/null; fuser -k -n tcp 8505 >/dev/null 2>&1; true; }
cleanup() { "$ROOT/tests/phase2/servers.sh" stop; kb_free; web_free; }
trap cleanup EXIT
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
# after servers.sh (its start stops every pid file in the state directory)
kb_free; web_free
(set -a; . "$KN_DEV_ENV"; set +a; exec php -S 127.0.0.1:8503 "$ROOT/tests/fake_kb_engine.php") >"$KN_DEV_STATE/kb.log" 2>&1 & echo $! > "$KN_DEV_STATE/kb.pid"
(set -a; . "$KN_DEV_ENV"; set +a; PHP_CLI_SERVER_WORKERS=4 exec php -S 127.0.0.1:8505 "$ROOT/tests/phase3/slice8/web.php") >"$KN_DEV_STATE/web.log" 2>&1 & echo $! > "$KN_DEV_STATE/web.pid"
set -a; . "$KN_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$KN_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$KN_DEV_STATE/maludb.log"
cd "$ROOT"
for i in $(seq 1 20); do curl -sf http://127.0.0.1:8503/health >/dev/null && break; sleep 0.2; done
for i in $(seq 1 20); do curl -s -o /dev/null http://127.0.0.1:8505/page-stable.html && break; sleep 0.2; done
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-dispatch poll proposals effects note feeds sync urls json}
for p in $proofs; do
  [ "$p" = browser ] && continue
  echo "== $p"; php "tests/phase3/slice8/$p.php" || status=1
done
if [ -z "${PROOFS:-}" ] || [[ " $PROOFS " == *" browser "* ]]; then echo "== browser"; node tests/phase3/slice8/browser.mjs || status=1; fi
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale — run bin/build_action_registry.php"; status=1; }
logs=$(ls "$KN_DEV_STATE/app.log" "$KN_DEV_STATE/apache/apache.err" 2>/dev/null)
if [ -n "$logs" ] && grep -q 'PHP \(Warning\|Notice\|Fatal\|Deprecated\)' $logs; then echo "  FAIL the application logged a PHP warning:"; grep -h -m5 'PHP \(Warning\|Notice\|Fatal\|Deprecated\)' $logs; status=1; else echo "  ok   no PHP warning in the application's log"; fi
[ $status -eq 0 ] && echo "ALL SLICE 8 PROOFS PASSED" || echo "SOME SLICE 8 PROOFS FAILED"
exit $status
