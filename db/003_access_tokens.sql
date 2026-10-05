-- 003: the application's own bearer tokens for its two read MCP servers and its token API
-- (mcp-and-api.md §1). Raw form `mcp_` + 48 hex chars, shown once; only the hash is stored.
-- A kernel agent never holds one of these: it presents the tenant's signed run token, which the
-- servers verify with ACTION_TOKEN_KEY (the same key, copied into config/.env by the installer).
-- A person's own token (scope mcp) is READ-ONLY (design §6.3: nothing automated writes here but a hired agent through the
-- kernel's Actions MCP).
--
-- THE ESTATE'S DATA MODEL (design §6.4): the contract table, Consultant Tracking db/003 verbatim, APPENDING the bound-token
-- columns (design §4, D12) — proposed to the catalogue: a token of scope `api` is a KEY bound to ONE base (and optionally one
-- focus area), rate-limited per minute and per day, and rotation keeps the old key 24 h with `rotated_from` naming the new
-- one's origin. The foreign keys to knowledge_bases and focus_areas are added when those tables exist (db/006, db/008).
BEGIN;

CREATE TABLE mcp_access_tokens (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id     bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    label         text NOT NULL,
    token_hash    text NOT NULL UNIQUE,            -- sha256(raw)
    scope         text NOT NULL DEFAULT 'mcp' CHECK (scope IN ('mcp', 'api')),
    last_used_at  timestamptz,
    expires_at    timestamptz,
    revoked_at    timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    -- appended (design §4, §6.3 "Keys"): the bound token
    base_id          bigint,                                         -- the ONE base a key reaches (FK in db/006)
    focus_area_id    bigint,                                         -- optionally, the one area it asks (FK in db/008)
    rate_per_minute  integer CHECK (rate_per_minute IS NULL OR rate_per_minute BETWEEN 1 AND 100000),   -- NULL on a key = kn_settings default (filled by trigger, db/011)
    rate_per_day     integer CHECK (rate_per_day IS NULL OR rate_per_day BETWEEN 1 AND 10000000),
    rotated_from     bigint REFERENCES mcp_access_tokens(id) ON DELETE SET NULL,   -- the key this one replaced (the old one keeps 24 h)
    -- a key is always bound to a base; a person's mcp token never is
    CHECK ((scope = 'api') = (base_id IS NOT NULL)),
    CHECK (scope = 'api' OR (focus_area_id IS NULL AND rate_per_minute IS NULL AND rate_per_day IS NULL AND rotated_from IS NULL))
);
CREATE INDEX mcp_access_tokens_member_idx ON mcp_access_tokens (member_id);
CREATE INDEX mcp_access_tokens_base_idx ON mcp_access_tokens (base_id) WHERE base_id IS NOT NULL;
CREATE INDEX mcp_access_tokens_rotated_idx ON mcp_access_tokens (rotated_from) WHERE rotated_from IS NOT NULL;
CREATE TRIGGER mcp_access_tokens_touch BEFORE UPDATE ON mcp_access_tokens FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
GRANT SELECT, INSERT, UPDATE ON mcp_access_tokens TO knowledge_rw;

-- The read roles have no grant on the table: this function is their only way to resolve a token.
CREATE OR REPLACE FUNCTION mcp_resolve_token(p_hash text, p_scope text DEFAULT 'mcp')
    RETURNS TABLE (member_id bigint, member_role text, member_kind text)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE mcp_access_tokens t
       SET last_used_at = now()
      FROM members m
     WHERE t.token_hash = p_hash AND t.scope = p_scope
       AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
       AND m.id = t.member_id AND m.status = 'active' AND m.capability IS NOT NULL;
    RETURN QUERY
        SELECT t.member_id, m.business_role, m.member_kind
          FROM mcp_access_tokens t JOIN members m ON m.id = t.member_id
         WHERE t.token_hash = p_hash AND t.scope = p_scope
           AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
           AND m.status = 'active' AND m.capability IS NOT NULL;
END$$;
GRANT EXECUTE ON FUNCTION mcp_resolve_token(text, text) TO knowledge_rw, knowledge_records_ro, knowledge_activity_ro;

COMMIT;
