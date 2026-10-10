#!/usr/bin/env bash
# Knowledge — what root or the owner does to install it beside the kernel, in order. Written at the end of Phase 0 (2026-10-05);
# later phases ADD steps and mark them. NOTHING HERE HAS BEEN RUN: the proofs use a scratch database, php -S and fixtures, and
# never touch an installed application, Apache, systemd or the MaluDB API.
#   bash deploy/ROOT_STEPS.sh              prints this plan (the default)
#   sudo bash deploy/ROOT_STEPS.sh apply   runs the steps with no decision in them: 0, 3 and 4 (the ports, the memory database, its token)
# Steps 1, 2, 5, 6 and 7 are printed and never run: they are the installer's, the owner's, or put a secret somewhere.
set -euo pipefail
APP=/srv/apps/knowledge
KERNEL=/var/www
MODE=${1:-plan}
MEMDB=subello_knowledge_memory      # <tenant>_knowledge_memory (design section 14); change the prefix for another tenant
MEMROLE=knowledge_mem               # the schema and the login role
MALUDB_API=${MALUDB_API:-http://127.0.0.1:8000}
run() { echo "+ $*"; if [ "$MODE" = apply ]; then "$@"; fi; }
env_has() { [ -f "$APP/config/.env" ] && grep -q "^$1=." "$APP/config/.env"; }

# ------------------------------------------------------------------------------------------------------------------
# 0. PIN THE PORTS before the installer's first apply (Help Desk's lesson of 2026-10-02). A config/.env holding ONLY the three
#    ports: the installer reads them, fills every other required key itself, and sets fresh role passwords because DB_PASSWORD
#    is absent. Never put anything else in this file by hand before apply.
echo "== 0. Pin the ports (ROOT) — only if $APP/config/.env does not exist yet"
if [ ! -f "$APP/config/.env" ]; then
  run bash -c "mkdir -p '$APP/config' && printf 'APP_INTERNAL_PORT=8187\nMCP_RECORDS_PORT=8835\nMCP_ACTIVITY_PORT=8836\n' > '$APP/config/.env' && chgrp www-data '$APP/config/.env' && chmod 640 '$APP/config/.env'"
else
  echo "   (config/.env exists — leaving it alone)"
fi
#    See that it worked:  php $KERNEL/bin/app_install.php plan $APP --domain subello.com | grep -E '^(todo|done) +(ports|vhost)'   -> ports done, no {{...}} unfilled

# ------------------------------------------------------------------------------------------------------------------
# 1. Look (read-only, any user): what the installer would do.
echo "== 1. The installer's plan (read-only)"
echo "   php $KERNEL/bin/app_install.php plan $APP --by <super-admin email> --domain subello.com"

# ------------------------------------------------------------------------------------------------------------------
# 2. The installer's apply (ROOT) — PHASE 2 onward, not before the shell exists: database and roles, config/.env, the vhost,
#    the units, the registration (catalog kind ours — the row K25 seeded, db/170 —, /sso and /sso/logout, scope_kind none),
#    the application token, skills imported, approval policies, the installing super-admin's admin grant.
#    --grant-standing-departments gives every standing department the Member role (key user). --hire-agents hires the expert
#    and the Archivist. Run it twice: the roles are read from app_roles only once the records MCP answers (Phase 4).
echo "== 2. apply (ROOT) — from Phase 2"
echo "   sudo php $KERNEL/bin/app_install.php apply $APP --by <super-admin email> --domain subello.com --grant-standing-departments --hire-agents"

# ------------------------------------------------------------------------------------------------------------------
# 3. THE MEMORY DATABASE (ROOT, any time; idempotent). The `maludb` database is only the extension's bootstrap — never a
#    tenant. The graph of every base lives in a memory database of its own, one namespace per base (kb:<id>), beside the
#    tenant's agent memory (certstudy_memory). Same recipe as the kernel's own: MaluDB README section 2 — a database with
#    maludb_core, a login role and a schema of one name, enable_memory_schema, grant_memory_access.
#    The role's password is generated once into a root-only file, used to mint the token in step 4, and never printed.
echo "== 3. The memory database $MEMDB (ROOT)"
PW_FILE="$APP/config/knowledge_mem.pw"
psql_pg() { sudo -u postgres psql -v ON_ERROR_STOP=1 -X -q "$@"; }
if [ "$MODE" = apply ]; then
  if ! sudo -u postgres psql -X -tAc "SELECT 1 FROM pg_database WHERE datname='$MEMDB'" | grep -q 1; then
    echo "+ createdb $MEMDB"; sudo -u postgres createdb "$MEMDB"
  else echo "   ($MEMDB exists)"; fi
  if [ ! -s "$PW_FILE" ]; then
    echo "+ generate the role password into $PW_FILE (root only, never printed)"
    ( umask 077; mkdir -p "$APP/config"; openssl rand -hex 24 > "$PW_FILE" ); chown root:root "$PW_FILE"; chmod 600 "$PW_FILE"
  fi
  PW=$(cat "$PW_FILE")
  psql_pg -d "$MEMDB" -c "CREATE EXTENSION IF NOT EXISTS maludb_core CASCADE"
  psql_pg -d postgres -v pw="$PW" <<SQL
SELECT format('CREATE ROLE %I LOGIN', '$MEMROLE') WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '$MEMROLE') \gexec
ALTER ROLE $MEMROLE PASSWORD :'pw';
SQL
  psql_pg -d "$MEMDB" <<SQL
CREATE SCHEMA IF NOT EXISTS $MEMROLE AUTHORIZATION $MEMROLE;
SELECT * FROM maludb_core.enable_memory_schema('$MEMROLE');
SELECT * FROM maludb_core.grant_memory_access('$MEMROLE', 'write');
-- found by the engine proof: without this the namespace's provider secret cannot be read and every model-calling route answers 409 model_not_configured
GRANT maludb_secret_consumer TO $MEMROLE;
SQL
  unset PW
else
  echo "+ createdb $MEMDB   (if absent)"
  echo "+ role $MEMROLE with a generated password in $PW_FILE (root-only)"
  echo "+ CREATE EXTENSION IF NOT EXISTS maludb_core CASCADE   (in $MEMDB)"
  echo "+ CREATE SCHEMA $MEMROLE AUTHORIZATION $MEMROLE; SELECT maludb_core.enable_memory_schema('$MEMROLE'); SELECT maludb_core.grant_memory_access('$MEMROLE','write'); GRANT maludb_secret_consumer TO $MEMROLE"
fi

# ------------------------------------------------------------------------------------------------------------------
# 4. ITS API TOKEN (ROOT; after steps 0 and 3, and after the MaluDB API is up). Minted by proving the role's login
#    (POST /v1/tokens), written to config/.env as MALUDB_KB_API_URL and MALUDB_KB_API_TOKEN, read in ONE file of the application
#    (app/knowledge/engine.php). Skipped when the token is already there. The token is never echoed.
echo "== 4. Mint the memory database's API token into config/.env (ROOT)"
if [ "$MODE" = apply ]; then
  if env_has MALUDB_KB_API_TOKEN; then
    echo "   (MALUDB_KB_API_TOKEN already set — leaving it alone)"
  else
    [ -s "$PW_FILE" ] || { echo "   run step 3 first ($PW_FILE missing)"; exit 1; }
    TOKEN=$(python3 - "$MALUDB_API" "$MEMDB" "$MEMROLE" "$PW_FILE" <<'PY'
import json, sys, urllib.request
api, db, role, pwf = sys.argv[1:5]
body = json.dumps({"pg_dbname": db, "pg_user": role, "pg_password": open(pwf).read().strip(), "label": "Knowledge (application)"}).encode()
req = urllib.request.Request(api.rstrip("/") + "/v1/tokens", data=body, headers={"Content-Type": "application/json"})
r = json.load(urllib.request.urlopen(req, timeout=10))
print(r.get("token") or r.get("access_token") or "")
PY
)
    [ -n "$TOKEN" ] || { echo "   the API gave no token (is maludb-api up on $MALUDB_API?)"; exit 1; }
    { echo "MALUDB_KB_API_URL=$MALUDB_API"; echo "MALUDB_KB_API_TOKEN=$TOKEN"; } >> "$APP/config/.env"
    unset TOKEN
    chgrp www-data "$APP/config/.env"; chmod 640 "$APP/config/.env"
    echo "   token written to $APP/config/.env (not shown)"
  fi
else
  echo "+ POST $MALUDB_API/v1/tokens {pg_dbname:$MEMDB, pg_user:$MEMROLE, pg_password:<from $PW_FILE>} -> MALUDB_KB_API_URL, MALUDB_KB_API_TOKEN in config/.env"
fi

# ------------------------------------------------------------------------------------------------------------------
# 5. MANUAL — the engine's model calls on the AI statement (K24). NOT automated: a secret is never put anywhere by a script.
#    (a) Every model the engine will call must be registered, with a price, in the kernel's model registry (an embedding model
#        too; api_key models only). Name the registry keys: the extraction/chat model, the embedding model, the answer model.
#    (b) Mint the application's credential (printed ONCE, only its hash is kept; minting again revokes the last):
echo "== 5. MANUAL — K24: the engine's credential at the ledger proxy"
echo "   php $KERNEL/bin/application_model_credential.php mint --app knowledge --models <chat-model-key>,<embedding-model-key>,<answer-model-key> [--budget <monthly dollars>]"
#    (c) The printed appm_ credential becomes the engine's PROVIDER API KEY, and the provider base URL becomes
#        http://127.0.0.1:8816/openai (or http://127.0.0.1:8816/anthropic for an Anthropic model). Set them with
#        POST /v1/memory/config against the memory database (bearer = MALUDB_KB_API_TOKEN) for the REFERENCE namespace "kb:0"
#        (slice specs: each base's own namespace copies the connection from kb:0), by hand or from the admin's model screen
#        once built; the engine's own docs (docs/memory-answer.md and the config route) name the fields.
echo "   then: the appm_ credential is the engine's provider API key; the provider base URL is http://127.0.0.1:8816/openai (or /anthropic);"
echo "         set with POST /v1/memory/config (namespace kb:0) against $MEMDB. Put the credential nowhere else."
#    (d) The runner must be restarted once to serve the credential route:
echo "   sudo systemctl restart certstudy-agent-runner        # K24 — the owner's"

# ------------------------------------------------------------------------------------------------------------------
# 6. MANUAL — before answers are composed: M1 and the audit log.
echo "== 6. MANUAL — the engine's API, answers and logging"
echo "   - MaluDB API 0.4.0 (M1, the answer route) is merged and live since 2026-10-09 (PR #18)."
echo "   - MaluDB API 0.4.1 (M12, PR #19: the documents route registers an unseen subject type) is merged and live since 2026-10-10;"
echo "     an older API refuses every statement typed with a template's own type (product, policy, place …)."
echo "   - the memory database's 'answer' model: PUT /v1/llm/models/answer (bearer = MALUDB_KB_API_TOKEN). Until one is chosen the answer"
echo "     route falls back to the extract choice, then each base's namespace configuration."
echo "   - Settle pgaudit logging FIRST: the engine's calls carry the questions and the extracted text; decide what the audit log may keep"
echo "     before the first build, not after."

# ------------------------------------------------------------------------------------------------------------------
# 7. The owner's keys and decisions (any time after 2):
echo "== 7. The owner's"
echo "   - MaluMail: MALUMAIL_API_KEY, MAIL_FROM, MAIL_FROM_NAME into $APP/config/.env (the outbox sends the Monday notes and build mail)"
echo "   - text extraction tools: pdftotext (poppler-utils) on the host, KN_PDFTOTEXT if not on PATH; KN_URL_FETCH=off turns URL sources off"
echo "   - DNS and TLS for knowledge.subello.com (until then /etc/hosts maps it to this host)"
echo "   - the Archivist's duty (45 6 * * 1) in Agent HR until the installer reads agents[].duty"
echo "   - the connections a super-admin approves (bin/app_connection.php): Help Desk, Projects, Spaces and Consultant Tracking reading ask, search and bases_index;"
echo "     Knowledge reading spaces.pages_index, spaces.page_markdown and helpdesk.articles_published (HD2 owed)"
echo "   - the worker, the directory sync and the ingest timers are rendered and enabled by apply; by hand:"
echo "       sudo systemctl enable --now knowledge-worker.timer knowledge-directory-sync.timer knowledge-activity-ingest.timer"
