-- 011: KEYS — a token of scope `api` bound to one base — their COUNTERS and the rate rule (design §4, §6.3; D12).
--
-- A key is an mcp_access_tokens row (db/003) with base_id set. The referee's rules: a key reaches ITS base and no other
-- (kn_key_reaches_base); its area, if any, is an area of that base; its limits default from the settings; kn_rate_ok(token)
-- counts the call into the minute and the day buckets ATOMICALLY (one upsert each, under the row lock) and refuses the 31st
-- call in a minute (or the 2,001st in a day) — a refused call is not charged to the day; rotation (kn_key_rotate) mints the
-- new key and gives the old one 24 hours (the setting) before it expires. A key's raw form is `mcp_` + 48 hex, shown once;
-- only sha256 is stored; the hash never leaves the table (no view selects it).
BEGIN;

CREATE TABLE key_usage (
    token_id      bigint NOT NULL REFERENCES mcp_access_tokens(id) ON DELETE CASCADE,
    bucket_kind   text NOT NULL CHECK (bucket_kind IN ('minute', 'day')),
    bucket_start  timestamptz NOT NULL,
    calls         integer NOT NULL DEFAULT 0 CHECK (calls >= 0),
    refused       integer NOT NULL DEFAULT 0 CHECK (refused >= 0),
    PRIMARY KEY (token_id, bucket_kind, bucket_start)
);
CREATE INDEX key_usage_time_idx ON key_usage (bucket_kind, bucket_start);

-- A key's defaults and its area's base.
CREATE OR REPLACE FUNCTION kn_access_tokens_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.scope = 'api' THEN
        NEW.rate_per_minute := COALESCE(NEW.rate_per_minute, kn_setting_int('key_rate_per_minute')::integer);
        NEW.rate_per_day := COALESCE(NEW.rate_per_day, kn_setting_int('key_rate_per_day')::integer);
        IF NEW.focus_area_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM focus_areas a WHERE a.id = NEW.focus_area_id AND a.base_id = NEW.base_id) THEN
            RAISE EXCEPTION 'A key''s area is an area of its base' USING ERRCODE = 'check_violation';
        END IF;
        IF TG_OP = 'UPDATE' AND NEW.base_id <> OLD.base_id THEN
            RAISE EXCEPTION 'A key is bound to one base for life' USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.rotated_from IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mcp_access_tokens o WHERE o.id = NEW.rotated_from AND o.base_id = NEW.base_id) THEN
            RAISE EXCEPTION 'A key is rotated from a key of the same base' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER mcp_access_tokens_before BEFORE INSERT OR UPDATE ON mcp_access_tokens FOR EACH ROW EXECUTE FUNCTION kn_access_tokens_before();

-- Is this key live and bound to this base? (The token API's gate, after the hash resolved.)
CREATE OR REPLACE FUNCTION kn_key_reaches_base(p_token_id bigint, p_base_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 FROM mcp_access_tokens t JOIN knowledge_bases b ON b.id = t.base_id
         WHERE t.id = p_token_id AND t.scope = 'api' AND t.base_id = p_base_id
           AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
           AND b.archived_at IS NULL);
END$$;

-- Resolve a key by its hash: the token, its base, its area and its limits, with last_used_at touched. Nothing for a revoked,
-- expired or mcp-scope token, or one whose minter is no longer admitted (a revoked grant shuts this door too).
CREATE OR REPLACE FUNCTION kn_resolve_key(p_hash text)
    RETURNS TABLE (token_id bigint, member_id bigint, base_id bigint, focus_area_id bigint, label text, rate_per_minute integer, rate_per_day integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE mcp_access_tokens t SET last_used_at = now()
      FROM members m
     WHERE t.token_hash = p_hash AND t.scope = 'api' AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
       AND m.id = t.member_id AND m.status = 'active' AND m.capability IS NOT NULL;
    RETURN QUERY
        SELECT t.id, t.member_id, t.base_id, t.focus_area_id, t.label, t.rate_per_minute, t.rate_per_day
          FROM mcp_access_tokens t JOIN members m ON m.id = t.member_id JOIN knowledge_bases b ON b.id = t.base_id
         WHERE t.token_hash = p_hash AND t.scope = 'api' AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
           AND m.status = 'active' AND m.capability IS NOT NULL AND b.archived_at IS NULL;
END$$;

-- Count one call. ok = the call may proceed; when not, limit_hit says which bucket and retry_after how many seconds until it
-- opens. The minute bucket is charged first; a call the minute refuses is not charged to the day (so a burst never eats the
-- day); a call the day refuses stays counted in the minute (it was made). `refused` is kept per bucket for the keys screen.
CREATE OR REPLACE FUNCTION kn_rate_ok(p_token_id bigint, p_at timestamptz DEFAULT now())
    RETURNS TABLE (ok boolean, limit_hit text, retry_after integer, minute_calls integer, day_calls integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t mcp_access_tokens%ROWTYPE; m_start timestamptz; d_start timestamptz; m_calls integer; d_calls integer;
BEGIN
    SELECT * INTO t FROM mcp_access_tokens WHERE id = p_token_id AND scope = 'api' FOR UPDATE;   -- serialises the two upserts per key
    IF NOT FOUND THEN
        RETURN QUERY SELECT false, 'no_key'::text, 0, 0, 0; RETURN;
    END IF;
    IF t.revoked_at IS NOT NULL OR (t.expires_at IS NOT NULL AND t.expires_at <= p_at) THEN
        RETURN QUERY SELECT false, 'revoked'::text, 0, 0, 0; RETURN;
    END IF;
    m_start := date_trunc('minute', p_at);
    d_start := date_trunc('day', p_at);
    INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES (p_token_id, 'minute', m_start, 1)
    ON CONFLICT (token_id, bucket_kind, bucket_start) DO UPDATE SET calls = key_usage.calls + 1
    RETURNING calls INTO m_calls;
    IF m_calls > t.rate_per_minute THEN
        UPDATE key_usage SET calls = calls - 1, refused = refused + 1 WHERE token_id = p_token_id AND bucket_kind = 'minute' AND bucket_start = m_start;
        SELECT COALESCE(calls, 0) INTO d_calls FROM key_usage WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = d_start;
        RETURN QUERY SELECT false, 'minute'::text, GREATEST(1, ceil(extract(epoch FROM (m_start + interval '1 minute' - p_at)))::integer), m_calls - 1, COALESCE(d_calls, 0);
        RETURN;
    END IF;
    INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES (p_token_id, 'day', d_start, 1)
    ON CONFLICT (token_id, bucket_kind, bucket_start) DO UPDATE SET calls = key_usage.calls + 1
    RETURNING calls INTO d_calls;
    IF d_calls > t.rate_per_day THEN
        UPDATE key_usage SET calls = calls - 1, refused = refused + 1 WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = d_start;
        RETURN QUERY SELECT false, 'day'::text, GREATEST(1, ceil(extract(epoch FROM (d_start + interval '1 day' - p_at)))::integer), m_calls, d_calls - 1;
        RETURN;
    END IF;
    RETURN QUERY SELECT true, NULL::text, 0, m_calls, d_calls;
END$$;

-- Today's and this minute's count for a key (the keys screen, find_keys).
CREATE OR REPLACE FUNCTION kn_key_calls_today(p_token_id bigint) RETURNS integer
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE((SELECT calls FROM key_usage WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = date_trunc('day', now())), 0);
$$;

-- Rotate: a new key for the same base, area and limits, under the same minter and label; the old one expires after the
-- overlap (D12: 24 h) and points nowhere — the new one says `rotated_from`. Returns the new row's id; the raw key is the
-- caller's (PHP mints the 48 hex, hashes them, and passes the hash in).
CREATE OR REPLACE FUNCTION kn_key_rotate(p_old_token_id bigint, p_new_hash text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o mcp_access_tokens%ROWTYPE; nid bigint; overlap integer;
BEGIN
    SELECT * INTO o FROM mcp_access_tokens WHERE id = p_old_token_id AND scope = 'api' FOR UPDATE;
    IF NOT FOUND OR o.revoked_at IS NOT NULL THEN RAISE EXCEPTION 'Only a live key is rotated' USING ERRCODE = 'check_violation'; END IF;
    overlap := kn_setting_int('key_rotation_overlap_hours')::integer;
    INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, base_id, focus_area_id, rate_per_minute, rate_per_day, rotated_from)
    VALUES (o.member_id, o.label, p_new_hash, 'api', o.base_id, o.focus_area_id, o.rate_per_minute, o.rate_per_day, o.id)
    RETURNING id INTO nid;
    UPDATE mcp_access_tokens SET expires_at = LEAST(COALESCE(expires_at, 'infinity'::timestamptz), now() + make_interval(hours => overlap)) WHERE id = o.id;
    RETURN nid;
END$$;

-- The worker prunes buckets older than two days once a minute.
CREATE OR REPLACE FUNCTION kn_prune_key_usage() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    DELETE FROM key_usage WHERE (bucket_kind = 'minute' AND bucket_start < now() - interval '1 day') OR (bucket_kind = 'day' AND bucket_start < now() - interval '35 days');
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON key_usage TO knowledge_rw;
GRANT EXECUTE ON FUNCTION kn_key_reaches_base(bigint, bigint), kn_key_calls_today(bigint) TO knowledge_rw, knowledge_records_ro, knowledge_activity_ro;
REVOKE ALL ON FUNCTION kn_resolve_key(text), kn_rate_ok(bigint, timestamptz), kn_key_rotate(bigint, text), kn_prune_key_usage() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION kn_resolve_key(text), kn_rate_ok(bigint, timestamptz), kn_key_rotate(bigint, text), kn_prune_key_usage() TO knowledge_rw;

COMMIT;
