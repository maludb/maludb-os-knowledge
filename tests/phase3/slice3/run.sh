#!/usr/bin/env bash
# Phase 3, slice 3 — guidelines, vocabulary and focus areas, and the compile module: tests/phase3/slice3/run.sh
# A fresh SCRATCH database (kn_dev3), the application on :8501 (php -S, or KN_APP=apache), the fake kernel (:8502), the REAL MaluDB API 0.4.0 on
# a scratch memory database (:8503) with the fake provider (:8504) — tests/phase3/engine.sh —, the fake MaluDB + MaluMail (:8506). The compile
# proof configures kb:0 (the engine connection) with the fake provider. One line per check; exit 0 only if all passed.
#   PROOFS="compile guidelines" tests/phase3/slice3/run.sh     runs a subset, in order (compile first: it configures kb:0)
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export KN_DEV_DB=${KN_DEV_DB:-kn_dev3} KN_DEV_ENV=${KN_DEV_ENV:-/tmp/kn-dev3.env} KN_DEV_STATE=${KN_DEV_STATE:-/tmp/kn-dev3-state} SHOTS=${SHOTS:-/tmp/kn-shots-s3}
export KN_ENGINE_STATE=${KN_ENGINE_STATE:-/tmp/kn-engine-p3}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
mkdir -p "$SHOTS" "$KN_DEV_STATE"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
cleanup() { "$ROOT/tests/phase2/servers.sh" stop; "$ROOT/tests/phase3/engine.sh" stop; }
trap cleanup EXIT
"$ROOT/tests/phase3/engine.sh" start || { echo "the engine did not start"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
set -a; . "$KN_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$KN_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$KN_DEV_STATE/maludb.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-compile guidelines preview vocabulary areas engine json}
for p in $proofs; do
  [ "$p" = browser ] && continue
  echo "== $p"; php "tests/phase3/slice3/$p.php" || status=1
done
if [ -z "${PROOFS:-}" ] || [[ " $PROOFS " == *" browser "* ]]; then echo "== browser"; node tests/phase3/slice3/browser.mjs || status=1; fi
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale — run bin/build_action_registry.php"; status=1; }
logs=$(ls "$KN_DEV_STATE/app.log" "$KN_DEV_STATE/apache/apache.err" 2>/dev/null)
if [ -n "$logs" ] && grep -q 'PHP \(Warning\|Notice\|Fatal\|Deprecated\)' $logs; then echo "  FAIL the application logged a PHP warning:"; grep -h -m5 'PHP \(Warning\|Notice\|Fatal\|Deprecated\)' $logs; status=1; else echo "  ok   no PHP warning in the application's log"; fi
[ $status -eq 0 ] && echo "ALL SLICE 3 PROOFS PASSED" || echo "SOME SLICE 3 PROOFS FAILED"
exit $status
