-- Phase 0 proof of the schema (docs/knowledge-design.md §10). Everything happens inside one transaction that is ROLLED BACK:
-- fixtures written as knowledge_rw, reads as the two read roles, acting members set through app.member_id exactly as PHP and
-- the MCP servers set it. Fixtures are synthetic (no third-party text). Run as postgres on a SCRATCH database — never the
-- installed one — after loading every migration in order:
--   sudo -n -u postgres createdb knowledge_p0_scratch
--   for f in db/0*.sql; do sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q -d knowledge_p0_scratch -f "$f" || break; done
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d knowledge_p0_scratch -f db/proof/phase0_proof.sql; echo "exit $?"
--   sudo -n -u postgres dropdb knowledge_p0_scratch
--   # the three roles are cluster-wide: drop them only if they did not exist before (db/001 creates them idempotently)
--   sudo -n -u postgres psql -c 'DROP ROLE IF EXISTS knowledge_rw, knowledge_records_ro, knowledge_activity_ro'
-- Prints one line per check (ok / FAIL) and the count; exits non-zero when any check failed.
\set QUIET on
\o /dev/null
BEGIN;
CREATE TEMP TABLE proof (n serial, ok boolean, label text);
GRANT ALL ON proof TO PUBLIC; GRANT ALL ON SEQUENCE proof_n_seq TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.check(b boolean, l text) RETURNS void LANGUAGE sql AS $$ INSERT INTO proof (ok, label) VALUES (COALESCE(b, false), l) $$;
CREATE OR REPLACE FUNCTION pg_temp.refused(p_sql text, p_like text, l text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    BEGIN
        EXECUTE p_sql;
        INSERT INTO proof (ok, label) VALUES (false, l || ' (was NOT refused)');
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO proof (ok, label) VALUES (SQLERRM ILIKE '%' || p_like || '%', l || CASE WHEN SQLERRM ILIKE '%' || p_like || '%' THEN '' ELSE ' (said: ' || SQLERRM || ')' END);
    END;
END$$;
CREATE OR REPLACE FUNCTION pg_temp.as_member(p bigint) RETURNS void LANGUAGE sql AS $$ SELECT set_config('app.member_id', COALESCE(p::text, ''), true) $$;
CREATE TEMP TABLE ids (k text PRIMARY KEY, i bigint);
GRANT ALL ON ids TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.i(k text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT i FROM ids WHERE ids.k = $1 $$;
CREATE OR REPLACE FUNCTION pg_temp.keep(k text, v bigint) RETURNS void LANGUAGE sql AS $$ INSERT INTO ids (k, i) VALUES ($1, $2) ON CONFLICT (k) DO UPDATE SET i = EXCLUDED.i $$;
GRANT EXECUTE ON FUNCTION pg_temp.check(boolean, text), pg_temp.refused(text, text, text), pg_temp.as_member(bigint), pg_temp.i(text), pg_temp.keep(text, bigint) TO PUBLIC;

-- ---------------------------------------------------------------- fixtures, as the writer: every actor of §2
SET ROLE knowledge_rw;
SELECT pg_temp.as_member(1);
INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Owner',   'owner@example.invalid', 'super_admin', false, 'active', 'admin', '{admin}'),     -- the Knowledge admin
 (26, 'human', 'SMOKE Priya',   'priya@example.invalid', 'user', false, 'active', 'write', '{user}'),             -- a Member
 (27, 'human', 'SMOKE Marco',   'marco@example.invalid', 'user', false, 'active', 'write', '{curator,user}'),     -- a Curator
 (28, 'human', 'SMOKE Bea',     'bea@example.invalid',   'user', false, 'active', 'read',  '{reader}'),           -- a Reader inside
 (29, 'human', 'SMOKE Ann',     'ann@client.invalid',    'user', true,  'active', 'read',  '{reader}'),           -- a guest (external, Reader)
 (30, 'human', 'SMOKE Dana',    'dana@example.invalid',  'user', false, 'active', 'write', '{user}'),             -- Engineering
 (31, 'human', 'SMOKE Lee',     'lee@example.invalid',   'user', false, 'active', 'write', '{}'),                 -- Engineering, roles not yet sent
 (40, 'agent', 'SMOKE Seamus',  NULL,                    'user', false, 'active', 'write', '{user}'),             -- an agent granted Member
 (41, 'agent', 'SMOKE Watcher', NULL,                    'user', false, 'active', NULL,    '{}'),                 -- an agent not admitted
 (42, 'agent', 'SMOKE Archivist', NULL,                  'user', false, 'active', 'write', '{user}');
INSERT INTO departments (id, name, is_system, system_key, manager_member_id) VALUES
 (3, 'Accounting', true, 'accounting', NULL), (5, 'IT', true, 'it', 1), (7, 'Design', false, NULL, 27), (8, 'Engineering', false, NULL, NULL);
INSERT INTO department_members (member_id, department_id, is_admin, is_primary) VALUES
 (1, 5, true, true), (26, 7, false, true), (27, 7, true, true), (28, 3, false, true), (30, 8, false, true), (31, 8, false, true);
UPDATE kn_settings SET business_name = 'SMOKE Business';

-- ---------------------------------------------------------------- the mirror's appends and the acting member (§6.4)
SELECT pg_temp.check((SELECT is_agent FROM members WHERE id = 40) AND NOT (SELECT is_agent FROM members WHERE id = 26), 'members.is_agent follows member_kind (generated)');
SELECT pg_temp.refused($$UPDATE members SET is_agent = true WHERE id = 26$$, 'only be updated to DEFAULT', 'is_agent cannot be written');
SELECT pg_temp.check(kn_current_member_id() = 1 AND app_current_member_id() = 1, 'kn_current_member_id() is app_current_member_id()');

-- ---------------------------------------------------------------- actors and rights (§2, §3, D2)
SELECT pg_temp.as_member(1);
SELECT pg_temp.check(kn_is_admin() AND kn_has_right('settings.manage') AND kn_has_right('model.manage') AND kn_has_right('bases.read') AND NOT kn_is_guest(), 'the super-admin holds the admin role: every right, not a guest');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(kn_has_right('bases.read') AND kn_has_right('bases.create') AND kn_has_right('sources.add') AND kn_has_right('questions.own') AND NOT kn_has_right('base.manage') AND NOT kn_has_right('keys.manage') AND NOT kn_is_admin(), 'a Member reads, creates a base, adds sources, keeps questions; cannot manage or mint');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check(kn_has_right('base.manage') AND kn_has_right('sources.manage') AND kn_has_right('builds.run') AND kn_has_right('base.share') AND kn_has_right('keys.manage') AND kn_has_right('answers.pin') AND kn_has_right('questions.review') AND kn_has_right('feeds.manage') AND NOT kn_has_right('settings.manage') AND NOT kn_has_right('keys.all'), 'a Curator holds the curating rights and none of the admin''s');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check(kn_has_right('bases.read') AND kn_has_right('answers.rate') AND NOT kn_has_right('bases.create') AND NOT kn_is_guest() AND kn_is_member_here(), 'a Reader inside reads and rates, creates nothing, is a member here');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check(kn_is_guest() AND kn_has_right('bases.read') AND NOT kn_has_right('bases.create') AND NOT kn_is_member_here(), 'a guest (external + Reader) reads what is named, is not a member here');
SELECT pg_temp.as_member(31);
SELECT pg_temp.check(kn_has_right('bases.create') AND NOT kn_has_right('base.manage'), 'a member whose roles have not arrived is read from the capability (write = Member)');
SELECT pg_temp.as_member(40);
SELECT pg_temp.check(kn_has_right('bases.create') AND app_member_kind() = 'agent' AND NOT kn_is_guest() AND kn_is_member_here(), 'an agent granted Member has a Member''s rights and belongs here');
SELECT pg_temp.as_member(41);
SELECT pg_temp.check(NOT app_is_active_member() AND NOT kn_has_right('bases.read') AND (SELECT count(*) FROM kn_visible_base_ids()) = 0, 'an agent not yet admitted sees nothing and may do nothing');
SELECT pg_temp.as_member(NULL);
SELECT pg_temp.check((SELECT count(*) FROM kn_visible_base_ids()) = 0 AND NOT kn_has_right('bases.read') AND kn_base_level(1) = 'none', 'no acting member: every rule denies');
SELECT pg_temp.check((SELECT count(*) FROM mcp_app_roles) = 4 AND (SELECT count(*) FROM mcp_app_roles WHERE is_admin) = 1
    AND (SELECT rights FROM mcp_app_roles WHERE role_key = 'reader') = '{bases.read,answers.rate}'
    AND (SELECT capability FROM mcp_app_roles WHERE role_key = 'user') = 'write'
    AND (SELECT cardinality(rights) FROM mcp_app_roles WHERE role_key = 'admin') = (SELECT count(*) FROM kn_rights), 'app_roles answers the four roles, one admin, Reader with two rights, Member keyed user/write');

-- ---------------------------------------------------------------- settings and the shipped template (§6.2, §6.3)
SELECT pg_temp.as_member(1);
SELECT pg_temp.check((SELECT default_monthly_text_mb = 50 AND max_source_bytes = 52428800 AND question_retention_days = 365 AND key_rate_per_minute = 30 AND key_rate_per_day = 2000 AND pin_review_months = 6 FROM kn_settings), 'the settings row is seeded with the design''s defaults (50 MB/month, 50 MB/file, 365 days, 30/min, 2,000/day, 6 months)');
SELECT pg_temp.refused($$INSERT INTO kn_settings (id) VALUES (2)$$, 'violates check', 'the settings are one row');
SELECT pg_temp.check((SELECT count(*) FROM base_templates WHERE key = 'general' AND is_active) = 1
    AND (SELECT jsonb_array_length(guidelines) FROM base_templates WHERE key = 'general') = 4
    AND (SELECT array_agg(g->>'kind' ORDER BY g->>'kind') FROM base_templates t, jsonb_array_elements(t.guidelines) g WHERE t.key = 'general') = '{answering,audience,exclusion,extraction}', 'the general template ships four guidelines: extraction, exclusion, answering, audience');
SELECT pg_temp.refused($$INSERT INTO base_templates (key, name, guidelines) VALUES ('bad', 'Bad', '[{"kind":"nonsense","body":"x"}]')$$, 'violates check', 'a template''s guideline set names known kinds only');

-- ---------------------------------------------------------------- bases: private and internal, the namespace, the template copy (§3, §6; D5)
SELECT pg_temp.as_member(27);
WITH ins AS (INSERT INTO knowledge_bases (name, description, owner_member_id) VALUES ('SMOKE Product manuals', 'Manuals for the widgets', 27) RETURNING id) SELECT pg_temp.keep('P', id) FROM ins;
SELECT pg_temp.check((SELECT namespace = 'kb:' || id AND key = 'kb-' || id AND visibility = 'private' AND status = 'empty' AND guideline_version = 1 AND monthly_text_mb_limit = 50 AND template_version = 1 AND created_by = 27 FROM knowledge_bases WHERE id = pg_temp.i('P')), 'a new base: namespace kb:<id>, key defaulted, PRIVATE, empty, version 1, the settings'' limit, the template''s version');
SELECT pg_temp.check((SELECT count(*) FROM guidelines WHERE base_id = pg_temp.i('P') AND origin = 'template') = 4 AND (SELECT count(*) FROM guideline_versions WHERE base_id = pg_temp.i('P')) = 4
    AND (SELECT guideline_version FROM knowledge_bases WHERE id = pg_temp.i('P')) = 1, 'the template''s four guidelines are copied as the base''s own rows (versioned) without bumping the base''s version');
SELECT pg_temp.refused($$UPDATE knowledge_bases SET namespace = 'kb:999' WHERE id = pg_temp.i('P')$$, 'set once', 'a base''s namespace is set once');
SELECT pg_temp.refused($$INSERT INTO knowledge_bases (name, owner_member_id, namespace) VALUES ('SMOKE Bad', 27, 'kb:777')$$, 'set once', 'a namespace cannot be chosen');
SELECT pg_temp.refused($$INSERT INTO knowledge_bases (name, owner_member_id, visibility) VALUES ('SMOKE Bad', 27, 'public')$$, 'violates check', 'visibility is private or internal (CHECK)');
SELECT pg_temp.refused($$INSERT INTO knowledge_bases (name, owner_member_id, sensitivity) VALUES ('SMOKE Bad', 27, 'secret')$$, 'violates check', 'sensitivity is standard, confidential or restricted (CHECK)');
SELECT pg_temp.refused($$INSERT INTO knowledge_bases (name, owner_member_id, key) VALUES ('SMOKE Bad', 27, 'Not A Key')$$, 'violates check', 'a base key is a slug (CHECK)');
SELECT pg_temp.refused($$INSERT INTO knowledge_bases (name, owner_member_id, template) VALUES ('SMOKE Bad', 27, 'contracts')$$, 'No such template', 'a base names a shipped template');
SELECT pg_temp.as_member(26);
WITH ins AS (INSERT INTO knowledge_bases (name, owner_member_id, visibility, key) VALUES ('SMOKE Company handbook', 26, 'internal', 'handbook') RETURNING id) SELECT pg_temp.keep('I', id) FROM ins;
SELECT pg_temp.check((SELECT count(*) FROM base_members WHERE base_id = pg_temp.i('I') AND principal_kind = 'everyone' AND level = 'read') = 1, 'an internal base carries the everyone principal (read), written by the visibility trigger');
SELECT pg_temp.refused($$INSERT INTO base_members (base_id, principal_kind) VALUES (pg_temp.i('P'), 'everyone')$$, 'follows the base', 'the everyone row cannot be written directly (set visibility)');
SELECT pg_temp.refused($$DELETE FROM base_members WHERE base_id = pg_temp.i('I') AND principal_kind = 'everyone'$$, 'follows the base', 'nor deleted directly');
UPDATE knowledge_bases SET visibility = 'private' WHERE id = pg_temp.i('I');
SELECT pg_temp.check((SELECT count(*) FROM base_members WHERE base_id = pg_temp.i('I') AND principal_kind = 'everyone') = 0, 'private again: the everyone row goes');
UPDATE knowledge_bases SET visibility = 'internal' WHERE id = pg_temp.i('I');
SELECT pg_temp.check((SELECT count(*) FROM base_members WHERE base_id = pg_temp.i('I') AND principal_kind = 'everyone') = 1, 'internal again: it is back');
SELECT pg_temp.as_member(27);
WITH ins AS (INSERT INTO knowledge_bases (name, owner_member_id) VALUES ('SMOKE Marco notes', 27) RETURNING id) SELECT pg_temp.keep('X', id) FROM ins;
-- shares on P: Priya read, Engineering curate, the agent read, the sibling Help Desk read, the guest Ann read
INSERT INTO base_members (base_id, principal_kind, principal_id, level) VALUES (pg_temp.i('P'), 'member', 26, 'read');
INSERT INTO base_members (base_id, principal_kind, principal_id, level) VALUES (pg_temp.i('P'), 'department', 8, 'curate');
INSERT INTO base_members (base_id, principal_kind, principal_id, level) VALUES (pg_temp.i('P'), 'agent', 40, 'read');
INSERT INTO base_members (base_id, principal_kind, principal_ref, level) VALUES (pg_temp.i('P'), 'application', 'helpdesk', 'read');
INSERT INTO base_members (base_id, principal_kind, principal_id, level) VALUES (pg_temp.i('P'), 'member', 29, 'read');
SELECT pg_temp.check((SELECT count(*) FROM base_members WHERE base_id = pg_temp.i('P')) = 5 AND (SELECT bool_and(granted_by = 27) FROM base_members WHERE base_id = pg_temp.i('P')), 'five principals named on the private base, each recording who granted');
SELECT pg_temp.refused($$INSERT INTO base_members (base_id, principal_kind, principal_id, level) VALUES (pg_temp.i('P'), 'member', 26, 'curate')$$, 'duplicate key', 'one row per principal and base');
SELECT pg_temp.refused($$INSERT INTO base_members (base_id, principal_kind, principal_id) VALUES (pg_temp.i('P'), 'member', 40)$$, 'is a person', 'a member principal is a person (an agent goes as principal_kind agent)');
SELECT pg_temp.refused($$INSERT INTO base_members (base_id, principal_kind, principal_id) VALUES (pg_temp.i('P'), 'agent', 26)$$, 'agent member', 'an agent principal is an agent');
SELECT pg_temp.refused($$INSERT INTO base_members (base_id, principal_kind, principal_id) VALUES (pg_temp.i('P'), 'department', 99)$$, 'No such department', 'a department principal is a department of the mirror');
SELECT pg_temp.refused($$INSERT INTO base_members (base_id, principal_kind, principal_ref, level) VALUES (pg_temp.i('P'), 'application', 'projects', 'curate')$$, 'violates check', 'an application principal reads, never curates');
SELECT pg_temp.refused($$INSERT INTO base_members (base_id, principal_kind, principal_id, principal_ref) VALUES (pg_temp.i('P'), 'application', 5, 'projects')$$, 'violates check', 'an application principal is a catalog key, not an id');
SELECT pg_temp.refused($$INSERT INTO base_members (base_id, principal_kind, principal_ref) VALUES (pg_temp.i('P'), 'application', 'Help Desk!')$$, 'violates check', 'a catalog key is [a-z0-9_]');
SELECT pg_temp.check((SELECT array_agg(x) FROM kn_application_base_ids('helpdesk') x) = ARRAY[pg_temp.i('P')] AND (SELECT count(*) FROM kn_application_base_ids('projects')) = 0, 'K7: Help Desk reaches the one base shared with it; Projects none');

-- the seven actors on the private base P, the internal base I and the unshared base X (§3)
SELECT pg_temp.as_member(1);  SELECT pg_temp.check(kn_base_level(pg_temp.i('P')) = 'own' AND kn_base_level(pg_temp.i('I')) = 'own' AND kn_base_level(pg_temp.i('X')) = 'own' AND (SELECT count(*) FROM kn_visible_base_ids()) = 3, 'the admin: every base, private ones included (D2; PHP logs base.admin_view)');
SELECT pg_temp.as_member(26); SELECT pg_temp.check(kn_base_level(pg_temp.i('P')) = 'read' AND kn_base_level(pg_temp.i('I')) = 'own' AND kn_base_level(pg_temp.i('X')) = 'none' AND kn_can_read_base(pg_temp.i('P')) AND NOT kn_can_curate_base(pg_temp.i('P')), 'Priya (Member): reads the private base she was named on, owns the handbook, nothing of Marco''s notes');
SELECT pg_temp.as_member(27); SELECT pg_temp.check(kn_base_level(pg_temp.i('P')) = 'own' AND kn_base_level(pg_temp.i('I')) = 'read' AND kn_base_level(pg_temp.i('X')) = 'own' AND kn_can_curate_base(pg_temp.i('P')) AND NOT kn_can_curate_base(pg_temp.i('I')), 'Marco (Curator): owns his two, reads the internal one through everyone, curates it not');
SELECT pg_temp.as_member(28); SELECT pg_temp.check(kn_base_level(pg_temp.i('P')) = 'none' AND kn_base_level(pg_temp.i('I')) = 'read' AND NOT kn_can_read_base(pg_temp.i('P')) AND (SELECT count(*) FROM kn_visible_base_ids()) = 1, 'Bea (Reader inside): the internal base only');
SELECT pg_temp.as_member(29); SELECT pg_temp.check(kn_base_level(pg_temp.i('P')) = 'read' AND kn_base_level(pg_temp.i('I')) = 'none' AND (SELECT count(*) FROM kn_visible_base_ids()) = 1, 'Ann (guest): the private base she was named on, NEVER the internal one');
SELECT pg_temp.as_member(30); SELECT pg_temp.check(kn_base_level(pg_temp.i('P')) = 'curate' AND kn_base_level(pg_temp.i('I')) = 'read' AND kn_can_curate_base(pg_temp.i('P')), 'Dana (Engineering): curates the private base through her department (live through the mirror), reads the internal one');
SELECT pg_temp.as_member(40); SELECT pg_temp.check(kn_base_level(pg_temp.i('P')) = 'read' AND kn_base_level(pg_temp.i('I')) = 'read' AND kn_base_level(pg_temp.i('X')) = 'none', 'Seamus (agent, Member): reads the base shared to him and the internal one — what his member id may see');
SELECT pg_temp.as_member(41); SELECT pg_temp.check(kn_base_level(pg_temp.i('P')) = 'none' AND kn_base_level(pg_temp.i('I')) = 'none', 'an unadmitted agent: none');
SELECT pg_temp.as_member(NULL); SELECT pg_temp.check(kn_base_level(pg_temp.i('I')) = 'none' AND NOT kn_can_read_base(pg_temp.i('I')), 'anonymous: none, even on an internal base');
-- a department share is live: Lee leaves Engineering and loses it
SELECT pg_temp.as_member(31); SELECT pg_temp.check(kn_base_level(pg_temp.i('P')) = 'curate', 'Lee (Engineering) curates P through the department');
UPDATE department_members SET left_at = now() WHERE member_id = 31 AND department_id = 8;
SELECT pg_temp.check(kn_base_level(pg_temp.i('P')) = 'none', '…and loses it the moment the directory says he left (dated, never erased)');
-- archiving keeps reach for those who had it; nothing new goes in
SELECT pg_temp.as_member(27);
UPDATE knowledge_bases SET archived_at = now() WHERE id = pg_temp.i('X');
SELECT pg_temp.refused($$INSERT INTO sources (base_id, kind, title) VALUES (pg_temp.i('X'), 'text', 'SMOKE late')$$, 'archived', 'an archived base takes no sources');
SELECT pg_temp.refused($$INSERT INTO builds (base_id) VALUES (pg_temp.i('X'))$$, 'archived', 'an archived base is not built');
SELECT pg_temp.check(kn_can_read_base(pg_temp.i('X')), 'the owner still reaches an archived base (to restore it)');
UPDATE knowledge_bases SET archived_at = NULL WHERE id = pg_temp.i('X');

-- ---------------------------------------------------------------- a source with pages and sections (§6.1; D6)
SELECT pg_temp.as_member(27);
WITH ins AS (INSERT INTO sources (base_id, kind, title, origin, mime_type) VALUES (pg_temp.i('P'), 'file', 'SMOKE Widget manual', 'widget-manual.pdf', 'application/pdf') RETURNING id) SELECT pg_temp.keep('S1', id) FROM ins;
SELECT pg_temp.check((SELECT status = 'uploaded' AND added_by = 27 AND char_count = 0 AND page_count = 0 FROM sources WHERE id = pg_temp.i('S1')), 'a new source is uploaded, by the caller, with no text yet');
INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by)
VALUES ('source', pg_temp.i('S1'), 'widget-manual.pdf', 'application/pdf', 20480, repeat('a', 64), 'storage/sources/1/1/widget-manual.pdf', 27);
UPDATE sources SET attachment_id = (SELECT id FROM attachments WHERE record_id = pg_temp.i('S1')) WHERE id = pg_temp.i('S1');
SELECT pg_temp.refused($$INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path) VALUES ('invoice', 1, 'x', 'text/plain', 1, 'c', 'p')$$, 'violates check', 'an attachment hangs on a source only (GL canonical, record_type widened)');
UPDATE sources SET status = 'extracting_text' WHERE id = pg_temp.i('S1');
INSERT INTO source_text (source_id, text, extractor) VALUES (pg_temp.i('S1'),
 E'Widget Manual\n\n1 Setup\nUnbox the widget and connect the power lead. The green light means ready.\n\n1.1 Mounting\nMount the bracket with the four screws supplied. Torque to two newton metres.\n\n2 Warranty\nThe battery is covered for twenty-four months from purchase. Water damage is excluded.\n\n3 Returns\nA widget may be returned within thirty days in its box.', 'pdftotext');
INSERT INTO source_pages (source_id, page_no, char_from, char_to) VALUES (pg_temp.i('S1'), 1, 0, 100), (pg_temp.i('S1'), 2, 101, 230), (pg_temp.i('S1'), 3, 231, 400);
SELECT pg_temp.check((SELECT char_count > 300 AND page_count = 3 AND text_sha256 ~ '^[0-9a-f]{64}$' FROM sources WHERE id = pg_temp.i('S1')), 'the text''s facts follow the text: char_count, three pages, sha256');
SELECT pg_temp.refused($$INSERT INTO source_pages (source_id, page_no, char_from, char_to) VALUES (pg_temp.i('S1'), 4, 50, 10)$$, 'violates check', 'a page anchor runs forward');
UPDATE sources SET status = 'sectioning' WHERE id = pg_temp.i('S1');
WITH ins AS (INSERT INTO sections (source_id, heading_path, heading, level, position, kind, text, char_from, char_to, page_no) VALUES
  (pg_temp.i('S1'), 'Widget Manual › 1 Setup', '1 Setup', 1, 0, 'heading', 'Unbox the widget and connect the power lead. The green light means ready.', 15, 95, 1) RETURNING id) SELECT pg_temp.keep('sec1', id) FROM ins;
WITH ins AS (INSERT INTO sections (source_id, heading_path, heading, level, parent_id, position, kind, text, char_from, char_to, page_no) VALUES
  (pg_temp.i('S1'), 'Widget Manual › 1 Setup › 1.1 Mounting', '1.1 Mounting', 2, pg_temp.i('sec1'), 1, 'heading', 'Mount the bracket with the four screws supplied. Torque to two newton metres.', 110, 190, 2) RETURNING id) SELECT pg_temp.keep('sec2', id) FROM ins;
WITH ins AS (INSERT INTO sections (source_id, heading_path, heading, level, position, kind, text, char_from, char_to, page_no) VALUES
  (pg_temp.i('S1'), 'Widget Manual › 2 Warranty', '2 Warranty', 1, 2, 'heading', 'The battery is covered for twenty-four months from purchase. Water damage is excluded.', 200, 300, 2) RETURNING id) SELECT pg_temp.keep('sec3', id) FROM ins;
WITH ins AS (INSERT INTO sections (source_id, heading_path, heading, level, position, kind, text, char_from, char_to, page_no) VALUES
  (pg_temp.i('S1'), 'Widget Manual › 3 Returns', '3 Returns', 1, 3, 'heading', 'A widget may be returned within thirty days in its box.', 310, 380, 3) RETURNING id) SELECT pg_temp.keep('sec4', id) FROM ins;
UPDATE sources SET status = 'ready' WHERE id = pg_temp.i('S1');
SELECT pg_temp.check((SELECT sections_count = 4 AND status = 'ready' FROM sources WHERE id = pg_temp.i('S1')) AND (SELECT bool_and(base_id = pg_temp.i('P') AND status = 'queued') FROM sections WHERE source_id = pg_temp.i('S1')), 'four sections, queued, each stamped with the source''s base; the source counts them');
SELECT pg_temp.check((SELECT parent_id = pg_temp.i('sec1') AND level = 2 FROM sections WHERE id = pg_temp.i('sec2')), 'a section has a parent and a level (the outline)');
SELECT pg_temp.refused($$INSERT INTO sections (base_id, source_id, heading_path, position, text, char_from, char_to) VALUES (pg_temp.i('I'), pg_temp.i('S1'), 'x', 9, 'x', 0, 1)$$, 'same base', 'a section belongs to a source of the same base');
SELECT pg_temp.refused($$INSERT INTO sections (source_id, heading_path, position, text, char_from, char_to) VALUES (pg_temp.i('S1'), 'x', 0, 'x', 0, 1)$$, 'duplicate key', 'one section per position in a source');
SELECT pg_temp.refused($$INSERT INTO sections (source_id, heading_path, position, kind, text, char_from, char_to) VALUES (pg_temp.i('S1'), 'x', 9, 'chapter', 'x', 0, 1)$$, 'violates check', 'a section''s kind is heading, part, slide, sheet or rows');
SELECT pg_temp.refused($$INSERT INTO sections (source_id, heading_path, position, text, char_from, char_to) VALUES (pg_temp.i('S1'), 'x', 9, 'x', 10, 5)$$, 'violates check', 'a section''s char range runs forward');
SELECT pg_temp.refused($$INSERT INTO sections (source_id, heading_path, position, parent_id, text, char_from, char_to) VALUES (pg_temp.i('S1'), 'x', 9, 999999, 'x', 0, 1)$$, 'same source', 'a section''s parent is a section of the same source');
SELECT pg_temp.refused($$INSERT INTO sources (base_id, kind, title) VALUES (pg_temp.i('P'), 'video', 'SMOKE clip')$$, 'violates check', 'a source kind is file, text, url, feed or note');
SELECT pg_temp.refused($$INSERT INTO sources (base_id, kind, title) VALUES (pg_temp.i('P'), 'url', 'SMOKE page')$$, 'violates check', 'a URL source names its URL');
SELECT pg_temp.refused($$UPDATE sources SET base_id = pg_temp.i('I') WHERE id = pg_temp.i('S1')$$, 'never moves', 'a source never moves between bases');
INSERT INTO base_templates (key, name, guidelines) VALUES ('smoke_second', 'SMOKE second', '[]');
SELECT pg_temp.refused($$UPDATE knowledge_bases SET template = 'smoke_second' WHERE id = pg_temp.i('P')$$, 'only while it is empty', 'a base with a source cannot switch template');
UPDATE knowledge_bases SET template = 'smoke_second' WHERE id = pg_temp.i('X');
SELECT pg_temp.check((SELECT template = 'smoke_second' FROM knowledge_bases WHERE id = pg_temp.i('X')), 'an empty base may switch template');
-- a second source in the internal base, pasted text, one part
SELECT pg_temp.as_member(26);
WITH ins AS (INSERT INTO sources (base_id, kind, title, origin) VALUES (pg_temp.i('I'), 'text', 'SMOKE Leave policy', 'paste') RETURNING id) SELECT pg_temp.keep('S2', id) FROM ins;
INSERT INTO source_text (source_id, text, extractor) VALUES (pg_temp.i('S2'), 'Leave carries over up to five days. Sick leave needs a note after three days.', 'paste');
INSERT INTO source_pages (source_id, page_no, char_from, char_to) VALUES (pg_temp.i('S2'), 1, 0, 80);
WITH ins AS (INSERT INTO sections (source_id, heading_path, heading, level, position, kind, text, char_from, char_to, page_no) VALUES
  (pg_temp.i('S2'), 'SMOKE Leave policy', '', 0, 0, 'part', 'Leave carries over up to five days. Sick leave needs a note after three days.', 0, 80, 1) RETURNING id) SELECT pg_temp.keep('sec5', id) FROM ins;
UPDATE sources SET status = 'ready' WHERE id = pg_temp.i('S2');
-- notes
INSERT INTO notes (record_type, record_id, member_id, body) VALUES ('source', pg_temp.i('S2'), 26, 'Checked against the handbook of 2026.');
SELECT pg_temp.refused($$INSERT INTO notes (record_type, record_id, member_id, body) VALUES ('invoice', 1, 26, 'x')$$, 'violates check', 'a note sits on a base, a source or a section');
SELECT pg_temp.check(kn_record_base_id('source', pg_temp.i('S2')) = pg_temp.i('I') AND kn_record_base_id('section', pg_temp.i('sec1')) = pg_temp.i('P') AND kn_record_base_id('base', pg_temp.i('P')) = pg_temp.i('P'), 'a note''s or file''s base is found through its record');

-- ---------------------------------------------------------------- guidelines: the two moments (§6.2; D7)
SELECT pg_temp.as_member(27);
SELECT pg_temp.keep('v0', (SELECT guideline_version FROM knowledge_bases WHERE id = pg_temp.i('P')));
-- first, pretend a build extracted everything (the real build is proved below): mark sections extracted through a build
INSERT INTO builds (base_id, kind) VALUES (pg_temp.i('P'), 'extract');
SELECT pg_temp.keep('B0', (SELECT max(id) FROM builds WHERE base_id = pg_temp.i('P')));
SELECT pg_temp.check(kn_build_queue_items(pg_temp.i('B0')) = 4, 'a build queues the four queued sections as items');
UPDATE builds SET status = 'running' WHERE id = pg_temp.i('B0');
UPDATE build_items SET status = 'done' WHERE build_id = pg_temp.i('B0');
UPDATE builds SET status = 'done', subjects_added = 12, statements_added = 30 WHERE id = pg_temp.i('B0');
SELECT pg_temp.check((SELECT count(*) FROM sections WHERE source_id = pg_temp.i('S1') AND status = 'extracted' AND extracted_version = pg_temp.i('v0')) = 4
    AND (SELECT status = 'ready' AND last_built_at IS NOT NULL FROM knowledge_bases WHERE id = pg_temp.i('P')), 'after a finished build the sections are extracted under the version and the base is ready');
UPDATE guidelines SET body = 'Answer in one paragraph; cite the manual section.' WHERE base_id = pg_temp.i('P') AND kind = 'answering';
SELECT pg_temp.check((SELECT guideline_version = pg_temp.i('v0') AND status = 'ready' FROM knowledge_bases WHERE id = pg_temp.i('P'))
    AND (SELECT count(*) FROM sections WHERE base_id = pg_temp.i('P') AND status = 'stale') = 0, 'an ANSWERING save changes nothing of the graph: same version, still ready, nothing stale');
UPDATE guidelines SET body = 'Field technicians on a phone.' WHERE base_id = pg_temp.i('P') AND kind = 'audience';
SELECT pg_temp.check((SELECT guideline_version = pg_temp.i('v0') FROM knowledge_bases WHERE id = pg_temp.i('P')), 'an AUDIENCE save: the same');
SELECT pg_temp.check((SELECT count(*) FROM guideline_versions gv JOIN guidelines g ON g.id = gv.guideline_id WHERE g.base_id = pg_temp.i('P') AND g.kind = 'answering') = 2
    AND (SELECT version_no FROM guidelines WHERE base_id = pg_temp.i('P') AND kind = 'answering') = 2, 'yet every save keeps its words: the answering guideline is at version 2 with two version rows');
UPDATE guidelines SET body = 'Extract every part number, torque value, duration and the owner of each step.' WHERE base_id = pg_temp.i('P') AND kind = 'extraction';
SELECT pg_temp.check((SELECT guideline_version = pg_temp.i('v0') + 1 AND status = 'needs_rebuild' FROM knowledge_bases WHERE id = pg_temp.i('P'))
    AND (SELECT count(*) FROM sections WHERE base_id = pg_temp.i('P') AND status = 'stale') = 4, 'an EXTRACTION save bumps the version, marks the four extracted sections STALE and the base needs_rebuild');
SELECT pg_temp.check((SELECT base_guideline_version FROM guideline_versions gv JOIN guidelines g ON g.id = gv.guideline_id WHERE g.base_id = pg_temp.i('P') AND g.kind = 'extraction' ORDER BY gv.version_no DESC LIMIT 1) = pg_temp.i('v0') + 1, 'the version row records the base version it produced ("built under N")');
UPDATE guidelines SET position = 9 WHERE base_id = pg_temp.i('P') AND kind = 'extraction';
SELECT pg_temp.check((SELECT guideline_version = pg_temp.i('v0') + 1 FROM knowledge_bases WHERE id = pg_temp.i('P')) AND (SELECT version_no FROM guidelines WHERE base_id = pg_temp.i('P') AND kind = 'extraction') = 2, 'a position change is not a save: no version, no bump');
DELETE FROM guidelines WHERE base_id = pg_temp.i('P') AND kind = 'exclusion';
SELECT pg_temp.check((SELECT guideline_version = pg_temp.i('v0') + 2 FROM knowledge_bases WHERE id = pg_temp.i('P')), 'deleting an EXCLUSION guideline bumps the version too');
INSERT INTO guidelines (base_id, kind, title, body) VALUES (pg_temp.i('P'), 'exclusion', 'Ignore', 'Ignore the safety boilerplate on every page.');
SELECT pg_temp.check((SELECT guideline_version = pg_temp.i('v0') + 3 FROM knowledge_bases WHERE id = pg_temp.i('P')) AND (SELECT origin = 'person' AND created_by = 27 FROM guidelines WHERE base_id = pg_temp.i('P') AND kind = 'exclusion'), 'a person''s new exclusion guideline bumps it again and is theirs (origin person)');
SELECT pg_temp.refused($$INSERT INTO guidelines (base_id, kind, body) VALUES (pg_temp.i('P'), 'tone', 'x')$$, 'violates check', 'a guideline is one of five kinds (CHECK)');
SELECT pg_temp.refused($$INSERT INTO guidelines (base_id, kind, body) VALUES (pg_temp.i('P'), 'answering', '')$$, 'violates check', 'a guideline has words');
SELECT pg_temp.refused($$UPDATE guidelines SET base_id = pg_temp.i('I') WHERE base_id = pg_temp.i('P') AND kind = 'exclusion'$$, 'never moves', 'a guideline never moves between bases');
-- the internal base is untouched by all this
SELECT pg_temp.check((SELECT guideline_version = 1 AND status = 'empty' FROM knowledge_bases WHERE id = pg_temp.i('I')), 'another base''s version is untouched');

-- ---------------------------------------------------------------- vocabulary (§6.2)
SELECT pg_temp.keep('v1', (SELECT guideline_version FROM knowledge_bases WHERE id = pg_temp.i('P')));
WITH ins AS (INSERT INTO vocabulary_terms (base_id, canonical_name, aliases, subject_type, description) VALUES (pg_temp.i('P'), 'Widget', '{the widget,WDG-1}', 'product', 'The thing itself') RETURNING id) SELECT pg_temp.keep('T1', id) FROM ins;
SELECT pg_temp.check((SELECT guideline_version = pg_temp.i('v1') + 1 FROM knowledge_bases WHERE id = pg_temp.i('P')), 'a vocabulary term bumps the version (the extractor is handed new names)');
UPDATE vocabulary_terms SET maludb_subject_id = 'subj_1' WHERE id = pg_temp.i('T1');
SELECT pg_temp.check((SELECT guideline_version = pg_temp.i('v1') + 1 FROM knowledge_bases WHERE id = pg_temp.i('P')), 'the worker recording the seeded subject id shapes nothing');
UPDATE vocabulary_terms SET aliases = '{the widget,WDG-1,WDG1}' WHERE id = pg_temp.i('T1');
SELECT pg_temp.check((SELECT guideline_version = pg_temp.i('v1') + 2 FROM knowledge_bases WHERE id = pg_temp.i('P')), 'a new alias does');
INSERT INTO vocabulary_terms (base_id, canonical_name, subject_type) VALUES (pg_temp.i('P'), 'Torque wrench', 'tool');
SELECT pg_temp.refused($$INSERT INTO vocabulary_terms (base_id, canonical_name) VALUES (pg_temp.i('P'), 'widget')$$, 'duplicate key', 'one canonical name per base, case-insensitive');
SELECT pg_temp.refused($$INSERT INTO vocabulary_terms (base_id, canonical_name, subject_type) VALUES (pg_temp.i('P'), 'Bracket', 'A Type')$$, 'violates check', 'a subject type is a slug');

-- ---------------------------------------------------------------- focus areas (§6.2; D8)
WITH ins AS (INSERT INTO focus_areas (base_id, name, description, seed_terms, example_questions) VALUES (pg_temp.i('P'), 'Warranty & Returns', 'Coverage, exclusions, claims, returns', '{warranty,returns}', '{How long is the battery warranty?}') RETURNING id) SELECT pg_temp.keep('A1', id) FROM ins;
SELECT pg_temp.check((SELECT slug = 'warranty-returns' AND status = 'active' AND created_by = 27 FROM focus_areas WHERE id = pg_temp.i('A1')), 'an area gets a slug from its name and is active');
SELECT pg_temp.as_member(40);
WITH ins AS (INSERT INTO focus_areas (base_id, name, status, proposal_note) VALUES (pg_temp.i('P'), 'Setup', 'proposed', 'A community of nine subjects about setup and mounting') RETURNING id) SELECT pg_temp.keep('A2', id) FROM ins;
SELECT pg_temp.check((SELECT status = 'proposed' AND proposed_by = 40 AND decided_at IS NULL FROM focus_areas WHERE id = pg_temp.i('A2')), 'the expert proposes an area; it records who proposed');
SELECT pg_temp.as_member(27);
UPDATE focus_areas SET status = 'active' WHERE id = pg_temp.i('A2');
SELECT pg_temp.check((SELECT status = 'active' AND decided_by = 27 AND decided_at IS NOT NULL FROM focus_areas WHERE id = pg_temp.i('A2')), 'a person accepts it in one click: decided_by and when');
SELECT pg_temp.refused($$INSERT INTO focus_areas (base_id, name, slug) VALUES (pg_temp.i('P'), 'Other', 'setup')$$, 'duplicate key', 'one slug per base');
SELECT pg_temp.refused($$INSERT INTO focus_areas (base_id, name, status) VALUES (pg_temp.i('I'), 'Odd', 'draft')$$, 'violates check', 'an area is proposed, active or archived');
INSERT INTO source_focus_areas (source_id, focus_area_id) VALUES (pg_temp.i('S1'), pg_temp.i('A1'));
SELECT pg_temp.refused($$INSERT INTO source_focus_areas (source_id, focus_area_id) VALUES (pg_temp.i('S2'), pg_temp.i('A1'))$$, 'own base', 'a source is assigned to an area of its own base');
SELECT pg_temp.check((SELECT count(*) FROM kn_empty_areas(pg_temp.i('P'))) = 1 AND (SELECT name FROM kn_empty_areas(pg_temp.i('P'))) = 'Setup', 'empty_areas: the accepted Setup area has no source yet');
SELECT pg_temp.check((SELECT count(*) FROM kn_unassigned_sources(pg_temp.i('P'))) = 0, 'unassigned_sources: the manual is in an area');
SELECT pg_temp.check((SELECT array_agg(canonical_name) FROM kn_unused_terms(pg_temp.i('P'))) = '{"Torque wrench"}', 'unused_terms: "Torque wrench" appears in no section; "Widget" does');

-- ---------------------------------------------------------------- the build: one at a time, items, failures, cancel, counters, the base's status (§6.2; D9)
SELECT pg_temp.as_member(30);   -- Dana curates P through Engineering
WITH ins AS (INSERT INTO builds (base_id, kind) VALUES (pg_temp.i('P'), 'extract') RETURNING id) SELECT pg_temp.keep('B1', id) FROM ins;
SELECT pg_temp.check((SELECT status = 'queued' AND started_by = 30 AND guideline_version = (SELECT guideline_version FROM knowledge_bases WHERE id = pg_temp.i('P')) FROM builds WHERE id = pg_temp.i('B1')), 'a build is queued by its starter under the base''s current version');
SELECT pg_temp.check((SELECT status FROM knowledge_bases WHERE id = pg_temp.i('P')) = 'building', 'the base is building while a build is queued or running');
SELECT pg_temp.refused($$INSERT INTO builds (base_id, kind) VALUES (pg_temp.i('P'), 'rebuild_all')$$, 'One build per base', 'ONE build per base at a time (the second is refused)');
SELECT pg_temp.refused($$INSERT INTO builds (base_id, kind) VALUES (pg_temp.i('X'), 'nonsense')$$, 'violates check', 'a build kind is extract, rebuild_all, reindex, embed or forget');
INSERT INTO builds (base_id, kind) VALUES (pg_temp.i('I'), 'extract');
SELECT pg_temp.keep('BI', (SELECT max(id) FROM builds WHERE base_id = pg_temp.i('I')));
SELECT pg_temp.check((SELECT count(*) FROM builds WHERE status IN ('queued', 'running')) = 2, 'another base may build at the same time');
SELECT pg_temp.keep('queued', kn_build_queue_items(pg_temp.i('B1')));
SELECT pg_temp.check(pg_temp.i('queued') = 4 AND (SELECT items_total FROM builds WHERE id = pg_temp.i('B1')) = 4, 'the stale sections are queued as items and counted');
SELECT pg_temp.check(kn_build_queue_items(pg_temp.i('B1')) = 0, 'queuing again adds nothing (idempotent)');
SELECT pg_temp.refused($$INSERT INTO build_items (build_id, section_id) VALUES (pg_temp.i('B1'), pg_temp.i('sec5'))$$, 'build''s base', 'an item names a section of the build''s base');
SELECT pg_temp.refused($$UPDATE builds SET status = 'done' WHERE id = pg_temp.i('B1')$$, 'starts or is cancelled', 'a queued build cannot jump to done');
UPDATE builds SET status = 'running' WHERE id = pg_temp.i('B1');
SELECT pg_temp.check((SELECT started_at IS NOT NULL FROM builds WHERE id = pg_temp.i('B1')), 'running stamps started_at');
UPDATE build_items SET status = 'running' WHERE build_id = pg_temp.i('B1') AND section_id = pg_temp.i('sec1');
UPDATE build_items SET status = 'done', usage = '{"input_tokens": 400, "output_tokens": 120}' WHERE build_id = pg_temp.i('B1') AND section_id = pg_temp.i('sec1');
UPDATE build_items SET status = 'done' WHERE build_id = pg_temp.i('B1') AND section_id = pg_temp.i('sec2');
UPDATE build_items SET status = 'failed', error = 'the engine answered 502' WHERE build_id = pg_temp.i('B1') AND section_id = pg_temp.i('sec3');
SELECT pg_temp.check((SELECT items_done = 2 AND items_failed = 1 AND chars_processed = (SELECT sum(length(text)) FROM sections WHERE id IN (pg_temp.i('sec1'), pg_temp.i('sec2'))) FROM builds WHERE id = pg_temp.i('B1')), 'the build''s counters follow its items: 2 done, 1 failed, the characters of the done ones');
SELECT pg_temp.check((SELECT status = 'extracted' AND extracted_version = (SELECT guideline_version FROM builds WHERE id = pg_temp.i('B1')) AND extracted_at IS NOT NULL FROM sections WHERE id = pg_temp.i('sec1'))
    AND (SELECT status = 'failed' AND error LIKE '%502%' FROM sections WHERE id = pg_temp.i('sec3')), 'a done item makes its section extracted under the build''s version; a failed item makes it failed with the error');
SELECT pg_temp.refused($$UPDATE build_items SET status = 'queued' WHERE build_id = pg_temp.i('B1') AND section_id = pg_temp.i('sec1')$$, 'stays done', 'a finished item stays finished');
SELECT pg_temp.check((SELECT (kn_build_progress(pg_temp.i('B1'))->>'percent')::int = 75 AND (kn_build_progress(pg_temp.i('B1'))->>'items_queued')::int = 1 AND jsonb_array_length(kn_build_progress(pg_temp.i('B1'))->'failed_items') = 1), 'build_progress: 75% with one queued and one failed item named');
UPDATE builds SET status = 'done' WHERE id = pg_temp.i('B1');
SELECT pg_temp.check((SELECT status FROM build_items WHERE build_id = pg_temp.i('B1') AND section_id = pg_temp.i('sec4')) = 'skipped' AND (SELECT status FROM sections WHERE id = pg_temp.i('sec4')) = 'stale', 'an item never sent is skipped when the build is declared done; its section stays stale');
SELECT pg_temp.check((SELECT status = 'needs_rebuild' FROM knowledge_bases WHERE id = pg_temp.i('P')), 'the base is needs_rebuild while a stale section remains');
SELECT pg_temp.refused($$UPDATE builds SET status = 'running' WHERE id = pg_temp.i('B1')$$, 'stays done', 'a finished build stays finished');
SELECT pg_temp.refused($$INSERT INTO build_items (build_id, section_id) VALUES (pg_temp.i('B1'), pg_temp.i('sec4'))$$, 'finished build takes no items', 'a finished build takes no items');
-- cancel the other
UPDATE builds SET status = 'cancelled' WHERE id = pg_temp.i('BI');
SELECT pg_temp.check((SELECT status = 'cancelled' AND finished_at IS NOT NULL FROM builds WHERE id = pg_temp.i('BI')) AND (SELECT status FROM knowledge_bases WHERE id = pg_temp.i('I')) = 'empty', 'a queued build is cancelled; the never-built base is empty again');
-- a second build picks up the stale and the failed one
WITH ins AS (INSERT INTO builds (base_id, kind) VALUES (pg_temp.i('P'), 'extract') RETURNING id) SELECT pg_temp.keep('B2', id) FROM ins;
SELECT pg_temp.check(kn_build_queue_items(pg_temp.i('B2')) = 2, 'the next build queues exactly the stale and the failed section');
UPDATE builds SET status = 'running' WHERE id = pg_temp.i('B2');
UPDATE build_items SET status = 'done' WHERE build_id = pg_temp.i('B2');
UPDATE builds SET status = 'done' WHERE id = pg_temp.i('B2');
SELECT pg_temp.check((SELECT status = 'ready' FROM knowledge_bases WHERE id = pg_temp.i('P')) AND (SELECT count(*) FROM sections WHERE base_id = pg_temp.i('P') AND status = 'extracted') = 4, 'after it, every section is extracted and the base is ready');
SELECT pg_temp.check((SELECT (kn_build_estimate(pg_temp.i('P'))->>'sections')::int = 0 AND (kn_build_estimate(pg_temp.i('P'), 'rebuild_all')->>'sections')::int = 4 AND (kn_build_estimate(pg_temp.i('P'))->>'within_limit')::boolean), 'build_estimate: nothing to do for extract, four for rebuild_all, within the monthly limit');
SELECT kn_usage_add(pg_temp.i('P'), 'extract', 6, 2400, 700, 52428700);
SELECT pg_temp.check((SELECT (kn_build_estimate(pg_temp.i('P'), 'rebuild_all')->>'within_limit')::boolean = false AND (kn_build_estimate(pg_temp.i('P'), 'rebuild_all')->>'remaining_chars')::bigint = 100), 'build_estimate against the limit: with 52,428,700 characters spent this month (100 left of 50 MB) a rebuild would exceed it');
SELECT pg_temp.check((SELECT calls = 6 AND chars = 52428700 FROM kn_usage(pg_temp.i('P')) WHERE kind = 'extract'), 'base_usage: the month''s extract calls and characters');
-- notifications from the builds: Dana started B1; the curators (Marco the owner, Dana and Lee? Lee left) get the bell
SELECT pg_temp.check((SELECT count(*) FROM notifications WHERE kind = 'build_done' AND record_id = pg_temp.i('B1')) = 2
    AND (SELECT array_agg(member_id ORDER BY member_id) FROM notifications WHERE kind = 'build_done' AND record_id = pg_temp.i('B1')) = '{27,30}', 'a finished build tells the base''s curators (the owner and the department''s live members; not Lee who left, not the agent)');
SELECT pg_temp.check((SELECT count(*) FROM notification_outbox WHERE kind = 'build_done' AND channel = 'email' AND status = 'queued') >= 2 AND (SELECT count(*) FROM notification_outbox WHERE channel = 'text') = 0, 'and queues one email each (nobody chose texts)');

-- ---------------------------------------------------------------- removing and replacing a source (§6.1)
SELECT pg_temp.as_member(27);
WITH ins AS (INSERT INTO sources (base_id, kind, title, origin) VALUES (pg_temp.i('P'), 'file', 'SMOKE Widget manual (2nd edition)', 'widget-manual-2.pdf') RETURNING id) SELECT pg_temp.keep('S1b', id) FROM ins;
UPDATE sections SET maludb_document_id = 'doc_' || id WHERE source_id = pg_temp.i('S1');
UPDATE sources SET superseded_by = pg_temp.i('S1b') WHERE id = pg_temp.i('S1');
SELECT pg_temp.check((SELECT status = 'forgotten' AND forgotten_at IS NOT NULL AND sections_count = 0 FROM sources WHERE id = pg_temp.i('S1'))
    AND (SELECT count(*) FROM sections WHERE source_id = pg_temp.i('S1') AND status = 'forgotten' AND maludb_document_id IS NOT NULL) = 4, 'a replaced source is forgotten with its four sections (their engine ids kept for the worker''s DELETE)');
SELECT pg_temp.check((SELECT status FROM knowledge_bases WHERE id = pg_temp.i('P')) = 'empty', 'with every section forgotten the base is empty again');
SELECT pg_temp.refused($$UPDATE sources SET status = 'ready' WHERE id = pg_temp.i('S1')$$, 'stays forgotten', 'a forgotten source never comes back');
SELECT pg_temp.refused($$INSERT INTO sections (source_id, heading_path, position, text, char_from, char_to) VALUES (pg_temp.i('S1'), 'x', 9, 'x', 0, 1)$$, 'forgotten source takes no sections', 'a forgotten source takes no sections');
SELECT pg_temp.refused($$UPDATE sections SET status = 'queued' WHERE id = pg_temp.i('sec1')$$, 'stays forgotten', 'a forgotten section stays forgotten');
SELECT pg_temp.refused($$UPDATE sources SET superseded_by = pg_temp.i('S2') WHERE id = pg_temp.i('S1b')$$, 'same base', 'a source is replaced by a source of the same base');
SELECT pg_temp.refused($$UPDATE sources SET superseded_by = id WHERE id = pg_temp.i('S1b')$$, 'violates check', 'never by itself');
-- re-cut the replacement
INSERT INTO source_text (source_id, text, extractor) VALUES (pg_temp.i('S1b'), E'Widget Manual, second edition\n\n2 Warranty\nThe battery is covered for thirty-six months from purchase. Water damage is excluded.\n\n3 Returns\nA widget may be returned within sixty days in its box.', 'pdftotext');
INSERT INTO source_pages (source_id, page_no, char_from, char_to) VALUES (pg_temp.i('S1b'), 1, 0, 120), (pg_temp.i('S1b'), 2, 121, 220);
WITH ins AS (INSERT INTO sections (source_id, heading_path, heading, level, position, kind, text, char_from, char_to, page_no) VALUES
  (pg_temp.i('S1b'), 'Widget Manual › 2 Warranty', '2 Warranty', 1, 0, 'heading', 'The battery is covered for thirty-six months from purchase. Water damage is excluded.', 40, 120, 1) RETURNING id) SELECT pg_temp.keep('sec6', id) FROM ins;
WITH ins AS (INSERT INTO sections (source_id, heading_path, heading, level, position, kind, text, char_from, char_to, page_no) VALUES
  (pg_temp.i('S1b'), 'Widget Manual › 3 Returns', '3 Returns', 1, 1, 'heading', 'A widget may be returned within sixty days in its box.', 130, 200, 2) RETURNING id) SELECT pg_temp.keep('sec7', id) FROM ins;
UPDATE sources SET status = 'ready' WHERE id = pg_temp.i('S1b');
INSERT INTO source_focus_areas (source_id, focus_area_id) VALUES (pg_temp.i('S1b'), pg_temp.i('A1'));
WITH ins AS (INSERT INTO builds (base_id) VALUES (pg_temp.i('P')) RETURNING id) SELECT pg_temp.keep('B3', id) FROM ins;
SELECT pg_temp.check(kn_build_queue_items(pg_temp.i('B3')) = 2, 'the replacement''s two sections are what the next build takes (forgotten ones never)');
UPDATE builds SET status = 'running' WHERE id = pg_temp.i('B3'); UPDATE build_items SET status = 'done' WHERE build_id = pg_temp.i('B3'); UPDATE builds SET status = 'done' WHERE id = pg_temp.i('B3');
SELECT pg_temp.check((SELECT status = 'ready' FROM knowledge_bases WHERE id = pg_temp.i('P')), 'built: ready');
SELECT pg_temp.check((SELECT count(*) FROM kn_source_outline(pg_temp.i('S1b'))) = 2 AND (SELECT count(*) FROM kn_source_outline(pg_temp.i('S1'))) = 0, 'source_outline lists the live sections, none of a forgotten source');
SELECT pg_temp.check(kn_section_markdown(pg_temp.i('sec6')) LIKE '# Widget Manual › 2 Warranty%thirty-six months%— SMOKE Widget manual (2nd edition), page 1 (section %', 'section_markdown: heading path, text, source and page');

-- ---------------------------------------------------------------- questions, citations, feedback, a pin becoming a note source (§6.3; D10)
SELECT pg_temp.as_member(26);   -- Priya, a Reader of P
WITH ins AS (INSERT INTO questions (base_id, focus_area_id, door, question, answer_md, status, confidence, conversation_id, turn_no, model, latency_ms)
  VALUES (pg_temp.i('P'), pg_temp.i('A1'), 'web', 'How long is the battery warranty?', 'The battery is covered for **thirty-six months** from purchase; water damage is excluded.', 'answered', 0.91, 'conv-1', 1, 'smoke-model', 1200) RETURNING id)
  SELECT pg_temp.keep('Q1', id) FROM ins;
SELECT pg_temp.check((SELECT asked_by_member_id = 26 AND guideline_version = (SELECT guideline_version FROM knowledge_bases WHERE id = pg_temp.i('P')) FROM questions WHERE id = pg_temp.i('Q1')), 'a question records its asker and the base''s version at the time');
INSERT INTO citations (question_id, rank, section_id, quote, maludb_chunk_id, verified) VALUES (pg_temp.i('Q1'), 1, pg_temp.i('sec6'), 'covered for thirty-six months from purchase', 'chunk_9', true);
SELECT pg_temp.check((SELECT source_id = pg_temp.i('S1b') AND page_no = 1 FROM citations WHERE question_id = pg_temp.i('Q1')), 'a citation''s source and page follow its section');
SELECT pg_temp.refused($$INSERT INTO citations (question_id, rank, section_id, quote) VALUES (pg_temp.i('Q1'), 2, pg_temp.i('sec5'), 'x')$$, 'question''s base', 'a citation names a section of the question''s base');
SELECT pg_temp.refused($$INSERT INTO citations (question_id, rank, section_id, quote) VALUES (pg_temp.i('Q1'), 1, pg_temp.i('sec7'), 'x')$$, 'duplicate key', 'one citation per rank');
SELECT pg_temp.refused($$INSERT INTO questions (base_id, door, question, status) VALUES (pg_temp.i('P'), 'web', 'x', 'guessed')$$, 'violates check', 'a question''s status is one of six');
SELECT pg_temp.refused($$INSERT INTO questions (base_id, door, question, status) VALUES (pg_temp.i('P'), 'phone', 'x', 'answered')$$, 'violates check', 'a door is web, api, mcp, share or agent');
SELECT pg_temp.refused($$INSERT INTO questions (base_id, focus_area_id, door, question, status) VALUES (pg_temp.i('I'), pg_temp.i('A1'), 'web', 'x', 'no_evidence')$$, 'own base', 'a question is scoped to an area of its own base');
SELECT pg_temp.refused($$UPDATE questions SET question = 'another' WHERE id = pg_temp.i('Q1')$$, 'never change', 'a question''s words never change');
INSERT INTO question_feedback (question_id, rating, comment) VALUES (pg_temp.i('Q1'), 'up', 'Exactly right');
SELECT pg_temp.check((SELECT member_id = 26 FROM question_feedback WHERE question_id = pg_temp.i('Q1')), 'feedback records who gave it');
SELECT pg_temp.refused($$INSERT INTO question_feedback (question_id, rating) VALUES (pg_temp.i('Q1'), 'down')$$, 'duplicate key', 'one rating per member and question');
SELECT pg_temp.refused($$INSERT INTO question_feedback (question_id, member_id, rating) VALUES (pg_temp.i('Q1'), 30, 'meh')$$, 'violates check', 'a rating is up or down');
-- gaps and more questions
INSERT INTO questions (base_id, door, question, status) VALUES
 (pg_temp.i('P'), 'web', 'Can I return a widget after ninety days?', 'no_evidence'),
 (pg_temp.i('P'), 'web', 'What is the returns address?', 'no_evidence'),
 (pg_temp.i('P'), 'web', 'Do returns need the original box?', 'unverified'),
 (pg_temp.i('P'), 'web', 'Where is the spare parts price list?', 'no_evidence');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check((SELECT cluster_key = 'return' AND question_count = 3 FROM kn_question_gaps(pg_temp.i('P')) ORDER BY question_count DESC LIMIT 1), 'question_gaps clusters the three unanswered questions about returns');
SELECT pg_temp.check((SELECT count(*) FROM kn_question_gaps(pg_temp.i('P'), now() + interval '1 hour')) = 0, 'question_gaps honours since');
-- the pin
SELECT pg_temp.keep('pin', kn_pin_answer(pg_temp.i('Q1'), NULL, 'Battery warranty'));
SELECT pg_temp.check((SELECT owner_member_id = 27 AND review_until = (current_date + interval '6 months')::date AND status = 'active' AND source_id IS NOT NULL AND note_id IS NOT NULL FROM pinned_answers WHERE id = pg_temp.i('pin')), 'a pin has an owner (the caller), a review date six months out, a note and a source');
SELECT pg_temp.check((SELECT kind = 'note' AND status = 'sectioning' AND origin = 'pin:' || pg_temp.i('Q1') AND title = 'Battery warranty' AND added_by = 27 FROM sources WHERE id = (SELECT source_id FROM pinned_answers WHERE id = pg_temp.i('pin'))), 'the pin''s source is of kind note, ready to be cut (sectioning), titled by the pin');
SELECT pg_temp.check((SELECT text LIKE '# Battery warranty%**Question:** How long is the battery warranty?%thirty-six months%' FROM source_text WHERE source_id = (SELECT source_id FROM pinned_answers WHERE id = pg_temp.i('pin'))), 'its text is the question and the answer');
SELECT pg_temp.check((SELECT record_type = 'base' AND record_id = pg_temp.i('P') AND member_id = 27 FROM notes WHERE id = (SELECT note_id FROM pinned_answers WHERE id = pg_temp.i('pin'))), 'the note sits on the base');
SELECT pg_temp.check((SELECT count(*) FROM source_focus_areas WHERE source_id = (SELECT source_id FROM pinned_answers WHERE id = pg_temp.i('pin')) AND focus_area_id = pg_temp.i('A1')) = 1, 'the note source inherits the question''s area');
SELECT pg_temp.refused($$SELECT kn_pin_answer(pg_temp.i('Q1'))$$, 'already pinned', 'an answer is pinned once');
SELECT pg_temp.refused($$SELECT kn_pin_answer((SELECT id FROM questions WHERE status = 'no_evidence' LIMIT 1))$$, 'answered question', 'only an answered question is pinned');
-- retention keeps the pinned question, purges the rest
SELECT pg_temp.keep('purged', kn_purge_questions('-1 second'));
SELECT pg_temp.check(pg_temp.i('purged') = 4 AND (SELECT count(*) FROM questions WHERE id = pg_temp.i('Q1')) = 1, 'retention purges the unpinned questions and keeps the pinned one');
UPDATE pinned_answers SET status = 'retired', retired_at = now(), retired_by = 27 WHERE id = pg_temp.i('pin');
SELECT pg_temp.check((SELECT status = 'forgotten' FROM sources WHERE id = (SELECT source_id FROM pinned_answers WHERE id = pg_temp.i('pin'))), 'retiring a pin forgets its note source');
SELECT pg_temp.keep('purged', kn_purge_questions('-1 second'));
SELECT pg_temp.check(pg_temp.i('purged') = 1 AND (SELECT count(*) FROM pinned_answers WHERE id = pg_temp.i('pin') AND question_id IS NULL AND answer_md LIKE '%thirty-six%') = 1, 'with the pin retired the question goes; the pin keeps its words');
-- fresh questions for the views below (as three askers and one key, later)
SELECT pg_temp.as_member(26); INSERT INTO questions (base_id, door, question, answer_md, status) VALUES (pg_temp.i('P'), 'web', 'SMOKE Priya asks about mounting', 'Four screws.', 'answered');
SELECT pg_temp.as_member(28); INSERT INTO questions (base_id, door, question, answer_md, status) VALUES (pg_temp.i('I'), 'web', 'SMOKE Bea asks about leave', 'Five days.', 'answered');
SELECT pg_temp.as_member(40); INSERT INTO questions (base_id, door, question, status) VALUES (pg_temp.i('P'), 'agent', 'SMOKE Seamus asks about torque', 'no_evidence');
INSERT INTO questions (base_id, door, question, application_key, status, asked_by_member_id) VALUES (pg_temp.i('P'), 'share', 'SMOKE Help Desk asks about returns', 'helpdesk', 'evidence_only', NULL);
SELECT pg_temp.check((SELECT asked_by_member_id = 40 FROM questions WHERE door = 'share'), 'a K7 question carries the application key (and the acting member the kernel ran as)');
SELECT pg_temp.refused($$INSERT INTO questions (base_id, door, question, application_key, status) VALUES (pg_temp.i('P'), 'share', 'x', NULL, 'no_evidence')$$, 'violates check', 'a share question names its application');

-- ---------------------------------------------------------------- keys: minted, bound, counted, the 31st refused, the day cap, rotation (§4; D12)
SELECT pg_temp.as_member(27);
WITH ins AS (INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, base_id) VALUES (27, 'SMOKE Widget site', encode(digest('mcp_' || encode(gen_random_bytes(24), 'hex'), 'sha256'), 'hex'), 'api', pg_temp.i('P')) RETURNING id) SELECT pg_temp.keep('K1', id) FROM ins;
SELECT pg_temp.check((SELECT rate_per_minute = 30 AND rate_per_day = 2000 AND focus_area_id IS NULL FROM mcp_access_tokens WHERE id = pg_temp.i('K1')), 'a key takes the settings'' limits: 30/min, 2,000/day');
SELECT pg_temp.check(kn_key_reaches_base(pg_temp.i('K1'), pg_temp.i('P')) AND NOT kn_key_reaches_base(pg_temp.i('K1'), pg_temp.i('I')), 'a key reaches its one base and no other');
SELECT pg_temp.refused($$INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (27, 'SMOKE loose', repeat('b', 64), 'api')$$, 'violates check', 'a key is always bound to a base');
SELECT pg_temp.refused($$INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, base_id) VALUES (27, 'SMOKE mcp', repeat('c', 64), 'mcp', pg_temp.i('P'))$$, 'violates check', 'a person''s mcp token is never bound');
SELECT pg_temp.refused($$INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, base_id, focus_area_id) VALUES (27, 'SMOKE wrong area', repeat('d', 64), 'api', pg_temp.i('I'), pg_temp.i('A1'))$$, 'area of its base', 'a key''s area is an area of its base');
SELECT pg_temp.refused($$UPDATE mcp_access_tokens SET base_id = pg_temp.i('I') WHERE id = pg_temp.i('K1')$$, 'for life', 'a key is bound for life');
SELECT pg_temp.refused($$INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, base_id, rate_per_minute) VALUES (27, 'SMOKE zero', repeat('e', 64), 'api', pg_temp.i('P'), 0)$$, 'violates check', 'a rate is at least one a minute');
-- thirty calls pass, the thirty-first is refused with Retry-After, the day is not charged for it
SELECT pg_temp.check((SELECT bool_and(ok) AND max(minute_calls) = 30 AND max(day_calls) = 30 FROM (SELECT (kn_rate_ok(pg_temp.i('K1'))).* FROM generate_series(1, 30)) t), 'thirty calls in a minute pass, counted into both buckets');
SELECT pg_temp.check((SELECT NOT ok AND limit_hit = 'minute' AND retry_after BETWEEN 1 AND 60 AND minute_calls = 30 AND day_calls = 30 FROM kn_rate_ok(pg_temp.i('K1'))), 'the 31st call in the minute is REFUSED (429) with a Retry-After and is not charged to the day');
SELECT pg_temp.check((SELECT calls = 30 AND refused = 1 FROM key_usage WHERE token_id = pg_temp.i('K1') AND bucket_kind = 'minute') AND kn_key_calls_today(pg_temp.i('K1')) = 30, 'the minute bucket remembers 30 calls and 1 refusal; today''s count is 30');
SELECT pg_temp.check((SELECT ok AND minute_calls = 1 AND day_calls = 31 FROM kn_rate_ok(pg_temp.i('K1'), now() + interval '1 minute')), 'the next minute opens again; the day keeps counting');
-- the day cap on a second key
WITH ins AS (INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, base_id, focus_area_id, rate_per_minute, rate_per_day) VALUES (27, 'SMOKE Kiosk', repeat('f', 64), 'api', pg_temp.i('P'), pg_temp.i('A1'), 100, 5) RETURNING id) SELECT pg_temp.keep('K2', id) FROM ins;
SELECT pg_temp.check((SELECT bool_and(ok) FROM (SELECT (kn_rate_ok(pg_temp.i('K2'))).* FROM generate_series(1, 5)) t) AND (SELECT NOT ok AND limit_hit = 'day' AND retry_after > 60 FROM kn_rate_ok(pg_temp.i('K2'))), 'five calls on a 5/day key pass; the sixth is refused for the day');
-- a key's question
INSERT INTO questions (base_id, door, question, answer_md, status, token_id, asked_by_member_id) VALUES (pg_temp.i('P'), 'api', 'SMOKE the widget site asks about returns', 'Sixty days.', 'answered', pg_temp.i('K1'), NULL);
SELECT pg_temp.check((SELECT asked_by_member_id = 27 FROM questions WHERE token_id = pg_temp.i('K1')), 'a key''s question is logged under the key and its minter');
SELECT pg_temp.refused($$INSERT INTO questions (base_id, door, question, status, token_id) VALUES (pg_temp.i('I'), 'api', 'x', 'no_evidence', pg_temp.i('K1'))$$, 'one base it is bound to', 'a key asks the one base it is bound to');
SELECT pg_temp.refused($$INSERT INTO questions (base_id, door, question, status) VALUES (pg_temp.i('P'), 'api', 'x', 'no_evidence')$$, 'violates check', 'an api question names its key');
-- rotation and resolution
SELECT pg_temp.keep('K1b', kn_key_rotate(pg_temp.i('K1'), repeat('9', 64)));
SELECT pg_temp.check((SELECT rotated_from = pg_temp.i('K1') AND base_id = pg_temp.i('P') AND label = 'SMOKE Widget site' AND rate_per_minute = 30 AND expires_at IS NULL FROM mcp_access_tokens WHERE id = pg_temp.i('K1b'))
    AND (SELECT expires_at BETWEEN now() + interval '23 hours' AND now() + interval '25 hours' FROM mcp_access_tokens WHERE id = pg_temp.i('K1')), 'rotation: a new key of the same base, label and limits; the old one keeps 24 hours');
SELECT pg_temp.check((SELECT token_id = pg_temp.i('K1b') AND base_id = pg_temp.i('P') AND member_id = 27 FROM kn_resolve_key(repeat('9', 64))), 'kn_resolve_key answers the new key by its hash');
SELECT pg_temp.check((SELECT count(*) FROM kn_resolve_key(repeat('f', 64))) = 1 AND (SELECT focus_area_id = pg_temp.i('A1') FROM kn_resolve_key(repeat('f', 64))), 'and the kiosk key with its area');
UPDATE mcp_access_tokens SET revoked_at = now() WHERE id = pg_temp.i('K2');
SELECT pg_temp.check((SELECT count(*) FROM kn_resolve_key(repeat('f', 64))) = 0 AND NOT kn_key_reaches_base(pg_temp.i('K2'), pg_temp.i('P')) AND (SELECT NOT ok AND limit_hit = 'revoked' FROM kn_rate_ok(pg_temp.i('K2'))), 'a revoked key resolves to nothing, reaches nothing, is refused');
SELECT pg_temp.refused($$SELECT kn_key_rotate(pg_temp.i('K2'), repeat('8', 64))$$, 'live key', 'only a live key is rotated');
SELECT pg_temp.check((SELECT count(*) FROM kn_resolve_key(repeat('9', 64))) = 1, 'a key resolves while its minter is admitted…');
UPDATE members SET capability = NULL WHERE id = 27;
SELECT pg_temp.check((SELECT count(*) FROM kn_resolve_key(repeat('9', 64))) = 0, '…and not once the kernel revoked the minter''s grant (a revoked grant shuts the API door)');
UPDATE members SET capability = 'write' WHERE id = 27;
-- a person's mcp token still resolves the contract way
INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (26, 'SMOKE my laptop', repeat('1', 64), 'mcp');
SELECT pg_temp.check((SELECT member_id = 26 AND member_kind = 'human' FROM mcp_resolve_token(repeat('1', 64), 'mcp')) AND (SELECT count(*) FROM mcp_resolve_token(repeat('9', 64), 'mcp')) = 0, 'mcp_resolve_token answers a person''s mcp token, never a key');
SELECT pg_temp.check(kn_prune_key_usage() = 0, 'pruning the counters removes nothing fresh');

-- ---------------------------------------------------------------- feeds (§8)
SELECT pg_temp.as_member(27);
WITH ins AS (INSERT INTO feeds (base_id, provider, tool, arguments, focus_area_id, every_minutes) VALUES (pg_temp.i('P'), 'helpdesk', 'articles_published', '{"category": "widgets"}', pg_temp.i('A1'), 60) RETURNING id) SELECT pg_temp.keep('F1', id) FROM ins;
SELECT pg_temp.check((SELECT enabled AND items = 0 AND last_status IS NULL AND created_by = 27 FROM feeds WHERE id = pg_temp.i('F1')), 'a feed follows a sibling''s shared tool, by a curator');
SELECT pg_temp.refused($$INSERT INTO feeds (base_id, provider, tool, arguments) VALUES (pg_temp.i('P'), 'helpdesk', 'articles_published', '{"category": "widgets"}')$$, 'duplicate key', 'one feed per tool and arguments on a base');
SELECT pg_temp.refused($$INSERT INTO feeds (base_id, provider, tool, focus_area_id) VALUES (pg_temp.i('I'), 'spaces', 'pages_index', pg_temp.i('A1'))$$, 'area of its base', 'a feed''s area is an area of its base');
SELECT pg_temp.refused($$INSERT INTO feeds (base_id, provider, tool, every_minutes) VALUES (pg_temp.i('P'), 'spaces', 'pages_index', 1)$$, 'violates check', 'a feed syncs at most every five minutes');
INSERT INTO sources (base_id, kind, title, origin, feed_id, feed_item_key) VALUES (pg_temp.i('P'), 'feed', 'SMOKE KB article 7', 'feed:helpdesk.articles_published#7', pg_temp.i('F1'), '7');
INSERT INTO sources (base_id, kind, title, origin, feed_id, feed_item_key) VALUES (pg_temp.i('P'), 'feed', 'SMOKE KB article 8', 'feed:helpdesk.articles_published#8', pg_temp.i('F1'), '8');
SELECT pg_temp.check((SELECT items FROM feeds WHERE id = pg_temp.i('F1')) = 2, 'a feed counts its live sources');
SELECT pg_temp.refused($$INSERT INTO sources (base_id, kind, title, feed_id, feed_item_key) VALUES (pg_temp.i('P'), 'feed', 'SMOKE dup', pg_temp.i('F1'), '7')$$, 'duplicate key', 'a re-sync updates rather than duplicates (one live source per provider id)');
UPDATE sources SET status = 'forgotten' WHERE feed_id = pg_temp.i('F1') AND feed_item_key = '8';
SELECT pg_temp.check((SELECT items FROM feeds WHERE id = pg_temp.i('F1')) = 1, 'a forgotten feed source is no longer counted');
UPDATE feeds SET last_sync_at = now(), last_status = 'no_connection', last_error = 'no approved connection to helpdesk' WHERE id = pg_temp.i('F1');
SELECT pg_temp.check((SELECT next_sync_at = now() + interval '60 minutes' AND last_status = 'no_connection' FROM feeds WHERE id = pg_temp.i('F1')), 'a sync records its status (no_connection degrades, never fails) and schedules the next');

-- ---------------------------------------------------------------- dispatches and the Archivist's proposals (§5)
SELECT pg_temp.as_member(27);
WITH ins AS (INSERT INTO agent_dispatches (agent_member_id, kind, via, build_id) VALUES (40, 'propose_areas', 'chat', pg_temp.i('B3')) RETURNING id) SELECT pg_temp.keep('D1', id) FROM ins;
SELECT pg_temp.check((SELECT base_id = pg_temp.i('P') AND acting_member_id = 27 AND status = 'sent' FROM agent_dispatches WHERE id = pg_temp.i('D1')), 'a dispatch after a build names the base through the build and who caused it');
SELECT pg_temp.refused($$INSERT INTO agent_dispatches (agent_member_id, kind, via) VALUES (26, 'ask', 'chat')$$, 'goes to an agent', 'a dispatch goes to an agent, never a person');
SELECT pg_temp.refused($$INSERT INTO agent_dispatches (agent_member_id, kind, via) VALUES (40, 'mention', 'chat')$$, 'violates check', 'a dispatch kind is ask, propose_areas, draft_guidelines or report');
UPDATE agent_dispatches SET status = 'answered', reply_excerpt = repeat('x', 300), answered_at = now() WHERE id = pg_temp.i('D1');
SELECT pg_temp.check((SELECT length(reply_excerpt) = 200 FROM agent_dispatches WHERE id = pg_temp.i('D1')), 'a reply excerpt is cut at 200 characters');
SELECT pg_temp.as_member(42);   -- the Archivist
WITH ins AS (INSERT INTO archivist_proposals (base_id, kind, title, reason, payload) VALUES (pg_temp.i('P'), 'gap', 'Three questions about returns; no source covers returns after thirty days', 'Clustered from the question log', '{"questions": 3}') RETURNING id) SELECT pg_temp.keep('AP1', id) FROM ins;
INSERT INTO archivist_proposals (base_id, kind, subject_type, subject_id, title, reason) VALUES (pg_temp.i('P'), 'stale_source', 'source', pg_temp.i('S1b'), 'A source is stale against the guideline version', 'Built under an older version');
SELECT pg_temp.check((SELECT proposed_by = 42 AND status = 'proposed' AND decided_at IS NULL FROM archivist_proposals WHERE id = pg_temp.i('AP1')), 'a proposal is the Archivist''s, open');
SELECT pg_temp.refused($$INSERT INTO archivist_proposals (base_id, kind, subject_type, subject_id, title, reason) VALUES (pg_temp.i('P'), 'stale_source', 'source', pg_temp.i('S1b'), 'again', 'x')$$, 'duplicate key', 'one open proposal per kind and subject');
SELECT pg_temp.refused($$INSERT INTO archivist_proposals (base_id, kind, title, reason) VALUES (pg_temp.i('P'), 'delete_everything', 'x', 'x')$$, 'violates check', 'a proposal kind is one of the list');
SELECT pg_temp.check((SELECT count(*) FROM notifications WHERE kind = 'proposal' AND record_id = pg_temp.i('AP1')) = 2, 'a proposal rings the curators'' bell (Marco, Dana)');
SELECT pg_temp.as_member(27);
UPDATE archivist_proposals SET status = 'accepted' WHERE id = pg_temp.i('AP1');
SELECT pg_temp.check((SELECT status = 'accepted' AND decided_by = 27 AND decided_at IS NOT NULL FROM archivist_proposals WHERE id = pg_temp.i('AP1')), 'a curator accepts it: decided_by and when');
SELECT pg_temp.refused($$UPDATE archivist_proposals SET status = 'dismissed' WHERE id = pg_temp.i('AP1')$$, 'stays accepted', 'a decided proposal stays decided');
INSERT INTO archivist_proposals (base_id, kind, title, reason) VALUES (pg_temp.i('P'), 'gap', 'Three questions about returns; no source covers returns after thirty days', 'Again');
SELECT pg_temp.check((SELECT count(*) FROM archivist_proposals WHERE base_id = pg_temp.i('P') AND kind = 'gap') = 2, 'once decided, the same kind may be proposed again');

-- ---------------------------------------------------------------- notifications: preferences, texts within 480 characters, dedupe, agents never (§6.3)
SELECT pg_temp.as_member(27);
INSERT INTO notification_prefs (member_id, text_enabled, text_kinds) VALUES (30, true, '{build_failed}') ON CONFLICT (member_id) DO UPDATE SET text_enabled = true, text_kinds = '{build_failed}';
SELECT pg_temp.check(kn_notify(30, 'build_failed', 'Build failed: SMOKE Product manuals', repeat('y', 300), 'build', pg_temp.i('B3'), 'smoke:bf:1') IS NOT NULL, 'a notice to a member who chose texts');
SELECT pg_temp.check((SELECT count(*) FROM notification_outbox WHERE member_id = 30 AND kind = 'build_failed' AND channel = 'text' AND length(body) <= 480) = 1 AND (SELECT count(*) FROM notification_outbox WHERE member_id = 30 AND kind = 'build_failed' AND channel = 'email') = 1, 'queues a text within the kernel''s 480 characters AND an email');
SELECT pg_temp.check(kn_notify(30, 'build_failed', 'Build failed: SMOKE Product manuals', 'again', 'build', pg_temp.i('B3'), 'smoke:bf:1') IS NULL, 'the same dedupe key a second time writes nothing');
SELECT pg_temp.check(kn_notify(40, 'proposal', 'x') IS NULL AND (SELECT count(*) FROM notifications WHERE member_id = 40) = 0, 'an agent is dispatched, never notified');
SELECT pg_temp.check(kn_notify(41, 'proposal', 'x') IS NULL, 'nor an unadmitted member');
SELECT pg_temp.check((SELECT count(*) FROM notifications WHERE member_id = 26 AND kind = 'shared') = 1 AND (SELECT count(*) FROM notifications WHERE member_id = 30 AND kind = 'shared') = 1, 'a share told Priya (named) and Dana (her department)');
SELECT pg_temp.refused($$INSERT INTO notification_outbox (channel, kind, body) VALUES ('email', 'x', 'x')$$, 'not-null', 'every outbox row names a member (no external recipient here — the recorded substitution)');
SELECT pg_temp.refused($$INSERT INTO notifications (member_id, kind, title) VALUES (26, 'mention', 'x')$$, 'violates check', 'a notification kind is one of ours');

-- ---------------------------------------------------------------- the activity log's appended key (§6.4)
INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, after, base_id, token_id) VALUES
 (27, 'web', 'base.create', 'base', pg_temp.i('P'), jsonb_build_object('name', 'SMOKE Product manuals'), pg_temp.i('P'), NULL),
 (26, 'web', 'question.ask', 'question', pg_temp.i('Q1'), jsonb_build_object('question', 'How long is the battery warranty?', 'status', 'answered'), pg_temp.i('P'), NULL),
 (27, 'api', 'question.ask', 'question', NULL, jsonb_build_object('key_label', 'SMOKE Widget site'), pg_temp.i('P'), pg_temp.i('K1')),
 (26, 'web', 'base.create', 'base', pg_temp.i('I'), jsonb_build_object('name', 'SMOKE Company handbook'), pg_temp.i('I'), NULL),
 (27, 'web', 'base.create', 'base', pg_temp.i('X'), jsonb_build_object('name', 'SMOKE Marco notes'), pg_temp.i('X'), NULL);
SELECT pg_temp.refused($$UPDATE activity_log SET action = 'x.y' WHERE base_id = pg_temp.i('X')$$, 'permission denied', 'the writer appends to the log and never rewrites it (INSERT and SELECT only)');
SELECT pg_temp.refused($$INSERT INTO activity_log (action, source) VALUES ('x.y', 'ui')$$, 'violates check', 'the log''s source list is the contract''s');

-- ---------------------------------------------------------------- the views, as the read roles (§3, §6.4, db/014)
RESET ROLE; SET ROLE knowledge_records_ro;
SELECT pg_temp.as_member(26);   -- Priya: owns I, reads P
SELECT pg_temp.check((SELECT count(*) FROM mcp_bases) = 2 AND (SELECT my_level FROM mcp_bases WHERE base_id = pg_temp.i('P')) = 'read' AND (SELECT my_level FROM mcp_bases WHERE base_id = pg_temp.i('I')) = 'own'
    AND (SELECT i_curate FROM mcp_bases WHERE base_id = pg_temp.i('I')) AND NOT (SELECT i_curate FROM mcp_bases WHERE base_id = pg_temp.i('P')), 'the records role, as Priya: two bases in view with her level on each');
SELECT pg_temp.check((SELECT source_count = 2 AND section_count = 2 AND area_count = 2 AND status = 'ready' FROM mcp_bases WHERE base_id = pg_temp.i('P')), 'mcp_bases carries the counts (live sources, live sections, active areas)');
SELECT pg_temp.check((SELECT count(*) FROM mcp_sources WHERE base_id = pg_temp.i('P')) = (SELECT count(*) FROM mcp_sources WHERE base_id = pg_temp.i('P') AND status IS NOT NULL) AND (SELECT count(*) FROM mcp_sections WHERE base_id = pg_temp.i('P') AND text <> '') = 2 AND (SELECT count(*) FROM mcp_sections WHERE base_id = pg_temp.i('P') AND status = 'forgotten' AND text = '') = 4, 'a Reader reads the sources and the live sections'' text; a forgotten section''s text is withheld');
SELECT pg_temp.check((SELECT count(*) FROM mcp_focus_areas WHERE base_id = pg_temp.i('P')) = 2 AND (SELECT source_count FROM mcp_focus_areas WHERE focus_area_id = pg_temp.i('A1')) = 1, 'areas with their source counts are a Reader''s');
SELECT pg_temp.check((SELECT count(*) FROM mcp_pinned_answers WHERE base_id = pg_temp.i('P')) = 1 AND (SELECT count(*) FROM mcp_notes) >= 2 AND (SELECT count(*) FROM mcp_attachments) = 1 AND (SELECT count(*) FROM mcp_source_pages WHERE source_id = pg_temp.i('S1b')) = 2, 'pins, notes, files and pages follow the base');
SELECT pg_temp.check((SELECT count(*) FROM mcp_guidelines WHERE base_id = pg_temp.i('P')) = 0 AND (SELECT count(*) FROM mcp_guidelines WHERE base_id = pg_temp.i('I')) = 4
    AND (SELECT count(*) FROM mcp_vocabulary_terms WHERE base_id = pg_temp.i('P')) = 0 AND (SELECT count(*) FROM mcp_guideline_versions WHERE base_id = pg_temp.i('P')) = 0, 'guidelines, versions and vocabulary are the CURATOR''s: none of P''s, all of her own base''s');
SELECT pg_temp.check((SELECT count(*) FROM mcp_builds WHERE base_id = pg_temp.i('P')) = 0 AND (SELECT count(*) FROM mcp_keys) = 0 AND (SELECT count(*) FROM mcp_feeds) = 0 AND (SELECT count(*) FROM mcp_ai_usage) = 0 AND (SELECT count(*) FROM mcp_base_members WHERE base_id = pg_temp.i('P')) = 0 AND (SELECT count(*) FROM mcp_archivist_proposals) = 0, 'builds, keys, feeds, usage, members and proposals of a base she only reads: none');
SELECT pg_temp.check((SELECT count(*) FROM mcp_questions WHERE base_id = pg_temp.i('P')) = 1 AND (SELECT bool_and(asked_by_member_id = 26) FROM mcp_questions WHERE base_id = pg_temp.i('P')), 'other people''s questions on P are hidden from a Reader: only her own');
SELECT pg_temp.check((SELECT count(*) FROM mcp_base_members WHERE base_id = pg_temp.i('I')) = 1 AND (SELECT principal_name FROM mcp_base_members WHERE base_id = pg_temp.i('I')) = 'Everyone in the business', 'the members of her own base, named');
SELECT pg_temp.check((SELECT count(*) FROM mcp_members WHERE is_agent) = 2 AND (SELECT count(*) FROM mcp_members WHERE email IS NOT NULL) = 1 AND (SELECT count(*) FROM mcp_members WHERE is_guest) = 1 AND (SELECT count(*) FROM mcp_members) = 9, 'the people picker marks agents and the guest; an email is one''s own only; the unadmitted agent is absent');
SELECT pg_temp.check((SELECT count(*) FROM mcp_notifications) >= 1 AND (SELECT count(*) FROM mcp_notifications WHERE member_id <> 26) = 0 AND (SELECT count(*) FROM mcp_access_tokens_mine) = 1 AND (SELECT scope FROM mcp_access_tokens_mine) = 'mcp', 'notifications and tokens are one''s own');
SELECT pg_temp.check((SELECT model_alias IS NULL AND business_name = 'SMOKE Business' FROM mcp_settings) AND (SELECT count(*) FROM mcp_templates WHERE template_key = 'general') = 1 AND (SELECT count(*) FROM mcp_departments) = 4, 'settings (the model hidden from a non-admin), templates and departments');
SELECT pg_temp.check((SELECT kn_base_overview(pg_temp.i('P')) ? 'sources') AND NOT (kn_base_overview(pg_temp.i('P')) ? 'keys_count') AND (kn_base_overview(pg_temp.i('I')) ? 'keys_count') AND kn_base_overview(pg_temp.i('X')) IS NULL, 'base_overview: a Reader gets the public half, a curator the whole, nobody another''s private base');
SELECT pg_temp.check((SELECT count(*) FROM kn_questions(pg_temp.i('P'))) = 1 AND (SELECT count(*) FROM kn_question_gaps(pg_temp.i('P'))) = 0 AND kn_build_estimate(pg_temp.i('P')) IS NULL AND (SELECT count(*) FROM kn_usage(pg_temp.i('P'))) = 0 AND (SELECT count(*) FROM kn_unused_terms(pg_temp.i('P'))) = 0, 'the curator''s read functions answer a Reader nothing (gaps, estimate, usage, unused terms)');
SELECT pg_temp.as_member(27);   -- Marco: curates P
SELECT pg_temp.check((SELECT count(*) FROM mcp_keys WHERE base_id = pg_temp.i('P')) = 3 AND (SELECT calls_today FROM mcp_keys WHERE key_id = pg_temp.i('K1')) = 31 AND (SELECT NOT is_live FROM mcp_keys WHERE key_id = pg_temp.i('K2')) AND (SELECT rotated_from FROM mcp_keys WHERE key_id = pg_temp.i('K1b')) = pg_temp.i('K1'), 'as Marco: the three keys with today''s count, liveness and rotation; never a hash');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name IN ('mcp_keys', 'mcp_access_tokens_mine', 'mcp_questions') AND column_name = 'token_hash'), 'no view exposes token_hash');
SELECT pg_temp.check((SELECT count(*) FROM mcp_questions WHERE base_id = pg_temp.i('P')) = 4 AND (SELECT key_label FROM mcp_questions WHERE door = 'api') = 'SMOKE Widget site' AND (SELECT application_key FROM mcp_questions WHERE door = 'share') = 'helpdesk', 'a curator sees every asker''s question on his base: the key''s shows its label, the sibling''s its key');
SELECT pg_temp.check((SELECT count(*) FROM kn_questions(pg_temp.i('P'), p_door := 'api')) = 1 AND (SELECT key_label FROM kn_questions(pg_temp.i('P'), p_door := 'api')) = 'SMOKE Widget site' AND (SELECT count(*) FROM kn_questions(pg_temp.i('P'), p_search := 'torque')) = 1, 'kn_questions filters by door and searches the words');
SELECT pg_temp.check((SELECT count(*) FROM mcp_builds WHERE base_id = pg_temp.i('P')) = 4 AND (SELECT count(*) FROM mcp_build_items WHERE build_id = pg_temp.i('B1')) = 4 AND (SELECT count(*) FROM mcp_guidelines WHERE base_id = pg_temp.i('P')) = 4 AND (SELECT count(*) FROM mcp_feeds) = 1 AND (SELECT count(*) FROM mcp_ai_usage) = 1 AND (SELECT count(*) FROM mcp_archivist_proposals WHERE base_id = pg_temp.i('P')) = 3 AND (SELECT count(*) FROM mcp_base_members WHERE base_id = pg_temp.i('P')) = 5, 'builds, items, guidelines, feeds, usage, proposals and members of the base he curates');
SELECT pg_temp.check((SELECT bool_and(shapes_graph = (kind IN ('extraction', 'vocabulary', 'exclusion'))) FROM mcp_guidelines), 'mcp_guidelines says which kinds shape the graph');
SELECT pg_temp.check((SELECT count(*) FROM mcp_agent_dispatches) = 1 AND (SELECT count(*) FROM mcp_citations) = 0, 'the dispatch he caused; the citations of a purged question are gone');
SELECT pg_temp.as_member(30);   -- Dana: curates P through Engineering
SELECT pg_temp.check((SELECT count(*) FROM mcp_keys) = 3 AND (SELECT count(*) FROM mcp_questions WHERE base_id = pg_temp.i('P')) = 4 AND (SELECT my_level FROM mcp_bases WHERE base_id = pg_temp.i('P')) = 'curate', 'as Dana: a department''s curate share opens the curator''s views');
SELECT pg_temp.as_member(29);   -- the guest
SELECT pg_temp.check((SELECT count(*) FROM mcp_bases) = 1 AND (SELECT count(*) FROM mcp_bases WHERE base_id = pg_temp.i('I')) = 0 AND (SELECT count(*) FROM mcp_sections) = 6 AND (SELECT count(*) FROM mcp_questions) = 0 AND (SELECT count(*) FROM mcp_departments) = 0, 'as the guest: the one shared base and its sections, no internal base, no questions, no departments');
SELECT pg_temp.check((SELECT count(*) FROM mcp_members) BETWEEN 2 AND 4 AND (SELECT count(*) FROM mcp_members WHERE member_id = 28) = 0, 'the guest sees only the people present on what is shared (never Bea)');
SELECT pg_temp.as_member(28);   -- Bea, Reader inside
SELECT pg_temp.check((SELECT count(*) FROM mcp_bases) = 1 AND (SELECT base_id FROM mcp_bases) = pg_temp.i('I') AND (SELECT count(*) FROM mcp_questions) = 1 AND (SELECT count(*) FROM mcp_members) = 9, 'as Bea: the internal base, her own question, everyone in the picker');
SELECT pg_temp.as_member(40);   -- the agent
SELECT pg_temp.check((SELECT count(*) FROM mcp_bases) = 2 AND (SELECT count(*) FROM mcp_questions WHERE base_id = pg_temp.i('P')) = 2 AND (SELECT count(*) FROM mcp_keys) = 0, 'as the agent: what its member id may see — two bases, its own two questions (one asked as the kernel ran the share), no keys');
SELECT pg_temp.as_member(1);    -- the admin
SELECT pg_temp.check((SELECT count(*) FROM mcp_bases) = 3 AND (SELECT count(*) FROM mcp_keys) = 3 AND (SELECT count(*) FROM mcp_agent_dispatches) = 1 AND (SELECT model_alias IS NULL AND embedding_model IS NULL FROM mcp_settings) AND (SELECT count(*) FROM mcp_questions) = 5, 'as the admin: every base, every key, every dispatch, every question (the five that survived retention)');
SELECT pg_temp.as_member(41);
SELECT pg_temp.check((SELECT count(*) FROM mcp_bases) + (SELECT count(*) FROM mcp_members) + (SELECT count(*) FROM mcp_sections) = 0, 'an unadmitted agent sees nothing');
SELECT pg_temp.as_member(NULL);
SELECT pg_temp.check((SELECT count(*) FROM mcp_bases) + (SELECT count(*) FROM mcp_sections) + (SELECT count(*) FROM mcp_members) + (SELECT count(*) FROM mcp_questions) + (SELECT count(*) FROM mcp_settings) = 0, 'no acting member: every view is empty');
SELECT pg_temp.refused('SELECT count(*) FROM knowledge_bases', 'permission denied', 'the records role cannot read the bases table');
SELECT pg_temp.refused('SELECT count(*) FROM sections', 'permission denied', 'nor the sections table');
SELECT pg_temp.refused('SELECT token_hash FROM mcp_access_tokens', 'permission denied', 'nor the tokens table (the hash)');
SELECT pg_temp.refused('SELECT count(*) FROM source_text', 'permission denied', 'nor a source''s full text');
SELECT pg_temp.refused('SELECT count(*) FROM activity_log', 'permission denied', 'nor the activity log');
SELECT pg_temp.refused('SELECT count(*) FROM members', 'permission denied', 'nor the mirror');
SELECT pg_temp.refused($$INSERT INTO knowledge_bases (name, owner_member_id) VALUES ('SMOKE no', 1)$$, 'permission denied', 'the records role writes nothing');
SELECT pg_temp.refused($$SELECT kn_rate_ok(1)$$, 'permission denied', 'the records role cannot count a call');
SELECT pg_temp.refused($$SELECT kn_pin_answer(1)$$, 'permission denied', 'nor pin');
SELECT pg_temp.refused($$SELECT kn_purge_questions()$$, 'permission denied', 'nor purge');
SELECT pg_temp.check(mcp_member_kind(40) = 'agent' AND mcp_member_kind(41) IS NULL, 'mcp_member_kind answers for an admitted agent only');
SELECT pg_temp.check(mcp_admit_agent(41), 'first contact admits an active agent');
SELECT pg_temp.check(mcp_member_kind(41) = 'agent' AND NOT mcp_admit_agent(41), 'the agent is admitted, and not twice');
RESET ROLE; SET ROLE knowledge_activity_ro;
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT count(*) FROM mcp_activity_log) = 4 AND (SELECT count(*) FROM mcp_activity_log WHERE base_id = pg_temp.i('X')) = 0, 'the activity role, as Priya: rows about the bases she may see and her own, not Marco''s notes');
SELECT pg_temp.check((SELECT count(*) FROM mcp_activity_log WHERE token_id IS NOT NULL AND after->>'key_label' = 'SMOKE Widget site') = 1, 'an API event shows the key''s label, never the key');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check((SELECT count(*) FROM mcp_activity_log) = 3 AND (SELECT count(*) FROM mcp_bases) = 1, 'as the guest: the shared base''s history, and the base''s name beside it');
SELECT pg_temp.as_member(1);
SELECT pg_temp.check((SELECT count(*) FROM mcp_activity_log) = 5, 'the admin reads every row');
SELECT pg_temp.refused('SELECT count(*) FROM mcp_sections', 'permission denied', 'the activity role sees no content view');
SELECT pg_temp.refused('SELECT count(*) FROM mcp_keys', 'permission denied', 'nor the keys');
SELECT pg_temp.refused('SELECT count(*) FROM sections', 'permission denied', 'nor a base table');
RESET ROLE;

-- ---------------------------------------------------------------- the one that proves the proof: every statement above ran as a role, nothing committed
SET ROLE knowledge_rw;
SELECT pg_temp.check((SELECT count(*) FROM members) = 10 AND (SELECT count(*) FROM knowledge_bases) = 3, 'the fixtures are there until the rollback');
RESET ROLE;

\o
\pset tuples_only on
SELECT CASE WHEN ok THEN ' ok   ' ELSE ' FAIL ' END || label FROM proof ORDER BY n;
SELECT ' ' || count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed' FROM proof;
SELECT count(*) FILTER (WHERE NOT ok) > 0 AS anyfail FROM proof \gset
ROLLBACK;
\if :anyfail
    \echo 'PHASE 0 PROOF FAILED'
    \set ON_ERROR_STOP on
    SELECT 1 / 0;   -- a non-zero exit for the caller
\endif
