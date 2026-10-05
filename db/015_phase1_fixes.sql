-- 015: PHASE 1 FIXES — what the approved Phase 1 specs and the MCP tool surface ask of the schema, added without touching
-- db/001–014 (migrations are append-only; CLAUDE.md "Never modify a migration"). Each part says which document asked and why.
--
--   1. kn_section_markdown() no longer hands out a FORGOTTEN section's text (tool surface, "What the schema lacks": db/013
--      concatenated s.text whatever the status, while mcp_sections blanks it — so the function, granted to the records role,
--      was a way round the view). A forgotten section and a section the caller may not read both answer NULL.
--   2. question_feedback.token_id — the KEY a rating came through (api-keys-k7.md divergence 2; tool surface "No feedback by
--      a key"). member_id stays NOT NULL and is the key's minter; the uniqueness per (question, member) is kept, so a key's
--      rating still replaces its minter's own on that question — but the row now says which key gave it, and the view shows
--      the key's label beside the minter's name.
--   3. kn_application_bases(app_key) — the bases SHARED WITH ONE APPLICATION principal, with what the K7 `bases_index` share
--      (os.knowledge-bases/1) needs, for a caller with NO member identity: the kernel's token sets no app.member_id, so
--      mcp_bases answers nothing and kn_application_base_ids() gives ids only (tool surface "No read function lists an
--      application's bases"). People-free: no owner, curator, member, key; no text of a source, section or guideline.
--   4. kn_settings.expert_member_id / archivist_member_id — WHO the expert and the Archivist are (ask.md divergence 1, "slice 5
--      ships it"; agents-feeds.md divergence 1; home-admin.md agents_settings_save). Nothing in the mirror says which agent
--      member is the expert, yet agent_dispatches.agent_member_id must name one, and the kernel's chat endpoint is called as
--      ?agent=<expert_member_id> so the row and the call name the same agent. A trigger refuses a human. Shown in mcp_settings
--      to admins only, exactly like model_alias and embedding_model.
--   5. kn_guidelines_after() — a DRAFT does not shape the graph (agents-feeds.md divergence 3, "slice 8 ships it";
--      guidelines-compile.md had not ruled on drafts). The expert saves drafts with is_active = false; db/008 treated every
--      save of an extraction, vocabulary or exclusion guideline as a graph change, so one draft marked every section stale.
--      Now only a guideline that IS or WAS active (before or after the save) shapes; the version row is still written.
--
-- Run in order as postgres after db/014:
--   sudo -u postgres psql -v ON_ERROR_STOP=1 -d subello_knowledge -f db/015_phase1_fixes.sql
-- Proof: db/proof/phase1_proof.sql (and db/proof/phase0_proof.sql still passes with this file loaded).
BEGIN;

-- ---------------------------------------------------------------------------------------------
-- 1. A forgotten section's text never leaves through the function (same signature, same grants: CREATE OR REPLACE keeps
--    them). What a forgotten section was is history (the activity log, the citation's quote); its words are not served —
--    to anyone, its curator and the admin included, the rule mcp_sections already keeps. Unreadable = NULL, as before.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION kn_section_markdown(p_section_id bigint) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE s sections%ROWTYPE; src sources%ROWTYPE;
BEGIN
    SELECT * INTO s FROM sections WHERE id = p_section_id;
    IF NOT FOUND OR s.status = 'forgotten' OR NOT kn_can_read_base(s.base_id) THEN RETURN NULL; END IF;
    SELECT * INTO src FROM sources WHERE id = s.source_id;
    IF NOT FOUND OR src.status = 'forgotten' THEN RETURN NULL; END IF;     -- belt to the brace: a forgotten source's sections are forgotten (db/007)
    RETURN '# ' || s.heading_path || E'\n\n' || s.text || E'\n\n— ' || src.title
        || CASE WHEN s.page_no IS NOT NULL THEN ', page ' || s.page_no ELSE '' END
        || ' (section ' || s.id || CASE WHEN s.status = 'stale' THEN ', stale' ELSE '' END || ')';
END$$;

-- ---------------------------------------------------------------------------------------------
-- 2. The key a rating came through. ON DELETE SET NULL: a revoked-and-purged key leaves the rating standing as its minter's.
-- ---------------------------------------------------------------------------------------------
ALTER TABLE question_feedback ADD COLUMN token_id bigint REFERENCES mcp_access_tokens(id) ON DELETE SET NULL;
CREATE INDEX question_feedback_token_idx ON question_feedback (token_id) WHERE token_id IS NOT NULL;
COMMENT ON COLUMN question_feedback.token_id IS 'The key (scope api, bound to the question''s base) the rating was posted with; NULL = a person or agent rated in their own name. member_id is then the key''s minter.';

-- The rule, in the trigger db/010 already runs (same name, so the trigger itself is unchanged): a rating through a key names a
-- live-or-not key of scope api bound to THE QUESTION'S BASE, and is the minter's — member_id defaults to the minter (the token
-- API runs with app.member_id = the minter, but the key is the authority) and may not name anyone else.
CREATE OR REPLACE FUNCTION kn_question_feedback_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE tok mcp_access_tokens%ROWTYPE; qb bigint;
BEGIN
    IF NEW.token_id IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.token_id IS DISTINCT FROM OLD.token_id) THEN
        SELECT * INTO tok FROM mcp_access_tokens WHERE id = NEW.token_id;
        SELECT base_id INTO qb FROM questions WHERE id = NEW.question_id;
        IF NOT FOUND OR tok.id IS NULL OR tok.scope <> 'api' OR tok.base_id IS DISTINCT FROM qb THEN
            RAISE EXCEPTION 'A key rates answers of the one base it is bound to' USING ERRCODE = 'check_violation';
        END IF;
        IF TG_OP = 'INSERT' THEN NEW.member_id := COALESCE(NEW.member_id, tok.member_id); END IF;
        IF NEW.member_id IS DISTINCT FROM tok.member_id THEN
            RAISE EXCEPTION 'A key''s rating is its minter''s' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    IF TG_OP = 'INSERT' THEN NEW.member_id := COALESCE(NEW.member_id, app_current_member_id()); END IF;
    IF NEW.member_id IS NULL THEN RAISE EXCEPTION 'Feedback names who gave it' USING ERRCODE = 'not_null_violation'; END IF;
    RETURN NEW;
END$$;

-- The view shows the key beside the minter (appended last; the row rule is db/014's, unchanged).
CREATE OR REPLACE VIEW mcp_question_feedback WITH (security_barrier = true) AS
SELECT f.id AS feedback_id, f.question_id, q.base_id, f.member_id, m.display_name AS member_name, f.rating, f.comment, f.created_at, f.updated_at,
       f.token_id, t.label AS key_label
  FROM question_feedback f JOIN questions q ON q.id = f.question_id LEFT JOIN members m ON m.id = f.member_id
  LEFT JOIN mcp_access_tokens t ON t.id = f.token_id
 WHERE f.question_id IN (SELECT kn_visible_question_ids());

-- ---------------------------------------------------------------------------------------------
-- 3. The bases shared with one application, for the kernel's token path (no member identity). Only an explicit
--    `application` principal counts: an internal base's `everyone` row is for people, never for a sibling, and a private base
--    not named to the application is not in the set. Archived bases never. The fields are os.knowledge-bases/1's and nothing
--    else: no owner, no curator, no key, no count of questions, no text of a source, section or guideline. Descriptions are
--    trimmed (500 for a base, 300 for an area) as the document says. Areas: the ACTIVE ones, in the curator's order.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION kn_application_bases(p_app_key text)
    RETURNS TABLE (base_id bigint, key text, name text, description text, template text, language text, status text,
                   last_built_at timestamptz, source_count bigint, section_count bigint, areas jsonb)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF p_app_key IS NULL OR p_app_key !~ '^[a-z0-9_]{1,60}$' THEN RETURN; END IF;
    RETURN QUERY
        SELECT b.id, b.key, b.name, left(b.description, 500), b.template, b.language, b.status, b.last_built_at,
               (SELECT count(*) FROM sources s WHERE s.base_id = b.id AND s.status <> 'forgotten'),
               (SELECT count(*) FROM sections x WHERE x.base_id = b.id AND x.status <> 'forgotten'),
               (SELECT COALESCE(jsonb_agg(jsonb_build_object('slug', a.slug, 'name', a.name, 'description', left(a.description, 300),
                                                             'example_questions', to_jsonb(a.example_questions[1:5]))
                                          ORDER BY a.position, a.name), '[]'::jsonb)
                  FROM focus_areas a WHERE a.base_id = b.id AND a.status = 'active')
          FROM knowledge_bases b
         WHERE b.archived_at IS NULL
           AND EXISTS (SELECT 1 FROM base_members bm WHERE bm.base_id = b.id AND bm.principal_kind = 'application' AND bm.principal_ref = p_app_key)
         ORDER BY b.name, b.id;
END$$;
REVOKE ALL ON FUNCTION kn_application_bases(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION kn_application_bases(text) TO knowledge_records_ro;

-- ---------------------------------------------------------------------------------------------
-- 4. Who the expert and the Archivist are. Both an agent member of the mirror, or blank. ON DELETE SET NULL: a member the
--    directory removes leaves the setting blank (the screens then say "No expert is named yet").
-- ---------------------------------------------------------------------------------------------
ALTER TABLE kn_settings ADD COLUMN expert_member_id bigint REFERENCES members(id) ON DELETE SET NULL;
ALTER TABLE kn_settings ADD COLUMN archivist_member_id bigint REFERENCES members(id) ON DELETE SET NULL;
COMMENT ON COLUMN kn_settings.expert_member_id IS 'The agent member that is Knowledge''s expert: dispatches go to it and the chat endpoint is called as ?agent=<this>. NULL = not named yet.';
COMMENT ON COLUMN kn_settings.archivist_member_id IS 'The agent member that is the Archivist (its proposals, the Monday note). NULL = not named yet.';

CREATE OR REPLACE FUNCTION kn_settings_agents_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.expert_member_id IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.expert_member_id IS DISTINCT FROM OLD.expert_member_id)
       AND NOT EXISTS (SELECT 1 FROM members m WHERE m.id = NEW.expert_member_id AND m.member_kind = 'agent') THEN
        RAISE EXCEPTION 'The expert is an agent member' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.archivist_member_id IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.archivist_member_id IS DISTINCT FROM OLD.archivist_member_id)
       AND NOT EXISTS (SELECT 1 FROM members m WHERE m.id = NEW.archivist_member_id AND m.member_kind = 'agent') THEN
        RAISE EXCEPTION 'The Archivist is an agent member' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER kn_settings_agents BEFORE INSERT OR UPDATE OF expert_member_id, archivist_member_id ON kn_settings
    FOR EACH ROW EXECUTE FUNCTION kn_settings_agents_before();

-- The settings view: db/014's columns in db/014's order, the two ids appended last and shown to admins only.
CREATE OR REPLACE VIEW mcp_settings WITH (security_barrier = true) AS
SELECT s.business_name, s.default_monthly_text_mb, s.max_source_bytes, s.url_fetch_allowed, s.url_fetch_max_bytes, s.evidence_threshold, s.max_evidence,
       s.max_question_chars, s.conversation_turns, s.question_retention_days, s.key_rate_per_minute, s.key_rate_per_day, s.key_rotation_overlap_hours,
       s.pin_review_months, s.section_target_chars, s.section_max_chars,
       CASE WHEN (SELECT kn_is_admin()) THEN s.model_alias END AS model_alias,
       CASE WHEN (SELECT kn_is_admin()) THEN s.embedding_model END AS embedding_model,
       s.limit_warn_pct, s.timezone, s.updated_at,
       CASE WHEN (SELECT kn_is_admin()) THEN s.expert_member_id END AS expert_member_id,
       CASE WHEN (SELECT kn_is_admin()) THEN s.archivist_member_id END AS archivist_member_id
  FROM kn_settings s WHERE s.id = 1 AND (SELECT app_is_active_member());

-- ---------------------------------------------------------------------------------------------
-- 5. The two moments, with drafts. A graph-shaping guideline is LIVE when it is active; a save shapes the graph when the
--    guideline was live before it or is live after it:
--      inactive → inactive (a draft edited, any kind)          nothing: no bump, nothing stale (the version row is written)
--      insert inactive (the expert's draft)                     nothing (the version row is written)
--      inactive → active (a draft accepted)                     shapes
--      active → inactive (a guideline switched off)             shapes
--      active, edited                                           shapes (as db/008)
--      delete of an inactive guideline                          nothing (it was never compiled)
--      delete of an active one                                  shapes (as db/008)
--      answering / audience, any save                           nothing (as db/008)
--      a template's copy on a new base                          nothing (as db/008: there is no graph yet)
--      a position change                                        not a save (as db/008)
--    A kind change counts on both sides: an active answering guideline turned into an extraction one shapes, and so does the
--    reverse.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION kn_guidelines_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE was_live boolean := false; now_live boolean := false; v integer;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.is_active AND OLD.kind = ANY (kn_graph_shaping_kinds()) THEN PERFORM kn_graph_shape_changed(OLD.base_id); END IF;
        RETURN NULL;
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.version_no = OLD.version_no THEN RETURN NULL; END IF;      -- a position change is not a save
    IF TG_OP = 'UPDATE' THEN was_live := OLD.is_active AND OLD.kind = ANY (kn_graph_shaping_kinds()); END IF;
    now_live := NEW.is_active AND NEW.kind = ANY (kn_graph_shaping_kinds());
    IF (was_live OR now_live) AND NOT (TG_OP = 'INSERT' AND NEW.origin = 'template') THEN
        v := kn_graph_shape_changed(NEW.base_id);
    ELSE
        SELECT guideline_version INTO v FROM knowledge_bases WHERE id = NEW.base_id;
    END IF;
    INSERT INTO guideline_versions (guideline_id, base_id, version_no, kind, title, body, is_active, base_guideline_version, saved_by)
    VALUES (NEW.id, NEW.base_id, NEW.version_no, NEW.kind, NEW.title, NEW.body, NEW.is_active, v, COALESCE(app_current_member_id(), NEW.updated_by));
    RETURN NULL;
END$$;

-- ---------------------------------------------------------------------------------------------
-- Grants re-stated (CREATE OR REPLACE keeps them; this makes the file read whole): the views to the read role and the writer,
-- the settings' new columns through the writer's table grant (db/005), the section function as db/013 had it.
-- ---------------------------------------------------------------------------------------------
GRANT SELECT ON mcp_settings, mcp_question_feedback TO knowledge_records_ro, knowledge_rw;
GRANT EXECUTE ON FUNCTION kn_section_markdown(bigint) TO knowledge_rw, knowledge_records_ro;

COMMIT;
