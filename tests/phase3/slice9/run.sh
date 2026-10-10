#!/usr/bin/env bash
# Phase 3, slice 9 — home, admin, usage and tokens: tests/phase3/slice9/run.sh
# A fresh SCRATCH database (kn_dev9), the application on :8501 (php -S, or KN_APP=apache), the fake kernel (:8502 — chat, the K7 read, K6 texts in each mode), THE FAKE ENGINE on :8503
# (tests/fake_kb_engine.php — kb:0's stateful namespace config switched on by the proofs; it never calls a model), the fake MaluDB + MaluMail (:8506). The worker is run pass by pass
# (php bin/worker.php, KN_WORKER_NOW moving its clock). One line per check; exit 0 only if all passed.   PROOFS="home admin" tests/phase3/slice9/run.sh runs a subset, in order.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export KN_DEV_DB=${KN_DEV_DB:-kn_dev9} KN_DEV_ENV=${KN_DEV_ENV:-/tmp/kn-dev9.env} KN_DEV_STATE=${KN_DEV_STATE:-/tmp/kn-dev9-state} SHOTS=${SHOTS:-/tmp/kn-shots-s9}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
mkdir -p "$SHOTS" "$KN_DEV_STATE"
rm -f "$ROOT/storage/cache/health-engine.json"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
export FAKE_KB_STATE="$KN_DEV_STATE/kb.json"
echo '{"answer_route": false, "scripts": [], "recall": [], "config_fake": true, "config": {"provider_name": "knowledge-ledger", "provider_kind": "cloud_api", "model_alias": "knowledge-extract", "model_identifier": "fake-model-1", "base_url": "http://127.0.0.1:9/v1", "embedding_model": "fake-embed-1", "secret_ref": "knowledge-model-credential", "prompt_template": "REFERENCE PROMPT {{chunk}}"}}' > "$FAKE_KB_STATE"
: > "$FAKE_KB_STATE.log"; rm -f "$FAKE_KB_STATE.proj" "$FAKE_KB_STATE.cfg"
KBTOKEN="kb-fake-$(head -c 8 /dev/urandom | od -An -tx1 | tr -d ' \n')"
{ echo "MALUDB_KB_API_URL=http://127.0.0.1:8503"; echo "MALUDB_KB_API_TOKEN=$KBTOKEN"; echo "MALUDB_KB_API_TIMEOUT=3"; echo "FAKE_KB_STATE=$FAKE_KB_STATE"; } >> "$KN_DEV_ENV"
kb_free() { [ -f "$KN_DEV_STATE/kb.pid" ] && kill "$(cat "$KN_DEV_STATE/kb.pid")" 2>/dev/null; fuser -k -n tcp 8503 >/dev/null 2>&1; true; }   # by port, never by a command-line pattern
cleanup() { "$ROOT/tests/phase2/servers.sh" stop; kb_free; }
trap cleanup EXIT
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
# after servers.sh (its start stops every pid file in the state directory)
kb_free
(set -a; . "$KN_DEV_ENV"; set +a; exec php -S 127.0.0.1:8503 "$ROOT/tests/fake_kb_engine.php") >"$KN_DEV_STATE/kb.log" 2>&1 & echo $! > "$KN_DEV_STATE/kb.pid"
set -a; . "$KN_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$KN_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$KN_DEV_STATE/maludb.log" FAKE_MALUMAIL_LOG="$KN_DEV_STATE/malumail.log"
cd "$ROOT"
for i in $(seq 1 20); do curl -sf http://127.0.0.1:8503/health >/dev/null && break; sleep 0.2; done
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-home admin model usage admin_lists agents prefs outbox steps json}
for p in $proofs; do
  [ "$p" = browser ] && continue
  echo "== $p"; php "tests/phase3/slice9/$p.php" || status=1
done
if [ -z "${PROOFS:-}" ] || [[ " $PROOFS " == *" browser "* ]]; then echo "== browser"; node tests/phase3/slice9/browser.mjs || status=1; fi
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale — run bin/build_action_registry.php"; status=1; }
logs=$(ls "$KN_DEV_STATE/app.log" "$KN_DEV_STATE/apache/apache.err" 2>/dev/null)
if [ -n "$logs" ] && grep -q 'PHP \(Warning\|Notice\|Fatal\|Deprecated\)' $logs; then echo "  FAIL the application logged a PHP warning:"; grep -h -m5 'PHP \(Warning\|Notice\|Fatal\|Deprecated\)' $logs; status=1; else echo "  ok   no PHP warning in the application's log"; fi
[ $status -eq 0 ] && echo "ALL SLICE 9 PROOFS PASSED" || echo "SOME SLICE 9 PROOFS FAILED"
exit $status
