-- 006: KNOWLEDGE BASES and WHO MAY READ THEM (design §3, §6.3; D5). New tables — nothing in the estate is a curated corpus
-- with a namespace in a memory database.
--
-- The referee's rules here (CLAUDE.md "The database is the referee"):
--   * a base's namespace is set ONCE and is always 'kb:' || id (BEFORE INSERT sets it; any later change is refused);
--   * a new base is PRIVATE; `visibility` is the truth and the `everyone` principal row MIRRORS it (one trigger keeps them
--     equal, and a direct write of an everyone row is refused) — so the reach function reads base_members alone;
--   * a template may change only while the base is empty (no live source);
--   * base_members names ONE principal per row: a member (a human), an agent (an agent member), a department (reached live
--     through the mirror), an application (a sibling's catalog key in principal_ref — what K7 checks), or everyone;
--   * the owner is always a curator; `own` on base_members is for a co-owner.
--
-- REACH: kn_visible_base_ids() is ONE PL/pgSQL function returning the caller's whole set (the kernel's db/160 lesson); every
-- mcp_* view (db/014) tests `id IN (SELECT kn_visible_base_ids())` ONCE per statement. kn_can_read_base(id) and
-- kn_can_curate_base(id) are the per-record gates PHP calls.
BEGIN;

CREATE TABLE knowledge_bases (
    id                      bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key                     text NOT NULL CHECK (key ~ '^[a-z0-9][a-z0-9_-]{0,59}$'),      -- the URL slug and the API's name for it
    name                    text NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
    description             text NOT NULL DEFAULT '',
    template                text NOT NULL DEFAULT 'general' REFERENCES base_templates(key) ON DELETE RESTRICT,
    template_version        integer NOT NULL DEFAULT 1,                                    -- the template version the shipped set was copied from
    visibility              text NOT NULL DEFAULT 'private' CHECK (visibility IN ('private', 'internal')),
    owner_member_id         bigint NOT NULL REFERENCES members(id) ON DELETE RESTRICT,
    namespace               text,                                                           -- 'kb:' || id, set once by trigger
    status                  text NOT NULL DEFAULT 'empty' CHECK (status IN ('empty', 'building', 'ready', 'needs_rebuild')),
    guideline_version       integer NOT NULL DEFAULT 1 CHECK (guideline_version >= 1),
    auto_extract            boolean NOT NULL DEFAULT true,                                 -- D9: new sources are extracted by the worker
    monthly_text_mb_limit   integer CHECK (monthly_text_mb_limit IS NULL OR monthly_text_mb_limit BETWEEN 1 AND 100000),   -- NULL = kn_settings default (filled below)
    sensitivity             text NOT NULL DEFAULT 'standard' CHECK (sensitivity IN ('standard', 'confidential', 'restricted')),   -- D14: passed to the engine
    language                text NOT NULL DEFAULT 'en' CHECK (language ~ '^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$'),
    -- counts cached from the engine after a build (graph/stats); never authoritative
    documents_count         integer NOT NULL DEFAULT 0 CHECK (documents_count >= 0),
    subjects_count          integer NOT NULL DEFAULT 0 CHECK (subjects_count >= 0),
    statements_count        integer NOT NULL DEFAULT 0 CHECK (statements_count >= 0),
    communities_count       integer NOT NULL DEFAULT 0 CHECK (communities_count >= 0),
    last_built_at           timestamptz,
    archived_at             timestamptz,
    created_by              bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at              timestamptz NOT NULL DEFAULT now(),
    updated_at              timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX knowledge_bases_key_live ON knowledge_bases (lower(key)) WHERE archived_at IS NULL;
CREATE UNIQUE INDEX knowledge_bases_namespace ON knowledge_bases (namespace);
CREATE INDEX knowledge_bases_owner_idx ON knowledge_bases (owner_member_id);
CREATE INDEX knowledge_bases_status_idx ON knowledge_bases (status) WHERE archived_at IS NULL;
CREATE INDEX knowledge_bases_name_trgm_idx ON knowledge_bases USING gin (name gin_trgm_ops);
CREATE TRIGGER knowledge_bases_touch BEFORE UPDATE ON knowledge_bases FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- The namespace is set once and equals 'kb:' || id; the key defaults to 'kb-' || id; the limit defaults from the settings;
-- the template version is the template's at creation.
CREATE OR REPLACE FUNCTION kn_base_before_insert() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.namespace IS NOT NULL AND NEW.namespace <> 'kb:' || NEW.id THEN
        RAISE EXCEPTION 'A base''s namespace is kb:<id> and is set once (got %)', NEW.namespace USING ERRCODE = 'check_violation';
    END IF;
    NEW.namespace := 'kb:' || NEW.id;
    NEW.key := COALESCE(NULLIF(NEW.key, ''), 'kb-' || NEW.id);
    NEW.monthly_text_mb_limit := COALESCE(NEW.monthly_text_mb_limit, kn_setting_int('default_monthly_text_mb')::integer);
    NEW.created_by := COALESCE(NEW.created_by, app_current_member_id());
    SELECT version INTO NEW.template_version FROM base_templates WHERE key = NEW.template;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such template %', NEW.template USING ERRCODE = 'foreign_key_violation'; END IF;
    NEW.status := 'empty';                                   -- a new base has no graph; the referee decides the rest (kn_base_recompute_status)
    RETURN NEW;
END$$;
-- The identity value is assigned before BEFORE triggers run, so NEW.id is known here. The key column is NOT NULL, so
-- the writer passes '' to get the default.
ALTER TABLE knowledge_bases ALTER COLUMN key SET DEFAULT '';
CREATE TRIGGER knowledge_bases_before_insert BEFORE INSERT ON knowledge_bases FOR EACH ROW EXECUTE FUNCTION kn_base_before_insert();

CREATE OR REPLACE FUNCTION kn_base_before_update() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.namespace IS DISTINCT FROM OLD.namespace THEN
        RAISE EXCEPTION 'A base''s namespace is set once' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.id <> OLD.id THEN
        RAISE EXCEPTION 'A base''s id never changes' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.template <> OLD.template THEN
        -- only while empty: no live source (sources arrive in db/007; the check is by name so this file stands alone)
        IF to_regclass('public.sources') IS NOT NULL AND EXISTS (SELECT 1 FROM sources s WHERE s.base_id = OLD.id AND s.status <> 'forgotten') THEN
            RAISE EXCEPTION 'A base may switch template only while it is empty' USING ERRCODE = 'check_violation';
        END IF;
        SELECT version INTO NEW.template_version FROM base_templates WHERE key = NEW.template;
        IF NOT FOUND THEN RAISE EXCEPTION 'No such template %', NEW.template USING ERRCODE = 'foreign_key_violation'; END IF;
    END IF;
    NEW.monthly_text_mb_limit := COALESCE(NEW.monthly_text_mb_limit, kn_setting_int('default_monthly_text_mb')::integer);
    RETURN NEW;
END$$;
CREATE TRIGGER knowledge_bases_before_update BEFORE UPDATE ON knowledge_bases FOR EACH ROW EXECUTE FUNCTION kn_base_before_update();

-- ---------------------------------------------------------------------------------------------
-- Who may read or curate a base (design §3).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE base_members (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    base_id         bigint NOT NULL REFERENCES knowledge_bases(id) ON DELETE CASCADE,
    principal_kind  text NOT NULL CHECK (principal_kind IN ('member', 'department', 'agent', 'application', 'everyone')),
    principal_id    bigint,                                       -- a member (human or agent) or a department id
    principal_ref   text,                                         -- an application's catalog key
    level           text NOT NULL DEFAULT 'read' CHECK (level IN ('read', 'curate', 'own')),
    granted_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at      timestamptz NOT NULL DEFAULT now(),
    CHECK (CASE principal_kind
             WHEN 'application' THEN principal_id IS NULL AND principal_ref ~ '^[a-z0-9_]{1,60}$'
             WHEN 'everyone'    THEN principal_id IS NULL AND principal_ref IS NULL AND level = 'read'
             ELSE                    principal_id IS NOT NULL AND principal_ref IS NULL
           END),
    CHECK (principal_kind <> 'application' OR level = 'read')      -- a sibling reads; it never curates
);
CREATE UNIQUE INDEX base_members_one_per_principal ON base_members (base_id, principal_kind, COALESCE(principal_id, 0), COALESCE(principal_ref, ''));
CREATE INDEX base_members_principal_idx ON base_members (principal_kind, principal_id);
CREATE INDEX base_members_application_idx ON base_members (principal_ref) WHERE principal_kind = 'application';

-- A member principal is a human of the mirror, an agent principal an agent member, a department principal a department;
-- an `everyone` row is written only by the visibility trigger below.
CREATE OR REPLACE FUNCTION kn_base_members_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE k text;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.principal_kind = 'everyone' AND pg_trigger_depth() <= 1 THEN
            RAISE EXCEPTION 'The everyone principal follows the base''s visibility: set visibility to private instead' USING ERRCODE = 'check_violation';
        END IF;
        RETURN OLD;
    END IF;
    IF NEW.principal_kind = 'everyone' AND pg_trigger_depth() <= 1 THEN
        RAISE EXCEPTION 'The everyone principal follows the base''s visibility: set visibility to internal instead' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.principal_kind IN ('member', 'agent') THEN
        SELECT member_kind INTO k FROM members WHERE id = NEW.principal_id;
        IF k IS NULL THEN RAISE EXCEPTION 'No such member %', NEW.principal_id USING ERRCODE = 'foreign_key_violation'; END IF;
        IF NEW.principal_kind = 'member' AND k <> 'human' THEN RAISE EXCEPTION 'A member principal is a person; share to an agent with principal_kind agent' USING ERRCODE = 'check_violation'; END IF;
        IF NEW.principal_kind = 'agent' AND k <> 'agent' THEN RAISE EXCEPTION 'An agent principal is an agent member' USING ERRCODE = 'check_violation'; END IF;
    ELSIF NEW.principal_kind = 'department' THEN
        IF NOT EXISTS (SELECT 1 FROM departments WHERE id = NEW.principal_id) THEN
            RAISE EXCEPTION 'No such department %', NEW.principal_id USING ERRCODE = 'foreign_key_violation';
        END IF;
    END IF;
    NEW.granted_by := COALESCE(NEW.granted_by, app_current_member_id());
    RETURN NEW;
END$$;
CREATE TRIGGER base_members_before BEFORE INSERT OR UPDATE OR DELETE ON base_members FOR EACH ROW EXECUTE FUNCTION kn_base_members_before();

-- visibility ↔ the everyone row (the trigger runs at depth 1, so the guard above lets it through).
CREATE OR REPLACE FUNCTION kn_base_visibility_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.visibility = 'internal' THEN
        INSERT INTO base_members (base_id, principal_kind, level, granted_by) VALUES (NEW.id, 'everyone', 'read', app_current_member_id())
        ON CONFLICT DO NOTHING;
    ELSE
        DELETE FROM base_members WHERE base_id = NEW.id AND principal_kind = 'everyone';
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER knowledge_bases_visibility AFTER INSERT OR UPDATE OF visibility ON knowledge_bases FOR EACH ROW EXECUTE FUNCTION kn_base_visibility_after();

-- The bases an APPLICATION principal (a sibling, by catalog key) was shared: what the K7 shares answer from.
CREATE OR REPLACE FUNCTION kn_application_base_ids(p_app_key text) RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN QUERY
        SELECT bm.base_id FROM base_members bm JOIN knowledge_bases b ON b.id = bm.base_id AND b.archived_at IS NULL
         WHERE bm.principal_kind = 'application' AND bm.principal_ref = p_app_key;
END$$;

-- The caller's whole set, in ONE query. The admin sees everything (D2; PHP logs base.admin_view). A guest sees only the
-- bases named to them (or to a department they are in — a named share). Everyone else: named, their departments', their
-- own, and every base with the everyone principal (= internal). Archived bases stay in the set of those who could read
-- them, so a curator can restore one; the views say archived_at.
CREATE OR REPLACE FUNCTION kn_visible_base_ids() RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id(); guest boolean;
BEGIN
    IF me IS NULL OR NOT app_is_active_member() THEN RETURN; END IF;
    IF kn_is_admin() THEN
        RETURN QUERY SELECT b.id FROM knowledge_bases b;
        RETURN;
    END IF;
    guest := kn_is_guest();
    RETURN QUERY
        SELECT b.id FROM knowledge_bases b WHERE b.owner_member_id = me
        UNION
        SELECT bm.base_id FROM base_members bm
         WHERE (bm.principal_kind IN ('member', 'agent') AND bm.principal_id = me)
            OR (bm.principal_kind = 'department' AND bm.principal_id IN (SELECT app_member_department_ids()))
            OR (bm.principal_kind = 'everyone' AND NOT guest);
END$$;

-- The bases the caller curates: the admin everywhere; an owner; a curate/own share naming them, their department or them
-- as an agent. (Owning a base makes one its curator whatever role one holds — §2 "a base of my own — I am its curator".)
CREATE OR REPLACE FUNCTION kn_curated_base_ids() RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id();
BEGIN
    IF me IS NULL OR NOT app_is_active_member() THEN RETURN; END IF;
    IF kn_is_admin() THEN
        RETURN QUERY SELECT b.id FROM knowledge_bases b;
        RETURN;
    END IF;
    RETURN QUERY
        SELECT b.id FROM knowledge_bases b WHERE b.owner_member_id = me
        UNION
        SELECT bm.base_id FROM base_members bm
         WHERE bm.level IN ('curate', 'own')
           AND ((bm.principal_kind IN ('member', 'agent') AND bm.principal_id = me)
             OR (bm.principal_kind = 'department' AND bm.principal_id IN (SELECT app_member_department_ids())));
END$$;

CREATE OR REPLACE FUNCTION kn_can_read_base(p_base_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN p_base_id IN (SELECT kn_visible_base_ids());
END$$;

CREATE OR REPLACE FUNCTION kn_can_curate_base(p_base_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN p_base_id IN (SELECT kn_curated_base_ids());
END$$;

-- The caller's level on a base, for the views and the screens: own | curate | read | none.
CREATE OR REPLACE FUNCTION kn_base_level(p_base_id bigint) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id();
BEGIN
    IF me IS NULL OR NOT app_is_active_member() THEN RETURN 'none'; END IF;
    IF kn_is_admin() OR EXISTS (SELECT 1 FROM knowledge_bases b WHERE b.id = p_base_id AND b.owner_member_id = me) THEN RETURN 'own'; END IF;
    IF p_base_id IN (SELECT kn_curated_base_ids()) THEN RETURN 'curate'; END IF;
    IF p_base_id IN (SELECT kn_visible_base_ids()) THEN RETURN 'read'; END IF;
    RETURN 'none';
END$$;

-- The keys of db/003 are bound to a base: the foreign key now that the table exists.
ALTER TABLE mcp_access_tokens ADD CONSTRAINT mcp_access_tokens_base_fk FOREIGN KEY (base_id) REFERENCES knowledge_bases(id) ON DELETE CASCADE;

GRANT SELECT, INSERT, UPDATE, DELETE ON knowledge_bases, base_members TO knowledge_rw;
GRANT EXECUTE ON FUNCTION kn_application_base_ids(text), kn_visible_base_ids(), kn_curated_base_ids(), kn_can_read_base(bigint), kn_can_curate_base(bigint), kn_base_level(bigint)
    TO knowledge_rw, knowledge_records_ro, knowledge_activity_ro;

COMMIT;
