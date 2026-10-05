-- 009: BUILDS — one extraction run over a base's new and stale sections — and their ITEMS, one per section (design §6.2; D9).
--
-- The referee's rules: ONE build per base at a time (a partial unique index on queued | running); a build records the
-- guideline_version it builds under; an item names a section OF THE BUILD'S BASE that is not forgotten; a finished item
-- decides its section — done → extracted under that version, failed → failed with the error — so the worker never sets a
-- section's status by hand; the build's counters follow its items once per statement; a build's status moves forward only
-- (queued → running → done | failed | cancelled; queued → cancelled); the base's status follows (building while one runs,
-- then ready or needs_rebuild). `rebuild_all` is the same run over every section — a person's, paused for an agent (§5).
-- New tables — nothing in the estate is an extraction run.
BEGIN;

CREATE TABLE builds (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    base_id            bigint NOT NULL REFERENCES knowledge_bases(id) ON DELETE CASCADE,
    kind               text NOT NULL DEFAULT 'extract' CHECK (kind IN ('extract', 'rebuild_all', 'reindex', 'embed', 'forget')),
    status             text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed', 'cancelled')),
    guideline_version  integer NOT NULL DEFAULT 1,                              -- the base's at the moment it was queued
    started_by         bigint REFERENCES members(id) ON DELETE SET NULL,        -- NULL = the worker (auto_extract)
    requested_at       timestamptz NOT NULL DEFAULT now(),
    started_at         timestamptz,
    finished_at        timestamptz,
    items_total        integer NOT NULL DEFAULT 0 CHECK (items_total >= 0),
    items_done         integer NOT NULL DEFAULT 0 CHECK (items_done >= 0),
    items_failed       integer NOT NULL DEFAULT 0 CHECK (items_failed >= 0),
    chars_processed    bigint NOT NULL DEFAULT 0 CHECK (chars_processed >= 0),
    subjects_added     integer NOT NULL DEFAULT 0,
    statements_added   integer NOT NULL DEFAULT 0,
    usage              jsonb NOT NULL DEFAULT '{}',                             -- the engine's usage, summed
    log                text,
    error              text,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    CHECK (finished_at IS NULL OR started_at IS NULL OR finished_at >= started_at),
    CHECK ((status IN ('done', 'failed', 'cancelled')) = (finished_at IS NOT NULL))
);
CREATE UNIQUE INDEX builds_one_live_per_base ON builds (base_id) WHERE status IN ('queued', 'running');
CREATE INDEX builds_base_idx ON builds (base_id, requested_at DESC);
CREATE INDEX builds_queue_idx ON builds (requested_at) WHERE status = 'queued';
CREATE INDEX builds_started_by_idx ON builds (started_by);
CREATE TRIGGER builds_touch BEFORE UPDATE ON builds FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE build_items (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    build_id     bigint NOT NULL REFERENCES builds(id) ON DELETE CASCADE,
    section_id   bigint NOT NULL REFERENCES sections(id) ON DELETE CASCADE,
    status       text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed', 'skipped')),
    chars        integer NOT NULL DEFAULT 0 CHECK (chars >= 0),
    started_at   timestamptz,
    finished_at  timestamptz,
    error        text,
    usage        jsonb NOT NULL DEFAULT '{}',
    UNIQUE (build_id, section_id)
);
CREATE INDEX build_items_build_idx ON build_items (build_id, status);
CREATE INDEX build_items_section_idx ON build_items (section_id);

CREATE OR REPLACE FUNCTION kn_builds_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF EXISTS (SELECT 1 FROM knowledge_bases b WHERE b.id = NEW.base_id AND b.archived_at IS NOT NULL) THEN
            RAISE EXCEPTION 'An archived base is not built' USING ERRCODE = 'check_violation';
        END IF;
        IF EXISTS (SELECT 1 FROM builds x WHERE x.base_id = NEW.base_id AND x.status IN ('queued', 'running')) THEN
            RAISE EXCEPTION 'One build per base at a time: a build is already queued or running' USING ERRCODE = 'unique_violation';
        END IF;
        NEW.status := 'queued';
        NEW.started_at := NULL; NEW.finished_at := NULL;
        SELECT guideline_version INTO NEW.guideline_version FROM knowledge_bases WHERE id = NEW.base_id;
        NEW.started_by := COALESCE(NEW.started_by, app_current_member_id());
        RETURN NEW;
    END IF;
    IF NEW.base_id <> OLD.base_id OR NEW.kind <> OLD.kind THEN
        RAISE EXCEPTION 'A build''s base and kind never change' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.status <> OLD.status THEN
        IF OLD.status IN ('done', 'failed', 'cancelled') THEN
            RAISE EXCEPTION 'A finished build stays %', OLD.status USING ERRCODE = 'check_violation';
        END IF;
        IF OLD.status = 'queued' AND NEW.status NOT IN ('running', 'cancelled') THEN
            RAISE EXCEPTION 'A queued build starts or is cancelled' USING ERRCODE = 'check_violation';
        END IF;
        IF OLD.status = 'running' AND NEW.status NOT IN ('done', 'failed', 'cancelled') THEN
            RAISE EXCEPTION 'A running build finishes, fails or is cancelled' USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.status = 'running' THEN NEW.started_at := COALESCE(NEW.started_at, now()); END IF;
        IF NEW.status IN ('done', 'failed', 'cancelled') THEN
            NEW.started_at := COALESCE(NEW.started_at, now());
            NEW.finished_at := COALESCE(NEW.finished_at, now());
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER builds_before BEFORE INSERT OR UPDATE ON builds FOR EACH ROW EXECUTE FUNCTION kn_builds_before();

CREATE OR REPLACE FUNCTION kn_builds_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.status = 'done' AND OLD.status <> 'done' AND NEW.kind IN ('extract', 'rebuild_all') THEN
        -- items still queued or running when the build is declared done were not sent: they are skipped, their sections stay as they were
        UPDATE build_items SET status = 'skipped', finished_at = now() WHERE build_id = NEW.id AND status IN ('queued', 'running');
        UPDATE knowledge_bases SET last_built_at = NEW.finished_at WHERE id = NEW.base_id;
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.status IN ('failed', 'cancelled') AND OLD.status NOT IN ('failed', 'cancelled') THEN
        UPDATE build_items SET status = 'skipped', finished_at = now() WHERE build_id = NEW.id AND status IN ('queued', 'running');
    END IF;
    PERFORM kn_base_recompute_status(NEW.base_id);
    RETURN NULL;
END$$;
CREATE TRIGGER builds_after AFTER INSERT OR UPDATE OF status ON builds FOR EACH ROW EXECUTE FUNCTION kn_builds_after();

-- An item names a live section of the build's base, on a build that is not finished; its chars default to the section's.
CREATE OR REPLACE FUNCTION kn_build_items_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b builds%ROWTYPE; s sections%ROWTYPE;
BEGIN
    SELECT * INTO b FROM builds WHERE id = NEW.build_id;
    SELECT * INTO s FROM sections WHERE id = NEW.section_id;
    IF TG_OP = 'INSERT' THEN
        IF b.status NOT IN ('queued', 'running') THEN RAISE EXCEPTION 'A finished build takes no items' USING ERRCODE = 'check_violation'; END IF;
        IF s.base_id <> b.base_id THEN RAISE EXCEPTION 'A build item names a section of the build''s base' USING ERRCODE = 'check_violation'; END IF;
        IF s.status = 'forgotten' THEN RAISE EXCEPTION 'A forgotten section is not built' USING ERRCODE = 'check_violation'; END IF;
        IF NEW.chars = 0 THEN NEW.chars := length(s.text); END IF;
    ELSE
        IF NEW.build_id <> OLD.build_id OR NEW.section_id <> OLD.section_id THEN RAISE EXCEPTION 'A build item never moves' USING ERRCODE = 'check_violation'; END IF;
        IF OLD.status IN ('done', 'failed', 'skipped') AND NEW.status <> OLD.status THEN
            RAISE EXCEPTION 'A finished item stays %', OLD.status USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.status = 'running' AND OLD.status <> 'running' THEN NEW.started_at := COALESCE(NEW.started_at, now()); END IF;
        IF NEW.status IN ('done', 'failed', 'skipped') AND OLD.status <> NEW.status THEN NEW.finished_at := COALESCE(NEW.finished_at, now()); END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER build_items_before BEFORE INSERT OR UPDATE ON build_items FOR EACH ROW EXECUTE FUNCTION kn_build_items_before();

-- A finished item decides its section (extract and rebuild_all builds; a reindex, embed or forget run leaves sections alone).
CREATE OR REPLACE FUNCTION kn_build_items_after_row() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b builds%ROWTYPE;
BEGIN
    IF NEW.status = OLD.status OR NEW.status NOT IN ('done', 'failed') THEN RETURN NULL; END IF;
    SELECT * INTO b FROM builds WHERE id = NEW.build_id;
    IF b.kind NOT IN ('extract', 'rebuild_all') THEN RETURN NULL; END IF;
    IF NEW.status = 'done' THEN
        UPDATE sections SET status = 'extracted', extracted_version = b.guideline_version, extracted_at = COALESCE(NEW.finished_at, now()), error = NULL
         WHERE id = NEW.section_id AND status <> 'forgotten';
    ELSE
        UPDATE sections SET status = 'failed', error = left(NEW.error, 1000) WHERE id = NEW.section_id AND status <> 'forgotten';
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER build_items_after_row AFTER UPDATE OF status ON build_items FOR EACH ROW EXECUTE FUNCTION kn_build_items_after_row();

-- The build's counters follow its items, once per statement.
CREATE OR REPLACE FUNCTION kn_build_items_recount(p_build_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE builds b SET
        items_total = c.total, items_done = c.done, items_failed = c.failed, chars_processed = c.chars
      FROM (SELECT count(*) AS total, count(*) FILTER (WHERE status = 'done') AS done, count(*) FILTER (WHERE status = 'failed') AS failed,
                   COALESCE(sum(chars) FILTER (WHERE status = 'done'), 0) AS chars
              FROM build_items WHERE build_id = p_build_id) c
     WHERE b.id = p_build_id;
END$$;
CREATE OR REPLACE FUNCTION kn_build_items_after_ins() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record;
BEGIN
    FOR r IN SELECT DISTINCT build_id FROM new_rows LOOP PERFORM kn_build_items_recount(r.build_id); END LOOP;
    RETURN NULL;
END$$;
CREATE OR REPLACE FUNCTION kn_build_items_after_del() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record;
BEGIN
    FOR r IN SELECT DISTINCT build_id FROM old_rows LOOP PERFORM kn_build_items_recount(r.build_id); END LOOP;
    RETURN NULL;
END$$;
CREATE TRIGGER build_items_after_insert AFTER INSERT ON build_items REFERENCING NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION kn_build_items_after_ins();
CREATE TRIGGER build_items_after_update AFTER UPDATE ON build_items REFERENCING NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION kn_build_items_after_ins();
CREATE TRIGGER build_items_after_delete AFTER DELETE ON build_items REFERENCING OLD TABLE AS old_rows FOR EACH STATEMENT EXECUTE FUNCTION kn_build_items_after_del();

-- What a build would take now: the base's queued and stale sections (every live one for rebuild_all), as items.
CREATE OR REPLACE FUNCTION kn_build_queue_items(p_build_id bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b builds%ROWTYPE; n integer;
BEGIN
    SELECT * INTO b FROM builds WHERE id = p_build_id;
    IF NOT FOUND OR b.status <> 'queued' THEN RAISE EXCEPTION 'Items are queued on a queued build' USING ERRCODE = 'check_violation'; END IF;
    INSERT INTO build_items (build_id, section_id, chars)
    SELECT b.id, s.id, length(s.text) FROM sections s
     WHERE s.base_id = b.base_id AND s.status <> 'forgotten'
       AND (b.kind = 'rebuild_all' OR s.status IN ('queued', 'stale', 'failed'))
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON builds, build_items TO knowledge_rw;
REVOKE ALL ON FUNCTION kn_build_items_recount(bigint), kn_build_queue_items(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION kn_build_items_recount(bigint), kn_build_queue_items(bigint) TO knowledge_rw;

COMMIT;
