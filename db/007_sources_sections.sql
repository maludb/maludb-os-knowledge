-- 007: SOURCES, their TEXT and PAGES, and the SECTIONS they are cut into (design §6.1, §6.3; D6); the FILES behind them and
-- NOTES on a base, a source or a section.
--
-- THE SECTION IS THE UNIT: one heading-delimited part of a source, one MaluDB document, the thing a citation names. A source
-- is cut deterministically by PHP (docs/build-specs/sectioning.md) — never by a model, never here. What the database
-- enforces: a section belongs to a source OF THE SAME BASE; a forgotten or replaced source's sections are forgotten with it
-- (and the engine forgets them within the minute — the worker reads status forgotten with a maludb_document_id still set);
-- a forgotten source never comes back (replace it); the base's status follows its sections (kn_base_recompute_status).
--
-- THE ESTATE'S DATA MODEL (design §6.4): `sources` + `source_text` + `source_pages` + `sections` are NEW — the canonical
-- "ingested document with text anchors" from here on (Help Desk's articles and Spaces' pages are authored content, read
-- through K7 as a feed, never copied). `attachments` is General Ledger db/009 CANONICAL verbatim, record_type widened to
-- `source`. `notes` is GL db/009 = Consultant Tracking db/006, record_type widened to base | source | section.
BEGIN;

CREATE TABLE sources (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    base_id          bigint NOT NULL REFERENCES knowledge_bases(id) ON DELETE CASCADE,
    kind             text NOT NULL CHECK (kind = ANY (kn_source_kinds())),
    title            text NOT NULL CHECK (length(title) BETWEEN 1 AND 300),
    origin           text,                                                    -- the file name, the URL, 'paste', 'feed:<provider>.<tool>#<id>', 'pin:<id>'
    attachment_id    bigint,                                                  -- the file (FK below, after attachments)
    mime_type        text,
    text_sha256      text CHECK (text_sha256 IS NULL OR text_sha256 ~ '^[0-9a-f]{64}$'),
    char_count       integer NOT NULL DEFAULT 0 CHECK (char_count >= 0),
    page_count       integer NOT NULL DEFAULT 0 CHECK (page_count >= 0),
    language         text CHECK (language IS NULL OR language ~ '^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$'),
    status           text NOT NULL DEFAULT 'uploaded' CHECK (status = ANY (kn_source_statuses())),
    sections_count   integer NOT NULL DEFAULT 0 CHECK (sections_count >= 0),  -- live (non-forgotten) sections, kept by trigger
    error            text,
    added_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    added_at         timestamptz NOT NULL DEFAULT now(),
    superseded_by    bigint REFERENCES sources(id) ON DELETE SET NULL,        -- the source that replaced this one
    url_etag         text,
    url_checked_at   timestamptz,
    url_changed_at   timestamptz,                                             -- the Archivist's "the page changed or vanished"
    feed_id          bigint,                                                  -- the feed that brought it (FK in db/012)
    feed_item_key    text,                                                    -- the provider's id for the record, so a re-sync updates rather than duplicates
    forgotten_at     timestamptz,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CHECK (kind <> 'url' OR origin IS NOT NULL),
    CHECK ((status = 'forgotten') = (forgotten_at IS NOT NULL)),
    CHECK (superseded_by IS NULL OR superseded_by <> id)
);
CREATE INDEX sources_base_idx ON sources (base_id, status);
CREATE INDEX sources_base_kind_idx ON sources (base_id, kind);
CREATE INDEX sources_title_trgm_idx ON sources USING gin (title gin_trgm_ops);
CREATE INDEX sources_superseded_idx ON sources (superseded_by) WHERE superseded_by IS NOT NULL;
CREATE INDEX sources_added_by_idx ON sources (added_by);
CREATE UNIQUE INDEX sources_feed_item_live ON sources (feed_id, feed_item_key) WHERE feed_id IS NOT NULL AND status <> 'forgotten';
CREATE TRIGGER sources_touch BEFORE UPDATE ON sources FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- The extracted text, apart from the record so a list never drags it along. A source's text is never in a log payload.
CREATE TABLE source_text (
    source_id     bigint PRIMARY KEY REFERENCES sources(id) ON DELETE CASCADE,
    text          text NOT NULL,
    extractor     text,                                                       -- 'pdftotext', 'docx', 'html', 'markdown', 'paste', 'csv', 'json', 'url', 'note', 'feed'
    extracted_at  timestamptz NOT NULL DEFAULT now()
);

-- Page anchors into source_text: a citation names a page because char_from..char_to says which one.
CREATE TABLE source_pages (
    source_id   bigint NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
    page_no     integer NOT NULL CHECK (page_no >= 1),
    char_from   integer NOT NULL CHECK (char_from >= 0),
    char_to     integer NOT NULL,
    PRIMARY KEY (source_id, page_no),
    CHECK (char_to >= char_from)
);

CREATE TABLE sections (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    base_id            bigint NOT NULL REFERENCES knowledge_bases(id) ON DELETE CASCADE,
    source_id          bigint NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
    heading_path       text NOT NULL,                                         -- "Handbook › Leave › Carry-over"
    heading            text NOT NULL DEFAULT '',
    level              smallint NOT NULL DEFAULT 1 CHECK (level BETWEEN 0 AND 9),
    parent_id          bigint REFERENCES sections(id) ON DELETE SET NULL,
    position           integer NOT NULL CHECK (position >= 0),
    kind               text NOT NULL DEFAULT 'heading' CHECK (kind IN ('heading', 'part', 'slide', 'sheet', 'rows')),
    text               text NOT NULL,
    char_from          integer NOT NULL CHECK (char_from >= 0),
    char_to            integer NOT NULL,
    page_no            integer CHECK (page_no IS NULL OR page_no >= 1),
    status             text NOT NULL DEFAULT 'queued' CHECK (status = ANY (kn_section_statuses())),
    extracted_version  integer CHECK (extracted_version IS NULL OR extracted_version >= 1),   -- the base's guideline_version it was built under
    maludb_document_id text,                                                  -- the engine's id; kept on a forgotten section until the worker has told the engine
    extracted_at       timestamptz,
    error              text,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    CHECK (char_to >= char_from),
    CHECK (parent_id IS NULL OR parent_id <> id),
    UNIQUE (source_id, position)
);
CREATE INDEX sections_base_status_idx ON sections (base_id, status);
CREATE INDEX sections_source_idx ON sections (source_id, position);
CREATE INDEX sections_parent_idx ON sections (parent_id);
CREATE INDEX sections_maludb_idx ON sections (maludb_document_id) WHERE maludb_document_id IS NOT NULL;
CREATE INDEX sections_heading_trgm_idx ON sections USING gin (heading_path gin_trgm_ops);
CREATE TRIGGER sections_touch BEFORE UPDATE ON sections FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- ---------------------------------------------------------------------------------------------
-- The referee: a base's status follows its builds and sections (one function, called by every trigger that can change it).
-- Builds arrive in db/009; the check is by name so this file stands alone.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION kn_base_recompute_status(p_base_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE s text;
BEGIN
    IF to_regclass('public.builds') IS NOT NULL
       AND EXISTS (SELECT 1 FROM builds b WHERE b.base_id = p_base_id AND b.status IN ('queued', 'running') AND b.kind IN ('extract', 'rebuild_all')) THEN
        s := 'building';
    ELSIF EXISTS (SELECT 1 FROM sections x WHERE x.base_id = p_base_id AND x.status = 'stale') THEN
        s := 'needs_rebuild';
    ELSIF EXISTS (SELECT 1 FROM sections x WHERE x.base_id = p_base_id AND x.status = 'extracted') THEN
        s := 'ready';
    ELSE
        s := 'empty';
    END IF;
    UPDATE knowledge_bases SET status = s WHERE id = p_base_id AND status IS DISTINCT FROM s;
END$$;

-- A section belongs to a source of the same base (set from the source when not given); never to a forgotten source.
CREATE OR REPLACE FUNCTION kn_sections_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE src sources%ROWTYPE;
BEGIN
    SELECT * INTO src FROM sources WHERE id = NEW.source_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such source %', NEW.source_id USING ERRCODE = 'foreign_key_violation'; END IF;
    IF TG_OP = 'INSERT' THEN
        IF NEW.base_id IS NULL THEN NEW.base_id := src.base_id; END IF;
        IF src.status = 'forgotten' THEN RAISE EXCEPTION 'A forgotten source takes no sections' USING ERRCODE = 'check_violation'; END IF;
    END IF;
    IF NEW.base_id <> src.base_id THEN
        RAISE EXCEPTION 'A section belongs to a source of the same base' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.parent_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM sections p WHERE p.id = NEW.parent_id AND p.source_id = NEW.source_id) THEN
        RAISE EXCEPTION 'A section''s parent is a section of the same source' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF OLD.status = 'forgotten' AND NEW.status <> 'forgotten' THEN
            RAISE EXCEPTION 'A forgotten section stays forgotten (replace the source)' USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.status = 'extracted' AND OLD.status <> 'extracted' THEN
            NEW.extracted_at := COALESCE(NEW.extracted_at, now());
            NEW.error := NULL;
        END IF;
    END IF;
    RETURN NEW;
END$$;
ALTER TABLE sections ALTER COLUMN base_id DROP NOT NULL;     -- the trigger fills it from the source; the check below keeps it set
ALTER TABLE sections ADD CONSTRAINT sections_base_set CHECK (base_id IS NOT NULL);
CREATE TRIGGER sections_before BEFORE INSERT OR UPDATE ON sections FOR EACH ROW EXECUTE FUNCTION kn_sections_before();

-- Counts and the base's status, once per statement (a build touches hundreds of sections at a time).
CREATE OR REPLACE FUNCTION kn_sections_after_stmt() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE srcs bigint[] := '{}'; bases bigint[] := '{}'; x bigint;
BEGIN
    -- a branch not taken is never planned, so each trigger may name only the transition table it declares
    IF TG_OP IN ('INSERT', 'UPDATE') THEN
        SELECT COALESCE(array_agg(DISTINCT source_id), '{}'), COALESCE(array_agg(DISTINCT base_id), '{}') INTO srcs, bases FROM new_rows;
    END IF;
    IF TG_OP IN ('DELETE', 'UPDATE') THEN
        SELECT srcs || COALESCE(array_agg(DISTINCT source_id), '{}'), bases || COALESCE(array_agg(DISTINCT base_id), '{}') INTO srcs, bases FROM old_rows;
    END IF;
    FOREACH x IN ARRAY (SELECT COALESCE(array_agg(DISTINCT e), '{}') FROM unnest(srcs) e) LOOP
        UPDATE sources s SET sections_count = (SELECT count(*) FROM sections n WHERE n.source_id = x AND n.status <> 'forgotten') WHERE s.id = x;
    END LOOP;
    FOREACH x IN ARRAY (SELECT COALESCE(array_agg(DISTINCT e), '{}') FROM unnest(bases) e) LOOP
        PERFORM kn_base_recompute_status(x);
    END LOOP;
    RETURN NULL;
END$$;
CREATE TRIGGER sections_after_insert AFTER INSERT ON sections REFERENCING NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION kn_sections_after_stmt();
CREATE TRIGGER sections_after_update AFTER UPDATE ON sections REFERENCING OLD TABLE AS old_rows NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION kn_sections_after_stmt();
CREATE TRIGGER sections_after_delete AFTER DELETE ON sections REFERENCING OLD TABLE AS old_rows FOR EACH STATEMENT EXECUTE FUNCTION kn_sections_after_stmt();

-- A source: forgotten is final; a replaced source (superseded_by set) is forgotten; the replacement is a source of the same
-- base; a source's own fields stay coherent with its text.
CREATE OR REPLACE FUNCTION kn_sources_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE other sources%ROWTYPE;
BEGIN
    IF TG_OP = 'INSERT' THEN
        NEW.added_by := COALESCE(NEW.added_by, app_current_member_id());
        IF EXISTS (SELECT 1 FROM knowledge_bases b WHERE b.id = NEW.base_id AND b.archived_at IS NOT NULL) THEN
            RAISE EXCEPTION 'An archived base takes no sources' USING ERRCODE = 'check_violation';
        END IF;
    ELSE
        IF OLD.status = 'forgotten' AND NEW.status <> 'forgotten' THEN
            RAISE EXCEPTION 'A forgotten source stays forgotten (add it again)' USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.base_id <> OLD.base_id THEN
            RAISE EXCEPTION 'A source never moves between bases' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    IF NEW.superseded_by IS NOT NULL AND NEW.superseded_by IS DISTINCT FROM OLD.superseded_by THEN
        SELECT * INTO other FROM sources WHERE id = NEW.superseded_by;
        IF NOT FOUND OR other.base_id <> NEW.base_id THEN
            RAISE EXCEPTION 'A source is replaced by a source of the same base' USING ERRCODE = 'check_violation';
        END IF;
        NEW.status := 'forgotten';
    END IF;
    IF NEW.status = 'forgotten' THEN
        NEW.forgotten_at := COALESCE(NEW.forgotten_at, now());
    ELSE
        NEW.forgotten_at := NULL;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER sources_before BEFORE INSERT OR UPDATE ON sources FOR EACH ROW EXECUTE FUNCTION kn_sources_before();

-- Removing a source (status forgotten) forgets its sections; the engine is told by the worker, which reads
-- sections.status = 'forgotten' AND maludb_document_id IS NOT NULL and clears the id after the DELETE.
CREATE OR REPLACE FUNCTION kn_sources_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.status = 'forgotten' AND (TG_OP = 'INSERT' OR OLD.status <> 'forgotten') THEN
        UPDATE sections SET status = 'forgotten' WHERE source_id = NEW.id AND status <> 'forgotten';
        PERFORM kn_base_recompute_status(NEW.base_id);
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER sources_after AFTER INSERT OR UPDATE OF status, superseded_by ON sources FOR EACH ROW EXECUTE FUNCTION kn_sources_after();

-- The text's facts follow the text.
CREATE OR REPLACE FUNCTION kn_source_text_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE sources SET char_count = length(NEW.text), text_sha256 = encode(digest(NEW.text, 'sha256'), 'hex') WHERE id = NEW.source_id;
    RETURN NULL;
END$$;
CREATE TRIGGER source_text_after AFTER INSERT OR UPDATE ON source_text FOR EACH ROW EXECUTE FUNCTION kn_source_text_after();

-- page_count follows the anchors (one trigger per operation: each names only the transition table it declares)
CREATE OR REPLACE FUNCTION kn_source_pages_after_ins() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record;
BEGIN
    FOR r IN SELECT DISTINCT source_id FROM new_rows LOOP
        UPDATE sources SET page_count = (SELECT count(*) FROM source_pages p WHERE p.source_id = r.source_id) WHERE id = r.source_id;
    END LOOP;
    RETURN NULL;
END$$;
CREATE OR REPLACE FUNCTION kn_source_pages_after_del() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record;
BEGIN
    FOR r IN SELECT DISTINCT source_id FROM old_rows LOOP
        UPDATE sources SET page_count = (SELECT count(*) FROM source_pages p WHERE p.source_id = r.source_id) WHERE id = r.source_id;
    END LOOP;
    RETURN NULL;
END$$;
CREATE TRIGGER source_pages_after_insert AFTER INSERT ON source_pages REFERENCING NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION kn_source_pages_after_ins();
CREATE TRIGGER source_pages_after_delete AFTER DELETE ON source_pages REFERENCING OLD TABLE AS old_rows FOR EACH STATEMENT EXECUTE FUNCTION kn_source_pages_after_del();

-- ---------------------------------------------------------------------------------------------
-- Files: GL db/009 canonical, verbatim; record_type widened to this application's one file-bearing record. Bytes live under
-- storage/sources/<base>/<source>/ (storage_path), served through the gate, never from the web root.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE attachments (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type   text NOT NULL CHECK (record_type IN ('source')),
    record_id     bigint NOT NULL,
    filename      text NOT NULL,
    mime_type     text NOT NULL,
    byte_size     bigint NOT NULL CHECK (byte_size >= 0),
    sha256        text NOT NULL,
    storage_path  text NOT NULL,
    uploaded_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX attachments_record_idx ON attachments (record_type, record_id);
CREATE INDEX attachments_uploaded_by_idx ON attachments (uploaded_by);
ALTER TABLE sources ADD CONSTRAINT sources_attachment_fk FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE SET NULL;

-- Notes: GL db/009 = CT db/006; record_type widened to base | source | section. A pinned answer writes one on its base (db/010).
CREATE TABLE notes (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type   text NOT NULL CHECK (record_type IN ('base', 'source', 'section')),
    record_id     bigint NOT NULL,
    member_id     bigint REFERENCES members(id) ON DELETE SET NULL,
    body          text NOT NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX notes_record_idx ON notes (record_type, record_id, created_at DESC);
CREATE INDEX notes_member_idx ON notes (member_id);

-- The base a note or an attachment belongs to (the views' reach rule follows it).
CREATE OR REPLACE FUNCTION kn_record_base_id(p_record_type text, p_record_id bigint) RETURNS bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE p_record_type
             WHEN 'base'    THEN p_record_id
             WHEN 'source'  THEN (SELECT base_id FROM sources WHERE id = p_record_id)
             WHEN 'section' THEN (SELECT base_id FROM sections WHERE id = p_record_id)
           END;
$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON sources, source_text, source_pages, sections, attachments, notes TO knowledge_rw;
GRANT EXECUTE ON FUNCTION kn_record_base_id(text, bigint) TO knowledge_rw, knowledge_records_ro, knowledge_activity_ro;
REVOKE ALL ON FUNCTION kn_base_recompute_status(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION kn_base_recompute_status(bigint) TO knowledge_rw;

COMMIT;
