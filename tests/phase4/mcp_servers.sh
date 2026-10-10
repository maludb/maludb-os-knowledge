#!/usr/bin/env bash
# tests/phase4/mcp_servers.sh start|stop|venv — the two MCP servers on SCRATCH loopback ports from the scratch environment ($KN_DEV_ENV):
# records :8504, activity :8505 (never the installed application's ports 8835/8836 or its services). The records server reaches the application's own door (the bridge) on $APP_INTERNAL_PORT — :8501 under php -S,
# :8507 under KN_APP=apache (the vhost's internal port). Pid files live in $KN_DEV_STATE. The Python is the application's own mcp/venv (python3 -m venv mcp/venv && mcp/venv/bin/pip install -r mcp/requirements.txt;
# deploy/ROOT_STEPS.sh does it for the installed copy); the servers are started WITHOUT the MaluDB token in their environment — they never need it (the bridge holds it in PHP); otherwise $KN_MCP_VENV, else a sibling application's venv that already holds FastMCP, asyncpg, httpx and uvicorn — nothing is installed here.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
ENVF=${KN_DEV_ENV:-/tmp/kn-dev.env}
STATE=${KN_DEV_STATE:-/tmp/kn-dev-state}
mkdir -p "$STATE"
VENV=
for c in "${KN_MCP_VENV:-}" "$ROOT/mcp/venv" /srv/apps/doccloud/mcp/venv /srv/apps/spaces/mcp/venv /srv/apps/helpdesk/mcp/venv; do
  [ -n "$c" ] && [ -x "$c/bin/python" ] && "$c/bin/python" -c 'import mcp, asyncpg, httpx, uvicorn' 2>/dev/null && { VENV=$c; break; }
done
[ -n "$VENV" ] || { echo "no venv with FastMCP, asyncpg, httpx and uvicorn found" >&2; exit 1; }
PY="$VENV/bin/python"
stop() {
  for f in "$STATE"/mcp-*.pid; do [ -e "$f" ] && kill "$(cat "$f")" 2>/dev/null || true; rm -f "$f"; done
  for i in $(seq 1 30); do ss -ltn 2>/dev/null | grep -qE ':(8504|8505) ' || break; sleep 0.2; done
  fuser -k -n tcp 8504 >/dev/null 2>&1 || true; fuser -k -n tcp 8505 >/dev/null 2>&1 || true      # by port, never by a command-line pattern
}
case "${1:-}" in
  stop) stop ;;
  venv) echo "$VENV" ;;
  start)
    stop
    set -a; . "$ENVF"; set +a
    INTERNAL=8501; [ "${KN_APP:-php}" = "apache" ] && INTERNAL=8507
    launch() {  # name, then VAR=value pairs, then the interpreter and script — exec so $! is the python process itself
      local name=$1; shift
      ( cd "$ROOT/mcp" && exec env -u MALUDB_KB_API_TOKEN -u MALUDB_KB_API_URL -u MALUDB_API_TOKEN "$@" >"$STATE/mcp-$name.log" 2>&1 ) &
      echo $! > "$STATE/mcp-$name.pid"
    }
    launch records MCP_RECORDS_PORT=8504 MCP_ACTIVITY_PORT=8505 APP_INTERNAL_PORT=$INTERNAL "$PY" "$ROOT/mcp/records_server.py"
    launch activity MCP_RECORDS_PORT=8504 MCP_ACTIVITY_PORT=8505 APP_INTERNAL_PORT=$INTERNAL "$PY" "$ROOT/mcp/activity_server.py"
    for p in 8504 8505; do
      for i in $(seq 1 60); do curl -s -o /dev/null -m 1 "http://127.0.0.1:$p/mcp" && break; sleep 0.2; done
    done
    echo "mcp servers up (records :8504, activity :8505, the bridge on :$INTERNAL; python $VENV)" ;;
  *) echo "usage: $0 start|stop|venv" >&2; exit 2 ;;
esac
