-- 002: activity memory -- the one funnel (memory.md §2). Exists before the first feature ships; it cannot be
-- backfilled. Every row is shipped to the tenant's one MaluDB as an `activity` episode by mcp/activity_ingest.py
-- (payload key "application": "knowledge" first). A RECORD'S HISTORY IS THIS TABLE (design §6.4): there is no audit table;
-- `before`/`after` carry what the timeline shows: ids, titles, counts, a kind, a status, 120 characters of a question at
-- most. A SOURCE'S TEXT, A SECTION'S TEXT AND A QUESTION'S FULL TEXT ARE NEVER HERE (D14); a key is never logged.
--
-- THE ESTATE'S DATA MODEL (design §6.4): the contract table (memory.md / Consultant Tracking db/002) with Knowledge's audit
-- key APPENDED: base_id — every event about a base, a source, a section, a guideline, an area, a build, a question or a key
-- carries the base it concerns, so a base's history and the mcp_activity_log reach rule are one index. Consultant Tracking's
-- own appended keys (actor_client_id, engagement_id, client_id, invoice_id, timesheet_id) are its domain, not the contract,
-- and are not copied.
BEGIN;

CREATE TABLE activity_log (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    occurred_at     timestamptz NOT NULL DEFAULT now(),
    actor_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    source          text NOT NULL DEFAULT 'web'
                    CHECK (source IN ('web', 'assistant', 'mcp', 'cron', 'agent', 'desk', 'webhook', 'portal', 'api', 'application')),
    action          text NOT NULL,                 -- entity.verb
    screen          text,
    route           text,                          -- "METHOD /path"
    entity_type     text,
    entity_id       bigint,
    before          jsonb,
    after           jsonb,                         -- changed fields only
    request_id      text,
    session_id      text,
    ip_address      inet,
    agent_run_id    bigint,                        -- the kernel's run id when an agent acted (no FK)
    department_id   bigint,                        -- the department the event concerns (a share to it)
    location_id     bigint,                        -- the contract's (unused: scope none)
    kernel_request_id text,                        -- the kernel's request id for a call that crossed to it
    created_at      timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.4): Knowledge's audit key (no FK — history outlives a record)
    base_id         bigint,                        -- the knowledge base the event concerns
    token_id        bigint                         -- the key (mcp_access_tokens.id) a token-API event ran under; its LABEL is in `after`, never the key
);
CREATE INDEX activity_log_actor_idx   ON activity_log (actor_member_id, occurred_at DESC);
CREATE INDEX activity_log_entity_idx  ON activity_log (entity_type, entity_id, occurred_at DESC);
CREATE INDEX activity_log_action_idx  ON activity_log (action, occurred_at DESC);
CREATE INDEX activity_log_time_idx    ON activity_log (occurred_at DESC);
CREATE INDEX activity_log_request_idx ON activity_log (request_id);
CREATE INDEX activity_log_base_idx    ON activity_log (base_id, occurred_at DESC);
CREATE INDEX activity_log_token_idx   ON activity_log (token_id, occurred_at DESC) WHERE token_id IS NOT NULL;

GRANT INSERT, SELECT ON activity_log TO knowledge_rw;
REVOKE ALL ON activity_log FROM knowledge_records_ro, knowledge_activity_ro;

-- The ingest checkpoint: only ever moves forward.
CREATE TABLE activity_ingest_state (
    id          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    last_id     bigint NOT NULL DEFAULT 0,
    updated_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO activity_ingest_state (id, last_id) VALUES (1, 0) ON CONFLICT DO NOTHING;
GRANT SELECT, UPDATE ON activity_ingest_state TO knowledge_rw;

COMMIT;
