-- 008: GUIDELINES of five kinds, their VERSIONS, the VOCABULARY and the FOCUS AREAS (design §6.2; D7, D8).
--
-- Two moments (§6.2): an extraction, vocabulary or exclusion guideline SHAPES THE GRAPH — saving, changing or deleting one,
-- or any vocabulary term, bumps the base's guideline_version, marks every extracted section STALE and so puts the base at
-- needs_rebuild; an answering or audience guideline changes only the answers and touches none of that. The database is the
-- referee of that rule (kn_graph_shape_changed); PHP's compile step (app/knowledge/compile.php) then sends the namespace its
-- new prompt. A base made from a template starts with the template's set as its own rows (origin 'template'); that copy
-- shapes nothing because there is no graph yet, so it does not bump.
--
-- A focus area is a MaluDB project (maludb_project_id); proposed by the expert from the communities after a first build,
-- accepted or dismissed by a person. New tables — nothing in the estate is close.
BEGIN;

CREATE TABLE guidelines (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    base_id      bigint NOT NULL REFERENCES knowledge_bases(id) ON DELETE CASCADE,
    kind         text NOT NULL CHECK (kind = ANY (kn_guideline_kinds())),
    title        text NOT NULL DEFAULT '' CHECK (length(title) <= 200),
    body         text NOT NULL CHECK (length(body) BETWEEN 1 AND 20000),
    position     integer NOT NULL DEFAULT 0,
    is_active    boolean NOT NULL DEFAULT true,
    origin       text NOT NULL DEFAULT 'person' CHECK (origin IN ('template', 'person', 'agent')),
    version_no   integer NOT NULL DEFAULT 1 CHECK (version_no >= 1),
    created_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    updated_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX guidelines_base_idx ON guidelines (base_id, kind, position);
CREATE TRIGGER guidelines_touch BEFORE UPDATE ON guidelines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Every save of a guideline keeps the words it had, with the base's guideline_version it produced — so "built under N" on
-- the guidelines screen can show exactly which words the graph was extracted with.
CREATE TABLE guideline_versions (
    id                      bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    guideline_id            bigint NOT NULL REFERENCES guidelines(id) ON DELETE CASCADE,
    base_id                 bigint NOT NULL REFERENCES knowledge_bases(id) ON DELETE CASCADE,
    version_no              integer NOT NULL CHECK (version_no >= 1),
    kind                    text NOT NULL CHECK (kind = ANY (kn_guideline_kinds())),
    title                   text NOT NULL DEFAULT '',
    body                    text NOT NULL,
    is_active               boolean NOT NULL,
    base_guideline_version  integer NOT NULL,                      -- knowledge_bases.guideline_version after this save
    saved_by                bigint REFERENCES members(id) ON DELETE SET NULL,
    saved_at                timestamptz NOT NULL DEFAULT now(),
    UNIQUE (guideline_id, version_no)
);
CREATE INDEX guideline_versions_base_idx ON guideline_versions (base_id, saved_at DESC);

CREATE TABLE vocabulary_terms (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    base_id            bigint NOT NULL REFERENCES knowledge_bases(id) ON DELETE CASCADE,
    canonical_name     text NOT NULL CHECK (length(canonical_name) BETWEEN 1 AND 200),
    aliases            text[] NOT NULL DEFAULT '{}',
    subject_type       text CHECK (subject_type IS NULL OR subject_type ~ '^[a-z][a-z0-9_]{0,39}$'),
    description        text,
    maludb_subject_id  text,                                       -- set by the worker when the subject is seeded before a build
    created_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX vocabulary_terms_one_name ON vocabulary_terms (base_id, lower(canonical_name));
CREATE INDEX vocabulary_terms_base_idx ON vocabulary_terms (base_id);
CREATE TRIGGER vocabulary_terms_touch BEFORE UPDATE ON vocabulary_terms FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE focus_areas (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    base_id            bigint NOT NULL REFERENCES knowledge_bases(id) ON DELETE CASCADE,
    name               text NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
    slug               text NOT NULL DEFAULT '' ,
    description        text NOT NULL DEFAULT '',                   -- becomes the project's classifier_md
    seed_terms         text[] NOT NULL DEFAULT '{}',               -- linked subjects
    example_questions  text[] NOT NULL DEFAULT '{}',
    maludb_project_id  text,
    status             text NOT NULL DEFAULT 'active' CHECK (status IN ('proposed', 'active', 'archived')),
    proposed_by        bigint REFERENCES members(id) ON DELETE SET NULL,   -- the expert, when proposed
    proposal_note      text,                                        -- why: "a community of 14 subjects about returns"
    decided_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at         timestamptz,
    position           integer NOT NULL DEFAULT 0,
    created_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    CHECK (status <> 'proposed' OR proposed_by IS NOT NULL)
);
CREATE UNIQUE INDEX focus_areas_one_slug ON focus_areas (base_id, slug);
CREATE INDEX focus_areas_base_idx ON focus_areas (base_id, status, position);
CREATE TRIGGER focus_areas_touch BEFORE UPDATE ON focus_areas FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE OR REPLACE FUNCTION kn_slugify(p text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT COALESCE(NULLIF(trim(BOTH '-' FROM regexp_replace(lower(unaccent(p)), '[^a-z0-9]+', '-', 'g')), ''), 'area');
$$;

CREATE OR REPLACE FUNCTION kn_focus_areas_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.slug = '' THEN NEW.slug := kn_slugify(NEW.name); END IF;
    IF TG_OP = 'INSERT' THEN
        NEW.created_by := COALESCE(NEW.created_by, app_current_member_id());
        IF NEW.status = 'proposed' THEN NEW.proposed_by := COALESCE(NEW.proposed_by, app_current_member_id()); END IF;
    ELSIF OLD.status = 'proposed' AND NEW.status <> 'proposed' THEN
        NEW.decided_by := COALESCE(NEW.decided_by, app_current_member_id());
        NEW.decided_at := COALESCE(NEW.decided_at, now());
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER focus_areas_before BEFORE INSERT OR UPDATE ON focus_areas FOR EACH ROW EXECUTE FUNCTION kn_focus_areas_before();

-- A source's areas (a section inherits its source's: the build sends projects[] per section from here).
CREATE TABLE source_focus_areas (
    source_id      bigint NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
    focus_area_id  bigint NOT NULL REFERENCES focus_areas(id) ON DELETE CASCADE,
    assigned_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at     timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (source_id, focus_area_id)
);
CREATE INDEX source_focus_areas_area_idx ON source_focus_areas (focus_area_id);

CREATE OR REPLACE FUNCTION kn_source_focus_areas_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF (SELECT base_id FROM sources WHERE id = NEW.source_id) IS DISTINCT FROM (SELECT base_id FROM focus_areas WHERE id = NEW.focus_area_id) THEN
        RAISE EXCEPTION 'A source is assigned to an area of its own base' USING ERRCODE = 'check_violation';
    END IF;
    NEW.assigned_by := COALESCE(NEW.assigned_by, app_current_member_id());
    RETURN NEW;
END$$;
CREATE TRIGGER source_focus_areas_before BEFORE INSERT OR UPDATE ON source_focus_areas FOR EACH ROW EXECUTE FUNCTION kn_source_focus_areas_before();

-- A key may be confined to one area of its base (db/003's column; the pair is checked in db/011).
ALTER TABLE mcp_access_tokens ADD CONSTRAINT mcp_access_tokens_focus_area_fk FOREIGN KEY (focus_area_id) REFERENCES focus_areas(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------------------------
-- The referee of the two moments.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION kn_graph_shape_changed(p_base_id bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE v integer;
BEGIN
    UPDATE knowledge_bases SET guideline_version = guideline_version + 1 WHERE id = p_base_id RETURNING guideline_version INTO v;
    UPDATE sections SET status = 'stale' WHERE base_id = p_base_id AND status = 'extracted';   -- the statement trigger recomputes the base's status
    PERFORM kn_base_recompute_status(p_base_id);
    RETURN v;
END$$;

CREATE OR REPLACE FUNCTION kn_guidelines_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        NEW.created_by := COALESCE(NEW.created_by, app_current_member_id());
        NEW.updated_by := COALESCE(NEW.updated_by, NEW.created_by);
        NEW.version_no := 1;
    ELSE
        IF NEW.base_id <> OLD.base_id THEN RAISE EXCEPTION 'A guideline never moves between bases' USING ERRCODE = 'check_violation'; END IF;
        IF NEW.body IS DISTINCT FROM OLD.body OR NEW.kind IS DISTINCT FROM OLD.kind OR NEW.title IS DISTINCT FROM OLD.title OR NEW.is_active IS DISTINCT FROM OLD.is_active THEN
            NEW.version_no := OLD.version_no + 1;
            NEW.updated_by := COALESCE(app_current_member_id(), NEW.updated_by);
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER guidelines_before BEFORE INSERT OR UPDATE ON guidelines FOR EACH ROW EXECUTE FUNCTION kn_guidelines_before();

CREATE OR REPLACE FUNCTION kn_guidelines_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE shaping boolean; v integer;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.kind = ANY (kn_graph_shaping_kinds()) THEN PERFORM kn_graph_shape_changed(OLD.base_id); END IF;
        RETURN NULL;
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.version_no = OLD.version_no THEN RETURN NULL; END IF;      -- a position change is not a save
    shaping := NEW.kind = ANY (kn_graph_shaping_kinds()) OR (TG_OP = 'UPDATE' AND OLD.kind = ANY (kn_graph_shaping_kinds()));
    IF shaping AND NOT (TG_OP = 'INSERT' AND NEW.origin = 'template') THEN
        v := kn_graph_shape_changed(NEW.base_id);
    ELSE
        SELECT guideline_version INTO v FROM knowledge_bases WHERE id = NEW.base_id;
    END IF;
    INSERT INTO guideline_versions (guideline_id, base_id, version_no, kind, title, body, is_active, base_guideline_version, saved_by)
    VALUES (NEW.id, NEW.base_id, NEW.version_no, NEW.kind, NEW.title, NEW.body, NEW.is_active, v, COALESCE(app_current_member_id(), NEW.updated_by));
    RETURN NULL;
END$$;
CREATE TRIGGER guidelines_after AFTER INSERT OR UPDATE OR DELETE ON guidelines FOR EACH ROW EXECUTE FUNCTION kn_guidelines_after();

CREATE OR REPLACE FUNCTION kn_vocabulary_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.canonical_name = OLD.canonical_name AND NEW.aliases = OLD.aliases AND NEW.subject_type IS NOT DISTINCT FROM OLD.subject_type
       AND NEW.description IS NOT DISTINCT FROM OLD.description THEN
        RETURN NULL;                                                   -- the worker writing maludb_subject_id shapes nothing
    END IF;
    PERFORM kn_graph_shape_changed(COALESCE(NEW.base_id, OLD.base_id));
    RETURN NULL;
END$$;
CREATE TRIGGER vocabulary_terms_after AFTER INSERT OR UPDATE OR DELETE ON vocabulary_terms FOR EACH ROW EXECUTE FUNCTION kn_vocabulary_after();

-- A new base starts with its template's shipped set as its own guideline rows.
CREATE OR REPLACE FUNCTION kn_base_copy_template() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    INSERT INTO guidelines (base_id, kind, title, body, position, origin, created_by)
    SELECT NEW.id, g->>'kind', COALESCE(g->>'title', ''), g->>'body', ord, 'template', NEW.created_by
      FROM base_templates t, jsonb_array_elements(t.guidelines) WITH ORDINALITY AS e(g, ord)
     WHERE t.key = NEW.template;
    RETURN NULL;
END$$;
CREATE TRIGGER knowledge_bases_copy_template AFTER INSERT ON knowledge_bases FOR EACH ROW EXECUTE FUNCTION kn_base_copy_template();

GRANT SELECT, INSERT, UPDATE, DELETE ON guidelines, guideline_versions, vocabulary_terms, focus_areas, source_focus_areas TO knowledge_rw;
GRANT EXECUTE ON FUNCTION kn_slugify(text) TO knowledge_rw, knowledge_records_ro, knowledge_activity_ro;
REVOKE ALL ON FUNCTION kn_graph_shape_changed(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION kn_graph_shape_changed(bigint) TO knowledge_rw;

COMMIT;
