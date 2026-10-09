#!/usr/bin/env bash
# Phase 3, slice 1 — bases, templates, sharing and settings (THE CRUD EXEMPLAR): tests/phase3/slice1/run.sh
# A fresh SCRATCH database (kn_dev1 — never the installed one), the application on :8501 (php -S, or KN_APP=apache for a real Apache with the
# rendered deploy vhost), a fake kernel (:8502), a fake MaluDB + MaluMail (:8506). Needs `sudo -n -u postgres`, php, psql, the kernel's
# web/node_modules (Playwright + Chromium) for the browser proof. Screenshots go to $SHOTS (default /tmp/kn-shots-s1). One line per check;
# exit 0 only if all passed. Uses the same ports as tests/phase2/run.sh and tests/phase0/run.sh: run them one after the other.
#   PROOFS="bases share" tests/phase3/slice1/run.sh     runs a subset (browser included only when named or when PROOFS is unset)
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export KN_DEV_DB=${KN_DEV_DB:-kn_dev1} KN_DEV_ENV=${KN_DEV_ENV:-/tmp/kn-dev1.env} KN_DEV_STATE=${KN_DEV_STATE:-/tmp/kn-dev1-state} SHOTS=${SHOTS:-/tmp/kn-shots-s1}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
mkdir -p "$SHOTS" "$KN_DEV_STATE"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$KN_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$KN_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$KN_DEV_STATE/maludb.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-bases share visibility settings lifecycle json}
for p in $proofs; do
  [ "$p" = browser ] && continue
  echo "== $p"; php "tests/phase3/slice1/$p.php" || status=1
done
if [ -z "${PROOFS:-}" ] || [[ " $PROOFS " == *" browser "* ]]; then echo "== browser"; node tests/phase3/slice1/browser.mjs || status=1; fi
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale — run bin/build_action_registry.php"; status=1; }
logs=$(ls "$KN_DEV_STATE/app.log" "$KN_DEV_STATE/apache/apache.err" 2>/dev/null)
if [ -n "$logs" ] && grep -q 'PHP \(Warning\|Notice\|Fatal\|Deprecated\)' $logs; then echo "  FAIL the application logged a PHP warning:"; grep -h -m5 'PHP \(Warning\|Notice\|Fatal\|Deprecated\)' $logs; status=1; else echo "  ok   no PHP warning in the application's log ($logs)"; fi
[ $status -eq 0 ] && echo "ALL SLICE 1 PROOFS PASSED" || echo "SOME SLICE 1 PROOFS FAILED"
exit $status
