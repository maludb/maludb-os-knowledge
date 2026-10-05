#!/usr/bin/env bash
# Phase 0 proof of the application KIT, WITHOUT a kernel (testing-without-a-kernel.md): sign-on, the mirror, sessions, CSRF, the
# activity funnel, the home, the attachment gate, the key rules and the ingest bridge — against a scratch database, PHP's built-in server
# and a fake kernel. Nothing installed or live is touched.
#
# Re-run:   bash /srv/apps/knowledge/tests/phase0/run.sh        (as maludb; exit 0 = every check passed; PASS/FAIL per check, a count at the end)
#
# Needs: passwordless `sudo -n -u postgres` (creates and drops the scratch database `knowledge_kit_scratch`), PostgreSQL 17 on 127.0.0.1
#   with password logins, php 8.3 (pdo_pgsql, curl, fileinfo), curl, openssl, and a python with asyncpg + httpx for the ingest check
#   (/var/www/mcp/venv/bin/python, else any $KN_PY). Scratch ports (Knowledge's block is 8501-8507): 8501 the app, 8502 the fake kernel,
#   8506 the fake MaluDB + MaluMail. It refuses to start if one is taken. Its secrets live in a temp dir (KN_DEV_ENV), never in config/.env.
# Roles: db/001 creates knowledge_rw, knowledge_records_ro, knowledge_activity_ro when absent. This script records which of the three it
#   created and drops ONLY those at the end; roles that already existed are kept (their passwords are set for the run — refused if the
#   application is installed: config/.env exists).
set -uo pipefail
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
DB=knowledge_kit_scratch
PY=${KN_PY:-/var/www/mcp/venv/bin/python}
P_APP=8501; P_KERNEL=8502; P_FAKE=8506
TMP=$(mktemp -d /tmp/knowledge-kit.XXXXXX)
export KN_DEV_ENV=${KN_DEV_ENV:-$TMP/dev.env}
PSQL=(sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q)
pass=0; fail=0; PIDS=(); CREATED_ROLES=(); ATTACH_DIRS=()
ok()   { echo "PASS $1"; pass=$((pass+1)); }
bad()  { echo "FAIL $1"; fail=$((fail+1)); }
check(){ if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got: [$1], wanted: [$2])"; fi; }
has()  { if grep -q -- "$2" <<<"$1"; then ok "$3"; else bad "$3 (missing: $2)"; fi; }
hasnt(){ if grep -q -- "$2" <<<"$1"; then bad "$3 (found: $2)"; else ok "$3"; fi; }
sql()  { sudo -n -u postgres psql -d "$DB" -Atq -c "$1"; }
asm()  { sudo -n -u postgres psql -d "$DB" -Atq -c "SET app.member_id = '$1'" -c "$2"; }      # a query as member $1

cleanup() {
  local rc=$?
  for p in "${PIDS[@]:-}"; do [ -n "$p" ] && kill "$p" 2>/dev/null; done
  sleep 0.5
  for p in "${PIDS[@]:-}"; do [ -n "$p" ] && kill -9 "$p" 2>/dev/null; done
  for d in "${ATTACH_DIRS[@]:-}"; do [ -n "$d" ] && rm -rf "$ROOT/storage/attachments/$d"; done
  "${PSQL[@]}" -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$DB'" >/dev/null 2>&1
  "${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS $DB" >/dev/null 2>&1 || echo "WARN: could not drop database $DB"
  for r in "${CREATED_ROLES[@]:-}"; do [ -n "$r" ] && "${PSQL[@]}" -d postgres -c "DROP ROLE IF EXISTS $r" >/dev/null 2>&1 || true; done
  rm -rf "$TMP"
  exit $rc
}
trap cleanup EXIT INT TERM

[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); this proof is for the scratch phase"; exit 2; }
for port in $P_APP $P_KERNEL $P_FAKE; do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }; done

echo "== scratch database $DB"
for r in knowledge_rw knowledge_records_ro knowledge_activity_ro; do
  [ "$(sudo -n -u postgres psql -Atqc "select count(*) from pg_roles where rolname='$r'")" = 0 ] && CREATED_ROLES+=("$r")
done
"${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS $DB" -c "CREATE DATABASE $DB" || { echo "ABORT: cannot create $DB"; exit 2; }
for f in "$ROOT"/db/0*.sql; do "${PSQL[@]}" -d "$DB" -f "$f" >/dev/null || { echo "ABORT: $f failed"; exit 2; }; done
PW=$(openssl rand -hex 16)
for r in knowledge_rw knowledge_records_ro knowledge_activity_ro; do "${PSQL[@]}" -d postgres -c "ALTER ROLE $r WITH LOGIN PASSWORD '$PW'"; done
umask 077
cat > "$KN_DEV_ENV" <<ENV
APP_ENV=dev
APP_DEBUG=0
APP_NAME="Knowledge"
APP_KEY=knowledge
APP_URL=http://127.0.0.1:$P_APP
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=$DB
DB_USER=knowledge_rw
DB_PASSWORD=$PW
MCP_RECORDS_DB_USER=knowledge_records_ro
MCP_RECORDS_DB_PASSWORD=$PW
MCP_ACTIVITY_DB_USER=knowledge_activity_ro
MCP_ACTIVITY_DB_PASSWORD=$PW
ACTION_TOKEN_KEY=$(openssl rand -hex 32)
ACTIONS_RELAY_KEY=$(openssl rand -hex 32)
OS_LAUNCHER_URL=https://app.example.invalid/
OS_INTERNAL_URL=http://127.0.0.1:$P_KERNEL
OS_APPLICATION_TOKEN=osapp_dev_$(openssl rand -hex 12)
MALUDB_API_URL=http://127.0.0.1:$P_FAKE
MALUDB_API_TOKEN=dev-maludb-$(openssl rand -hex 8)
MALUMAIL_API_URL=http://127.0.0.1:$P_FAKE
MALUMAIL_API_KEY=dev-malumail-$(openssl rand -hex 8)
ATTACHMENT_MAX_BYTES=52428800
FAKE_KERNEL_STATE=$TMP/kernel.json
FAKE_MALUDB_LOG=$TMP/maludb.log
FAKE_MALUMAIL_LOG=$TMP/malumail.log
ENV
set -a; . "$KN_DEV_ENV"; set +a
cd "$ROOT"
check "$(sql "select count(*) from pg_class where relname in ('knowledge_bases','sources','attachments','mcp_bases') and relnamespace = 'public'::regnamespace")" "4" "db/001-014 applied to the scratch database"

# the fake kernel serves the fixture's feed
php -r '$f = json_decode(file_get_contents("bin/dev_directory.json"), true); file_put_contents(getenv("FAKE_KERNEL_STATE"), json_encode(["feed" => $f["feed"]]));'
php -S 127.0.0.1:$P_KERNEL tests/fake_kernel.php >"$TMP/kernel.log" 2>&1 & PIDS+=($!)
php -S 127.0.0.1:$P_FAKE tests/fake_maludb.php >"$TMP/fake.log" 2>&1 & PIDS+=($!)
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:$P_APP -t html tests/dev_router.php >"$TMP/app.log" 2>&1 & PIDS+=($!)
sleep 1.2
APP="http://127.0.0.1:$P_APP"

echo "== the mirror (directory sync through the fake kernel)"
php bin/directory_sync.php >"$TMP/sync.out" 2>&1; check "$?" "0" "the change feed applies through the kernel's internal API (directory_sync.php)"
check "$(sql "select count(*) || ' of ' || count(*) filter (where capability is not null) from members")" "9 of 8" "nine members mirrored, eight admitted (the Watcher holds no grant)"
check "$(sql "select roles::text from members where id = 27")" "{curator,user}" "roles map from the feed's access[]: Marco holds curator and user"

echo "== sign-on"
check "$(curl -s $APP/api/v1/health | php -r 'echo json_decode(stream_get_contents(STDIN), true)["application"] ?? "?";')" "knowledge" "health answers and names the application"
check "$(curl -s -o /dev/null -w '%{http_code}' $APP/api/v1/health)" "200" "health answers 200"
login() { curl -s -o /dev/null -w '%{http_code}' -c "$TMP/jar$1" "$(php bin/dev_handoff.php "$1")"; }
url27=$(php bin/dev_handoff.php 27)
check "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c "$TMP/jar27" "$url27")" "302 $APP/" "a signed hand-off token opens a session (302 to /)"
check "$(curl -s -o /dev/null -w '%{http_code}' "$url27")" "403" "the same token a second time is refused (replay)"
check "$(curl -s -o /dev/null -w '%{http_code}' "$(HANDOFF_TTL=-120 php bin/dev_handoff.php 26)")" "403" "an expired token is refused"
pyld=$(php bin/dev_handoff.php 26 | sed -E 's/.*token=([^&]+)&.*/\1/' | sed -E 's/\.[a-f0-9]{64}$//')
check "$(curl -s -o /dev/null -w '%{http_code}' "$APP/sso?token=$pyld.$(printf '0%.0s' $(seq 1 64))&claims=x.y")" "403" "a tampered signature is refused"
check "$(curl -s -o /dev/null -w '%{http_code}' "$(APP_KEY=other php bin/dev_handoff.php 26)")" "403" "a token minted for another application is refused (audience)"
check "$(curl -s -o /dev/null -w '%{http_code}' "$(php bin/dev_handoff.php 999)")" "403" "an unknown member is refused"
check "$(sql "select count(*) from members where id = 999")" "0" "...and is never created in the mirror"
check "$(sql "select string_agg(after->>'reason', ',' order by id) from activity_log where action = 'member.sign_on.refused'")" "replay,token,token,token,capability" "every refusal is logged with its reason; none is told to the visitor"
check "$(sql "select count(*) from member_sessions where member_id = 27 and ended_at is null")" "1" "the session is listed for the kernel's sign-out notice"

echo "== roles and rights (kn_has_right, kn_is_admin, kn_is_guest)"
check "$(asm 1 "select kn_is_admin() and kn_has_right('settings.manage') and kn_has_right('keys.all')")" "t" "the super-admin runs Knowledge and holds every right"
check "$(asm 2 "select kn_is_admin() and kn_has_right('model.manage')")" "t" "a second admin (role admin) holds the admin rights"
check "$(asm 27 "select kn_has_right('base.manage')::text || kn_has_right('keys.manage')::text || kn_has_right('settings.manage')::text")" "truetruefalse" "a curator manages bases and keys, not the application"
check "$(asm 26 "select kn_has_right('bases.create')::text || kn_has_right('sources.add')::text || kn_has_right('base.manage')::text")" "truetruefalse" "a member creates a base and adds sources, but curates nothing by role"
check "$(asm 32 "select kn_has_right('bases.read')::text || kn_has_right('answers.rate')::text || kn_has_right('bases.create')::text")" "truetruefalse" "a reader asks and rates; creates nothing"
check "$(asm 29 "select kn_is_guest()") $(asm 32 "select kn_is_guest()")" "t f" "an external holding only Reader is a guest; an internal reader is not"
check "$(asm 40 "select kn_has_right('bases.read')")" "t" "an agent granted the member role reads"
check "$(asm 41 "select kn_has_right('bases.read')")" "f" "an agent the directory has not admitted holds nothing"
check "$(asm 27 "select kn_current_member_id() = app_current_member_id() and kn_current_member_id() = 27")" "t" "kn_current_member_id() is the contract's alias"

echo "== the shell and the home"
for m in 1 26 28 29 32; do login $m >/dev/null; done
home26=$(curl -s -b "$TMP/jar26" "$APP/")
has "$home26" 'id="home-create-base-btn"' "a member with no bases sees the one button, Create a knowledge base"
has "$home26" 'Create a knowledge base' "...and its words are the design's"
hasnt "$home26" 'id="nav-admin"' "the Admin item is not shown to a member"
has "$(curl -s -b "$TMP/jar1" "$APP/")" 'id="nav-admin"' "the Admin item is shown to an admin"
sql "insert into knowledge_bases (key, name, owner_member_id, visibility, created_by) values ('kit-open', 'SMOKE Open Base', 27, 'internal', 27), ('kit-private', 'SMOKE Private Base', 27, 'private', 27)" >/dev/null
BASE_OPEN=$(sql "select id from knowledge_bases where key = 'kit-open'"); BASE_PRIV=$(sql "select id from knowledge_bases where key = 'kit-private'")
home27=$(curl -s -b "$TMP/jar27" "$APP/")
has "$home27" 'SMOKE Open Base' "the owner's home lists their internal base"; has "$home27" 'SMOKE Private Base' "...and their private one"
home26=$(curl -s -b "$TMP/jar26" "$APP/")
has "$home26" 'SMOKE Open Base' "a member's home lists an internal base"; hasnt "$home26" 'SMOKE Private Base' "...and never a private base they were not given"
home32=$(curl -s -b "$TMP/jar32" "$APP/")
has "$home32" 'SMOKE Open Base' "a reader's home lists the internal base"; hasnt "$home32" 'id="home-create-base-btn"' "...with no create button (a reader creates nothing)"
code29=$(curl -s -o "$TMP/home29.html" -w '%{http_code}' -b "$TMP/jar29" "$APP/"); home29=$(cat "$TMP/home29.html")
check "$code29" "200" "an external member holding only Reader lands on the shell"
has "$home29" 'id="app-tabbar"' "...with the phone's tab bar"; hasnt "$home29" 'SMOKE Open Base' "...and sees no base nobody named to them"
has "$home29" 'id="home-nothing-shared"' "...and is told nothing has been shared"

echo "== the person's own screens"
for pair in "settings/:settings-content" "settings/tokens/:tokens-content" "notifications:notifications-content" "activity:activity-content"; do
  path=${pair%%:*}; marker=${pair##*:}
  check "$(curl -s -o "$TMP/screen.html" -w '%{http_code}' -b "$TMP/jar26" "$APP/$path"; grep -c "id=\"$marker\"" "$TMP/screen.html")" "2001" "/$path renders for a member (200, its content region present)"
done
check "$(curl -s -b "$TMP/jar26" -H 'Accept: application/json' "$APP/settings/" | php -r '$j = json_decode(stream_get_contents(STDIN), true); echo isset($j["data"]["kinds"]["build_done"]) ? "kinds" : "none";')" "kinds" "...and /settings/ answers JSON with the notification kinds the schema knows"

echo "== CSRF"
tok=$(curl -s -b "$TMP/jar26" -c "$TMP/jar26" "$APP/settings/" | sed -n 's/.*name="csrf-token" id="csrf-token-meta" content="\([a-f0-9]*\)".*/\1/p' | head -1)
[ -z "$tok" ] && tok=$(curl -s -b "$TMP/jar26" "$APP/settings/" | grep -o 'csrf-token-meta" content="[a-f0-9]*' | head -1 | sed 's/.*content="//')
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar26" -X POST -d 'email_enabled=yes' "$APP/settings/prefs.php")" "403" "a POST without a CSRF token is refused"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar26" -X POST -d 'email_enabled=yes&csrf_token=deadbeef' "$APP/settings/prefs.php")" "403" "a POST with a bad CSRF token is refused"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar26" -X POST -d 'email_enabled=no&kinds[]=build_done&csrf_token='"$tok" "$APP/settings/prefs.php")" "302" "a POST with the session's token is accepted (prefs_save)"
check "$(sql "select email_enabled::text || ':' || kinds::text from notification_prefs where member_id = 26")" "false:{build_done}" "...and the person's choices were written"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar26" -X POST -H "X-CSRF-Token: $tok" -d 'label=Kit&scope=api' "$APP/settings/tokens/mint.php")" "422" "a person's own token cannot be minted with the api scope (a key belongs to a base)"
check "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST -d 'email_enabled=yes' "$APP/settings/prefs.php")" "302 https://app.example.invalid/launcher?app=knowledge" "an anonymous POST goes to the launcher (there is no login form here)"
check "$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' -X POST -d 'email_enabled=yes' "$APP/settings/prefs.php")" "401" "...and answers a JSON caller 401, never a bare 403"

echo "== the activity funnel"
out=$(php tests/phase0/probe.php log 2>/dev/null)
check "$(sql "select base_id || ':' || token_id || ':' || source from activity_log where action = 'probe.event'")" "7:3:cron" "log_activity writes base_id and token_id (source cron under the CLI)"
check "$(echo "$out" | tr '\n' ' ')" "logged survived " "log_activity never throws on a bad actor, an unencodable payload or a bad key"
before=$(sql "select count(*) from activity_log where action = 'screen.view'")
curl -s -o /dev/null -b "$TMP/jar26" "$APP/"
a1=$(sql "select count(*) from activity_log where action = 'screen.view'")
curl -s -o /dev/null -b "$TMP/jar26" -H 'Accept: application/json' "$APP/"
a2=$(sql "select count(*) from activity_log where action = 'screen.view'")
curl -s -o /dev/null -b "$TMP/jar26" -H 'Accept: application/json' -H 'X-Screen-View: 1' "$APP/"
a3=$(sql "select count(*) from activity_log where action = 'screen.view'")
check "$((a1-before)) $((a2-a1)) $((a3-a2))" "1 0 1" "a screen view logs for a page and for JSON only with X-Screen-View: 1"
curl -s -o /dev/null -b "$TMP/jar26" -X POST -H "X-CSRF-Token: $tok" --data-urlencode 'utterance=SECRETQ-7731 what does section nine say about egress' -d 'screen=home' "$APP/assistant/ask.php"
check "$(sql "select count(*) from activity_log where after::text like '%SECRETQ-7731%' or before::text like '%SECRETQ-7731%' or route like '%SECRETQ%'")" "0" "a question's words never appear in an activity payload"
check "$(sql "select (after->>'length')::int > 20 from activity_log where action = 'assistant.ask' order by id desc limit 1")" "t" "...only its length is logged (the command bar)"
check "$(php -r '$l = file("'"$TMP"'/kernel.json.chat"); $r = json_decode($l[0], true); echo $r["agent"] . ":" . $r["acting"] . ":" . (str_contains($r["utterance"], "SECRETQ-7731") ? "words" : "none");')" "expert:26:words" "the command bar posts the words to the kernel's chat endpoint as the acting person"
check "$(sql "select count(*) from activity_log where session_id is null and source in ('web','assistant') and action like 'member.%' and action <> 'member.sign_on.refused'")" "0" "browser rows carry the session id"

echo "== attachments"
sql "insert into sources (base_id, kind, title, added_by) values ($BASE_OPEN, 'file', 'SMOKE open source', 27), ($BASE_PRIV, 'file', 'SMOKE private source', 27)" >/dev/null
SRC_OPEN=$(sql "select id from sources where base_id = $BASE_OPEN"); SRC_PRIV=$(sql "select id from sources where base_id = $BASE_PRIV")
ATTACH_DIRS+=("source-$SRC_OPEN" "source-$SRC_PRIV")
att=$(php tests/phase0/probe.php attach "$SRC_OPEN" 2>&1)
check "$(echo "$att" | grep -c '^refused:\(exe\|exe_pdf\|script\):\?' )" "3" "an executable, an executable renamed .pdf and a shell script are refused (judged from the bytes)"
check "$(echo "$att" | grep -c '^refused:svg')" "2" "an SVG, and an SVG named .txt, are refused"
has "$att" '^accepted:pdf:' "a small PDF is accepted"; has "$att" '^accepted:md:' "...and a Markdown file"
PDF_ID=$(echo "$att" | sed -n 's/^accepted:pdf:\([0-9]*\)$/\1/p')
check "$(sql "select mime_type from attachments where id = $PDF_ID")" "application/pdf" "the type is the bytes' (finfo), stored with the row"
att2=$(php tests/phase0/probe.php attach "$SRC_PRIV" 2>&1); PDF_PRIV=$(echo "$att2" | sed -n 's/^accepted:pdf:\([0-9]*\)$/\1/p')
hdr=$(curl -s -D - -o /dev/null -b "$TMP/jar26" "$APP/files/$PDF_ID")
has "$hdr" '^HTTP/1.1 200' "the gate serves a file in a base the member may read"; has "$hdr" 'Content-Disposition: inline' "...a PDF inline"
has "$hdr" 'X-Content-Type-Options: nosniff' "...with nosniff"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar26" "$APP/files/$PDF_PRIV")" "404" "a file in a private base the member was not given is a 404, not a 403"
check "$(curl -s -o /dev/null -w '%{http_code}' "$APP/files/$PDF_ID")" "401" "an anonymous request to the gate is a 401"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar27" "$APP/files/$PDF_PRIV")" "200" "the owner of the private base is served"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar26" "$APP/storage/attachments/source-$SRC_OPEN/")" "404" "storage/ is not on the web root: a file is never reachable by path"

echo "== keys: a key reaches one base"
RAW="mcp_$(openssl rand -hex 24)"; HASH=$(printf '%s' "$RAW" | openssl dgst -sha256 | sed 's/.* //')
sql "insert into mcp_access_tokens (member_id, label, token_hash, scope, base_id) values (27, 'SMOKE key', '$HASH', 'api', $BASE_OPEN)" >/dev/null
TOK=$(sql "select id from mcp_access_tokens where token_hash = '$HASH'")
check "$(sql "select kn_key_reaches_base($TOK, $BASE_OPEN)::text || kn_key_reaches_base($TOK, $BASE_PRIV)::text")" "truefalse" "a key bound to one base reaches it and not another base of the same owner (kn_key_reaches_base)"
check "$(sql "select base_id from kn_resolve_key('$HASH')")" "$BASE_OPEN" "a key resolves to its base (kn_resolve_key)"
r1=$(php tests/phase0/probe.php apikey "$RAW" "$BASE_OPEN" 2>&1 | tr '\n' ' ')
has "$r1" "authenticated:27:key=yes" "the token API authenticates a key as a key"; has "$r1" "reached:$BASE_OPEN" "...and lets it reach its own base"
r2=$(php tests/phase0/probe.php apikey "$RAW" "$BASE_PRIV" 2>&1 | tr '\n' ' ')
has "$r2" '"code":"not_found"' "...but another base of the same owner answers not_found through the API"
MCP="mcp_$(openssl rand -hex 24)"; MH=$(printf '%s' "$MCP" | openssl dgst -sha256 | sed 's/.*= *//;s/.* //')
sql "insert into mcp_access_tokens (member_id, label, token_hash, scope) values (27, 'SMOKE mcp', '$MH', 'mcp')" >/dev/null
r3=$(php tests/phase0/probe.php apikey "$MCP" "$BASE_OPEN" 2>&1 | tr '\n' ' ')
has "$r3" '"code":"unauthorized"' "a person's mcp token is not a door to the token API"
rl=$(sql "select string_agg(ok::text, ',' order by n) from (select n, (kn_rate_ok($TOK)).ok from generate_series(1, 31) n) s" | tr ',' '\n' | sort | uniq -c | tr -s ' ' | tr '\n' ';')
check "$rl" " 1 false; 30 true;" "the rate rule counts 30 calls a minute and refuses the 31st (kn_rate_ok)"

echo "== the worker, the ingest bridge, mail"
php bin/worker.php >/dev/null 2>&1; check "$?:$(sql "select count(*) from activity_log where action = 'cron.run' and source = 'cron'")" "0:1" "the worker stub takes its lock, logs cron.run and exits 0"
if [ -x "$PY" ] && "$PY" -c 'import asyncpg, httpx' 2>/dev/null; then
  "$PY" mcp/activity_ingest.py >"$TMP/ingest.out" 2>&1; check "$?" "0" "the ingest bridge ships the log to MaluDB (fake) and advances its checkpoint"
  check "$(php -r '$first = json_decode(file(getenv("FAKE_MALUDB_LOG"))[0], true); echo array_key_first($first["payload"]) . "=" . $first["payload"]["application"] . ":" . $first["kind"];')" "application=knowledge:activity" "the payload names the application first (memory.md)"
  check "$(php -r 'foreach (file(getenv("FAKE_MALUDB_LOG")) as $l) { $b = json_decode($l, true); if (($b["payload"]["action"] ?? "") === "probe.event") echo $b["payload"]["base_id"] . ":" . $b["payload"]["token_id"]; }')" "7:3" "...and carries base_id and token_id"
else
  bad "the ingest bridge needs a python with asyncpg and httpx (KN_PY)"; bad "the payload names the application first"; bad "payload carries base_id and token_id"
fi
check "$(php tests/phase0/probe.php mail 2>&1)" "sent:200" "MaluMail sends through the (fake) API when a key is set"
check "$(MALUMAIL_API_KEY= php tests/phase0/probe.php mail 2>&1 | cut -d: -f1)" "mail-threw" "with no key the send raises a catchable error (mail is optional)"

echo "== the kernel's word shuts the doors"
notice="27.$(date +%s).knowledge"; sig=$(printf 'sso-logout:%s' "$notice" | openssl dgst -sha256 -hmac "$ACTION_TOKEN_KEY" | sed 's/.* //')
check "$(curl -s -o /dev/null -w '%{http_code}' -X POST --data-urlencode "notice=$notice.$sig" "$APP/sso/logout")" "204" "the kernel's sign-out notice answers 204"
check "$(sql "select ended_by from member_sessions where member_id = 27 order by created_at desc limit 1")" "kernel" "...and ends the member's session"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar27" "$APP/")" "302" "...so the next request goes to the launcher"
check "$(curl -s -o /dev/null -w '%{http_code}' -X POST --data-urlencode "notice=27.1.knowledge.deadbeef" "$APP/sso/logout")" "204" "a bad notice also answers 204 and changes nothing"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar28" "$APP/")" "200" "Bea, signed on earlier, is let in while her grant stands"
php -r '$s = json_decode(file_get_contents(getenv("FAKE_KERNEL_STATE")), true); $s["incremental"] = ["schema" => "os.directory-changes/1", "since" => "2026-01-01T00:00:00.000000Z", "next" => "2026-01-02T00:00:00.000000Z", "full" => false, "members" => [], "departments" => [], "memberships" => [], "deleted_departments" => [], "scopes" => [], "access" => [["member_id" => 28, "capability" => null, "roles" => [], "scopes" => []]]]; file_put_contents(getenv("FAKE_KERNEL_STATE"), json_encode($s));'
php bin/directory_sync.php >/dev/null 2>&1
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar28" "$APP/")" "302" "a grant the kernel revoked shuts the door within one sync"
check "$(sql "select ended_by from member_sessions where member_id = 28 order by created_at desc limit 1") $(sql "select capability is null from members where id = 28")" "directory t" "...the session ended by the directory and the mirror holds no grant"
check "$(curl -s -o /dev/null -w '%{http_code}' "$(HANDOFF_NO_GRANT=1 php bin/dev_handoff.php 28)")" "403" "...and a hand-off whose claims carry no grant is refused (capability)"
tok26=$(curl -s -b "$TMP/jar26" "$APP/settings/" | grep -o 'csrf-token-meta" content="[a-f0-9]*' | head -1 | sed 's/.*content="//')
check "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$TMP/jar26" -X POST -d "csrf_token=$tok26" "$APP/logout.php")" "302 https://app.example.invalid/launcher" "Knowledge's own sign-out (POST + CSRF) goes back to the launcher"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar26" "$APP/")" "302" "...and the session is over"

echo "== $pass passed, $fail failed"
[ "$fail" -eq 0 ]
