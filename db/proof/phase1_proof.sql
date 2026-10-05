-- Phase 1 proof of db/015 (the Phase 1 fixes). Same shape as phase0_proof.sql: everything inside one transaction that is
-- ROLLED BACK; fixtures written as knowledge_rw, reads as the read roles, acting members set through app.member_id exactly
-- as PHP and the MCP servers set it. Fixtures are synthetic. Run as postgres on a SCRATCH database after loading db/001–015:
--   sudo -n -u postgres createdb knowledge_p1_scratch
--   for f in db/0*.sql; do sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q -d knowledge_p1_scratch -f "$f" || break; done
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d knowledge_p1_scratch -f db/proof/phase1_proof.sql; echo "exit $?"
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d knowledge_p1_scratch -f db/proof/phase0_proof.sql; echo "exit $?"   # still 264
--   sudo -n -u postgres dropdb knowledge_p1_scratch
--   # the three roles are cluster-wide: drop them only if they did not exist before
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
-- a whole extract build over a base's queued/stale/failed sections, every item done (the referee marks them extracted)
CREATE OR REPLACE FUNCTION pg_temp.rebuild(p_base bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE b bigint;
BEGIN
    INSERT INTO builds (base_id, kind) VALUES (p_base, 'extract') RETURNING id INTO b;
    PERFORM kn_build_queue_items(b);
    UPDATE builds SET status = 'running' WHERE id = b;
    UPDATE build_items SET status = 'done' WHERE build_id = b;
    UPDATE builds SET status = 'done' WHERE id = b;
END$$;
CREATE OR REPLACE FUNCTION pg_temp.gv(p_base bigint) RETURNS integer LANGUAGE sql STABLE AS $$ SELECT guideline_version FROM knowledge_bases WHERE id = p_base $$;
CREATE OR REPLACE FUNCTION pg_temp.st(p_base bigint) RETURNS text LANGUAGE sql STABLE AS $$ SELECT status FROM knowledge_bases WHERE id = p_base $$;
CREATE OR REPLACE FUNCTION pg_temp.stale(p_base bigint) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT count(*) FROM sections WHERE base_id = p_base AND status = 'stale' $$;
-- "nothing changed": the base is at version v, ready, nothing stale
CREATE OR REPLACE FUNCTION pg_temp.unchanged(p_base bigint, v integer) RETURNS boolean LANGUAGE sql STABLE AS $$
    SELECT pg_temp.gv(p_base) = v AND pg_temp.st(p_base) = 'ready' AND pg_temp.stale(p_base) = 0 $$;
-- "it shaped": the base is at version v, needs_rebuild, its n extracted sections stale
CREATE OR REPLACE FUNCTION pg_temp.shaped(p_base bigint, v integer, n integer) RETURNS boolean LANGUAGE sql STABLE AS $$
    SELECT pg_temp.gv(p_base) = v AND pg_temp.st(p_base) = 'needs_rebuild' AND pg_temp.stale(p_base) = n $$;
GRANT EXECUTE ON FUNCTION pg_temp.check(boolean, text), pg_temp.refused(text, text, text), pg_temp.as_member(bigint), pg_temp.i(text), pg_temp.keep(text, bigint),
    pg_temp.rebuild(bigint), pg_temp.gv(bigint), pg_temp.st(bigint), pg_temp.stale(bigint), pg_temp.unchanged(bigint, integer), pg_temp.shaped(bigint, integer, integer) TO PUBLIC;

-- ---------------------------------------------------------------- fixtures, as the writer (phase 0's actors)
SET ROLE knowledge_rw;
SELECT pg_temp.as_member(1);
INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Owner',   'owner@example.invalid', 'super_admin', false, 'active', 'admin', '{admin}'),
 (26, 'human', 'SMOKE Priya',   'priya@example.invalid', 'user', false, 'active', 'write', '{user}'),
 (27, 'human', 'SMOKE Marco',   'marco@example.invalid', 'user', false, 'active', 'write', '{curator,user}'),
 (28, 'human', 'SMOKE Bea',     'bea@example.invalid',   'user', false, 'active', 'read',  '{reader}'),
 (29, 'human', 'SMOKE Ann',     'ann@client.invalid',    'user', true,  'active', 'read',  '{reader}'),
 (40, 'agent', 'SMOKE Expert',  NULL,                    'user', false, 'active', 'write', '{user}'),
 (42, 'agent', 'SMOKE Archivist', NULL,                  'user', false, 'active', 'write', '{user}'),
 (43, 'agent', 'SMOKE Leaving', NULL,                    'user', false, 'active', 'write', '{user}');

-- bases: P (private, Marco's, shared with Help Desk), H (internal, Priya's, shared with Help Desk), U (private, shared with
-- Projects only), A (shared with Help Desk, then archived), I (internal, shared with no application), G (the guidelines base)
SELECT pg_temp.as_member(27);
WITH ins AS (INSERT INTO knowledge_bases (name, description, owner_member_id, key) VALUES ('SMOKE Manuals', repeat('d', 600), 27, 'manuals') RETURNING id) SELECT pg_temp.keep('P', id) FROM ins;
WITH ins AS (INSERT INTO knowledge_bases (name, owner_member_id, key) VALUES ('SMOKE Unshared', 27, 'unshared') RETURNING id) SELECT pg_temp.keep('U', id) FROM ins;
WITH ins AS (INSERT INTO knowledge_bases (name, owner_member_id, key) VALUES ('SMOKE Archived', 27, 'archived') RETURNING id) SELECT pg_temp.keep('A', id) FROM ins;
WITH ins AS (INSERT INTO knowledge_bases (name, owner_member_id, key) VALUES ('SMOKE Guidelines', 27, 'guidelines') RETURNING id) SELECT pg_temp.keep('G', id) FROM ins;
SELECT pg_temp.as_member(26);
WITH ins AS (INSERT INTO knowledge_bases (name, description, owner_member_id, visibility, key) VALUES ('SMOKE Handbook', 'The company handbook', 26, 'internal', 'handbook') RETURNING id) SELECT pg_temp.keep('H', id) FROM ins;
WITH ins AS (INSERT INTO knowledge_bases (name, owner_member_id, visibility, key) VALUES ('SMOKE Internal', 26, 'internal', 'internal') RETURNING id) SELECT pg_temp.keep('I', id) FROM ins;
SELECT pg_temp.as_member(27);
INSERT INTO base_members (base_id, principal_kind, principal_ref) VALUES (pg_temp.i('P'), 'application', 'helpdesk'), (pg_temp.i('A'), 'application', 'helpdesk'), (pg_temp.i('U'), 'application', 'projects');
INSERT INTO base_members (base_id, principal_kind, principal_id) VALUES (pg_temp.i('P'), 'member', 26);
SELECT pg_temp.as_member(26);
INSERT INTO base_members (base_id, principal_kind, principal_ref) VALUES (pg_temp.i('H'), 'application', 'helpdesk');
SELECT pg_temp.as_member(27);
UPDATE knowledge_bases SET archived_at = now() WHERE id = pg_temp.i('A');

-- P: one source with two sections, built; a second source forgotten with its section; areas active, proposed and archived
WITH ins AS (INSERT INTO sources (base_id, kind, title, origin) VALUES (pg_temp.i('P'), 'text', 'SMOKE Widget manual', 'paste') RETURNING id) SELECT pg_temp.keep('S1', id) FROM ins;
INSERT INTO source_text (source_id, text, extractor) VALUES (pg_temp.i('S1'), E'1 Warranty\nThe battery is covered for twenty-four months.\n\n2 Returns\nReturns within thirty days.', 'paste');
WITH ins AS (INSERT INTO sections (source_id, heading_path, heading, level, position, kind, text, char_from, char_to, page_no) VALUES
  (pg_temp.i('S1'), 'Widget Manual › 1 Warranty', '1 Warranty', 1, 0, 'heading', 'The battery is covered for twenty-four months.', 11, 57, 1) RETURNING id) SELECT pg_temp.keep('sec1', id) FROM ins;
WITH ins AS (INSERT INTO sections (source_id, heading_path, heading, level, position, kind, text, char_from, char_to, page_no) VALUES
  (pg_temp.i('S1'), 'Widget Manual › 2 Returns', '2 Returns', 1, 1, 'heading', 'Returns within thirty days.', 69, 96, 2) RETURNING id) SELECT pg_temp.keep('sec2', id) FROM ins;
UPDATE sources SET status = 'ready' WHERE id = pg_temp.i('S1');
WITH ins AS (INSERT INTO sources (base_id, kind, title, origin) VALUES (pg_temp.i('P'), 'text', 'SMOKE Old leaflet', 'paste') RETURNING id) SELECT pg_temp.keep('S9', id) FROM ins;
INSERT INTO source_text (source_id, text, extractor) VALUES (pg_temp.i('S9'), 'The old leaflet promised a lifetime guarantee.', 'paste');
WITH ins AS (INSERT INTO sections (source_id, heading_path, heading, level, position, kind, text, char_from, char_to) VALUES
  (pg_temp.i('S9'), 'Old leaflet', '', 0, 0, 'part', 'The old leaflet promised a lifetime guarantee.', 0, 46) RETURNING id) SELECT pg_temp.keep('secF', id) FROM ins;
SELECT pg_temp.rebuild(pg_temp.i('P'));
UPDATE sources SET status = 'forgotten' WHERE id = pg_temp.i('S9');
INSERT INTO focus_areas (base_id, name, description, example_questions) VALUES (pg_temp.i('P'), 'Warranty', repeat('w', 400), '{q1,q2,q3,q4,q5,q6}');
INSERT INTO focus_areas (base_id, name, status, proposed_by) VALUES (pg_temp.i('P'), 'Proposed area', 'proposed', 40);
INSERT INTO focus_areas (base_id, name, status) VALUES (pg_temp.i('P'), 'Old area', 'archived');

-- ================================================================ 1. kn_section_markdown: nothing of a forgotten or unreadable section
SELECT pg_temp.check((SELECT status FROM sections WHERE id = pg_temp.i('secF')) = 'forgotten' AND (SELECT status FROM sections WHERE id = pg_temp.i('sec1')) = 'extracted', 'fixture: one forgotten section, the others extracted');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check(kn_section_markdown(pg_temp.i('sec1')) LIKE '# Widget Manual › 1 Warranty%twenty-four months%— SMOKE Widget manual, page 1 (section ' || pg_temp.i('sec1') || ')', 'a live section: heading path, text, source, page and id (as before)');
SELECT pg_temp.check(kn_section_markdown(pg_temp.i('secF')) IS NULL, 'a FORGOTTEN section answers NULL to its own curator (the owner)');
SELECT pg_temp.as_member(1);
SELECT pg_temp.check(kn_section_markdown(pg_temp.i('secF')) IS NULL AND kn_section_markdown(pg_temp.i('sec1')) IS NOT NULL, '…and to the Knowledge admin, who reads the live one');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(kn_section_markdown(pg_temp.i('secF')) IS NULL AND kn_section_markdown(pg_temp.i('sec2')) LIKE '%thirty days%', '…and to a Reader of the base, who reads the live one');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check(kn_section_markdown(pg_temp.i('sec1')) IS NULL, 'a section of a base the caller may not read (Bea on the private base) answers NULL');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check(kn_section_markdown(pg_temp.i('sec1')) IS NULL, 'a guest not named on the base: NULL');
SELECT pg_temp.as_member(NULL);
SELECT pg_temp.check(kn_section_markdown(pg_temp.i('sec1')) IS NULL, 'anonymous: NULL');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check(kn_section_markdown(-1) IS NULL, 'no such section: NULL');
UPDATE guidelines SET body = 'Extract every duration.' WHERE base_id = pg_temp.i('P') AND kind = 'extraction';
SELECT pg_temp.check(kn_section_markdown(pg_temp.i('sec1')) LIKE '%(section ' || pg_temp.i('sec1') || ', stale)', 'a stale section is still served and says stale');
SELECT pg_temp.rebuild(pg_temp.i('P'));
SELECT pg_temp.check(pg_get_function_identity_arguments('kn_section_markdown'::regproc) = 'p_section_id bigint'
    AND pg_get_function_result('kn_section_markdown'::regproc) = 'text', 'the signature is unchanged: kn_section_markdown(p_section_id bigint) RETURNS text');
RESET ROLE; SET ROLE knowledge_records_ro;
SELECT pg_temp.as_member(27);
SELECT pg_temp.check(kn_section_markdown(pg_temp.i('secF')) IS NULL AND kn_section_markdown(pg_temp.i('sec1')) IS NOT NULL, 'as the records role: still NULL for the forgotten section, text for the live one');
SELECT pg_temp.check((SELECT text FROM mcp_sections WHERE section_id = pg_temp.i('secF')) = '', 'the function now agrees with mcp_sections, which blanks a forgotten section''s text');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check(kn_section_markdown(pg_temp.i('sec1')) IS NULL, 'as the records role, an unreadable section: NULL');
RESET ROLE; SET ROLE knowledge_rw;

-- ================================================================ 2. question_feedback.token_id: a key's rating is stored with the key and the minter
SELECT pg_temp.as_member(27);
WITH ins AS (INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, base_id) VALUES (27, 'SMOKE Widget site', repeat('a', 64), 'api', pg_temp.i('P')) RETURNING id) SELECT pg_temp.keep('K1', id) FROM ins;
WITH ins AS (INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, base_id) VALUES (27, 'SMOKE Kiosk', repeat('b', 64), 'api', pg_temp.i('P')) RETURNING id) SELECT pg_temp.keep('K2', id) FROM ins;
WITH ins AS (INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, base_id) VALUES (26, 'SMOKE Handbook bot', repeat('c', 64), 'api', pg_temp.i('H')) RETURNING id) SELECT pg_temp.keep('KH', id) FROM ins;
WITH ins AS (INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (27, 'SMOKE Marco desktop', repeat('e', 64), 'mcp') RETURNING id) SELECT pg_temp.keep('TM', id) FROM ins;
WITH ins AS (INSERT INTO questions (base_id, door, question, answer_md, status, token_id) VALUES (pg_temp.i('P'), 'api', 'SMOKE how long is the warranty?', 'Twenty-four months.', 'answered', pg_temp.i('K1')) RETURNING id) SELECT pg_temp.keep('QK', id) FROM ins;
SELECT pg_temp.as_member(26);
WITH ins AS (INSERT INTO questions (base_id, door, question, answer_md, status) VALUES (pg_temp.i('P'), 'web', 'SMOKE Priya asks about returns', 'Thirty days.', 'answered') RETURNING id) SELECT pg_temp.keep('Q2', id) FROM ins;
WITH ins AS (INSERT INTO questions (base_id, door, question, answer_md, status) VALUES (pg_temp.i('H'), 'web', 'SMOKE Priya asks about leave', 'Five days.', 'answered') RETURNING id) SELECT pg_temp.keep('QH', id) FROM ins;
-- the token API posts with no session of its own (and even with nobody acting at all, the key names the minter)
SELECT pg_temp.as_member(NULL);
INSERT INTO question_feedback (question_id, rating, comment, token_id) VALUES (pg_temp.i('QK'), 'up', 'Right', pg_temp.i('K1'));
SELECT pg_temp.check((SELECT member_id = 27 AND token_id = pg_temp.i('K1') FROM question_feedback WHERE question_id = pg_temp.i('QK')), 'a rating posted with a key stores the key (token_id) and its minter (member_id)');
SELECT pg_temp.as_member(27);   -- as the token API runs it: the minter acting
INSERT INTO question_feedback (question_id, rating, token_id) VALUES (pg_temp.i('Q2'), 'down', pg_temp.i('K2'));
SELECT pg_temp.check((SELECT member_id = 27 AND token_id = pg_temp.i('K2') AND rating = 'down' FROM question_feedback WHERE question_id = pg_temp.i('Q2') AND member_id = 27), 'a second key of the base rates another question under the same minter');
SELECT pg_temp.as_member(26);
INSERT INTO question_feedback (question_id, rating) VALUES (pg_temp.i('Q2'), 'up');
SELECT pg_temp.check((SELECT member_id = 26 AND token_id IS NULL FROM question_feedback WHERE question_id = pg_temp.i('Q2') AND member_id = 26), 'a person''s own rating carries no key');
SELECT pg_temp.as_member(27);
SELECT pg_temp.refused($$INSERT INTO question_feedback (question_id, rating) VALUES (pg_temp.i('QK'), 'down')$$, 'duplicate key', 'the uniqueness is kept: the minter''s own rating on the same question collides with the key''s (one per member and question)');
SELECT pg_temp.refused($$INSERT INTO question_feedback (question_id, rating, token_id) VALUES (pg_temp.i('QH'), 'up', pg_temp.i('K1'))$$, 'bound to', 'a key cannot rate an answer of another base');
SELECT pg_temp.refused($$INSERT INTO question_feedback (question_id, member_id, rating, token_id) VALUES (pg_temp.i('QK'), 26, 'up', pg_temp.i('K1'))$$, 'minter', 'a key''s rating cannot be filed under someone other than its minter');
SELECT pg_temp.refused($$INSERT INTO question_feedback (question_id, rating, token_id) VALUES (pg_temp.i('QH'), 'up', pg_temp.i('TM'))$$, 'bound to', 'a person''s mcp token is not a key: it cannot be named on a rating');
SELECT pg_temp.refused($$UPDATE question_feedback SET token_id = pg_temp.i('KH') WHERE question_id = pg_temp.i('QK')$$, 'bound to', 'nor can a rating be moved to another base''s key afterwards');
INSERT INTO question_feedback (question_id, rating, comment, token_id) VALUES (pg_temp.i('QK'), 'down', 'Changed my mind', pg_temp.i('K1'))
    ON CONFLICT (question_id, member_id) DO UPDATE SET rating = EXCLUDED.rating, comment = EXCLUDED.comment, token_id = EXCLUDED.token_id;
SELECT pg_temp.check((SELECT count(*) = 1 AND bool_and(rating = 'down' AND token_id = pg_temp.i('K1')) FROM question_feedback WHERE question_id = pg_temp.i('QK')), 'the key re-rates (the API''s upsert): one row, the new rating, the key kept');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'question_feedback'::regclass AND contype = 'u'
                             AND conkey = ARRAY[(SELECT attnum FROM pg_attribute WHERE attrelid = 'question_feedback'::regclass AND attname = 'question_id'),
                                                (SELECT attnum FROM pg_attribute WHERE attrelid = 'question_feedback'::regclass AND attname = 'member_id')]::int2[])
    AND (SELECT NOT attnotnull FROM pg_attribute WHERE attrelid = 'question_feedback'::regclass AND attname = 'token_id')
    AND (SELECT confdeltype = 'n' FROM pg_constraint WHERE conrelid = 'question_feedback'::regclass AND contype = 'f' AND confrelid = 'mcp_access_tokens'::regclass)
    AND to_regclass('question_feedback_token_idx') IS NOT NULL, 'the schema: UNIQUE (question_id, member_id) kept; token_id nullable, ON DELETE SET NULL, indexed');
RESET ROLE; SET ROLE knowledge_records_ro;
SELECT pg_temp.as_member(27);
SELECT pg_temp.check((SELECT token_id = pg_temp.i('K1') AND key_label = 'SMOKE Widget site' AND member_name = 'SMOKE Marco' FROM mcp_question_feedback WHERE question_id = pg_temp.i('QK')), 'mcp_question_feedback shows the curator the minter''s name with the key''s label beside it');
SELECT pg_temp.check((SELECT array_agg(attname ORDER BY attnum DESC) FROM (SELECT attname, attnum FROM pg_attribute WHERE attrelid = 'mcp_question_feedback'::regclass AND attnum > 0 ORDER BY attnum DESC LIMIT 2) a) = '{key_label,token_id}', 'token_id and key_label are appended LAST to the view');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check((SELECT count(*) FROM mcp_question_feedback WHERE question_id = pg_temp.i('QK')) = 0, 'the view''s row rule is unchanged: a Reader of another base sees none of it');
RESET ROLE;
DELETE FROM mcp_access_tokens WHERE id = pg_temp.i('K2');        -- as postgres: the writer never deletes a key, it revokes
SET ROLE knowledge_rw;
SELECT pg_temp.check((SELECT token_id IS NULL AND member_id = 27 AND rating = 'down' FROM question_feedback WHERE question_id = pg_temp.i('Q2') AND member_id = 27), 'a deleted key leaves its rating standing as the minter''s (token_id SET NULL)');

-- ================================================================ 3. kn_application_bases: what the kernel's bases_index reads
RESET ROLE; SET ROLE knowledge_records_ro;
SELECT pg_temp.as_member(NULL);    -- the kernel's token sets no member
SELECT pg_temp.check((SELECT count(*) FROM mcp_bases) = 0, 'fixture: with no member identity mcp_bases answers nothing (why the function exists)');
SELECT pg_temp.check((SELECT array_agg(key ORDER BY name) FROM kn_application_bases('helpdesk')) = '{handbook,manuals}', 'Help Desk: exactly the two bases shared with it — not the archived one, not the one shared with Projects, not the internal one shared with no application');
SELECT pg_temp.check((SELECT array_agg(base_id) FROM kn_application_bases('helpdesk')) = ARRAY[pg_temp.i('H'), pg_temp.i('P')], 'in name order, with their ids');
SELECT pg_temp.check((SELECT array_agg(key) FROM kn_application_bases('projects')) = '{unshared}', 'Projects: its one private base (shared by name), nothing of Help Desk''s');
SELECT pg_temp.check((SELECT name = 'SMOKE Manuals' AND length(description) = 500 AND status = 'ready' AND last_built_at IS NOT NULL AND template = 'general' AND language = 'en'
                        FROM kn_application_bases('helpdesk') WHERE key = 'manuals'), 'the fields bases_index needs: name, description trimmed to 500, status, last built, template, language');
SELECT pg_temp.check((SELECT source_count = 1 AND section_count = 2 FROM kn_application_bases('helpdesk') WHERE key = 'manuals'), 'counts leave the forgotten source and its section out');
SELECT pg_temp.check((SELECT jsonb_array_length(areas) = 1 AND areas->0->>'slug' = 'warranty' AND areas->0->>'name' = 'Warranty'
                             AND length(areas->0->>'description') = 300 AND jsonb_array_length(areas->0->'example_questions') = 5
                        FROM kn_application_bases('helpdesk') WHERE key = 'manuals'), 'areas: the ACTIVE ones only (not proposed, not archived) as {slug, name, …}, description ≤ 300, five example questions at most');
SELECT pg_temp.check((SELECT areas = '[]'::jsonb FROM kn_application_bases('helpdesk') WHERE key = 'handbook'), 'a base with no area answers an empty array');
SELECT pg_temp.check((SELECT count(*) FROM kn_application_bases('nobody')) = 0 AND (SELECT count(*) FROM kn_application_bases(NULL)) = 0
    AND (SELECT count(*) FROM kn_application_bases('Help Desk!')) = 0 AND (SELECT count(*) FROM kn_application_bases('')) = 0, 'an unknown, empty, NULL or malformed application key answers nothing');
SELECT pg_temp.check(pg_get_function_result('kn_application_bases'::regproc) !~ '(owner|member|curator|token|key_|text_sha|guideline|body)'
    AND (SELECT jsonb_agg(to_jsonb(r))::text FROM kn_application_bases('helpdesk') r) NOT ILIKE '%twenty-four%'
    AND (SELECT jsonb_agg(to_jsonb(r))::text FROM kn_application_bases('helpdesk') r) NOT ILIKE '%Extract every%'
    AND (SELECT jsonb_agg(to_jsonb(r))::text FROM kn_application_bases('helpdesk') r) NOT ILIKE '%SMOKE Marco%', 'people-free and text-free: no owner, member or key column; no section, source or guideline words; no names of people');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check((SELECT count(*) FROM kn_application_bases('helpdesk')) = 2, 'whoever is acting, the answer is the application''s (the function never reads app.member_id)');
RESET ROLE; SET ROLE knowledge_activity_ro;
SELECT pg_temp.refused($$SELECT count(*) FROM kn_application_bases('helpdesk')$$, 'permission denied', 'refused to the activity role');
RESET ROLE; SET ROLE knowledge_rw;
SELECT pg_temp.refused($$SELECT count(*) FROM kn_application_bases('helpdesk')$$, 'permission denied', 'refused to the writer (records role only)');
RESET ROLE;
SELECT pg_temp.check(NOT has_function_privilege('public', 'kn_application_bases(text)', 'EXECUTE') AND has_function_privilege('knowledge_records_ro', 'kn_application_bases(text)', 'EXECUTE')
    AND (SELECT prosecdef AND proconfig @> '{search_path=public}' FROM pg_proc WHERE oid = 'kn_application_bases'::regproc), 'revoked from PUBLIC, granted to knowledge_records_ro; SECURITY DEFINER with a fixed search_path');
SET ROLE knowledge_rw;
SELECT pg_temp.as_member(27);
UPDATE knowledge_bases SET archived_at = now() WHERE id = pg_temp.i('P');
RESET ROLE; SET ROLE knowledge_records_ro;
SELECT pg_temp.check((SELECT array_agg(key) FROM kn_application_bases('helpdesk')) = '{handbook}', 'archiving a shared base takes it out of the index at once');
RESET ROLE; SET ROLE knowledge_rw;
UPDATE knowledge_bases SET archived_at = NULL WHERE id = pg_temp.i('P');
DELETE FROM base_members WHERE base_id = pg_temp.i('H') AND principal_kind = 'application';
RESET ROLE; SET ROLE knowledge_records_ro;
SELECT pg_temp.check((SELECT array_agg(key) FROM kn_application_bases('helpdesk')) = '{manuals}', 'restored, it is back; unshared, the internal handbook is gone (internal is for people, never for a sibling)');
RESET ROLE; SET ROLE knowledge_rw;

-- ================================================================ 4. kn_settings: the expert and the Archivist are agents, shown to admins only
SELECT pg_temp.as_member(1);
UPDATE kn_settings SET expert_member_id = 40, archivist_member_id = 42, model_alias = 'smoke-model' WHERE id = 1;
SELECT pg_temp.check((SELECT expert_member_id = 40 AND archivist_member_id = 42 FROM kn_settings), 'an admin names the expert and the Archivist (two agent members)');
SELECT pg_temp.refused($$UPDATE kn_settings SET expert_member_id = 27 WHERE id = 1$$, 'The expert is an agent member', 'a human as the expert is refused');
SELECT pg_temp.refused($$UPDATE kn_settings SET archivist_member_id = 26 WHERE id = 1$$, 'The Archivist is an agent member', 'a human as the Archivist is refused');
SELECT pg_temp.refused($$UPDATE kn_settings SET expert_member_id = 999999 WHERE id = 1$$, 'agent member', 'a member the mirror does not hold is refused');
UPDATE kn_settings SET max_evidence = 9 WHERE id = 1;
SELECT pg_temp.check((SELECT max_evidence = 9 AND expert_member_id = 40 FROM kn_settings), 'saving another setting leaves the two ids as they are');
UPDATE kn_settings SET archivist_member_id = 43 WHERE id = 1;
RESET ROLE;
DELETE FROM members WHERE id = 43;     -- the directory removes an agent (as postgres: the mirror's writer is the sync)
SET ROLE knowledge_rw;
SELECT pg_temp.check((SELECT archivist_member_id IS NULL AND expert_member_id = 40 FROM kn_settings), 'a removed member leaves the setting blank (ON DELETE SET NULL)');
UPDATE kn_settings SET archivist_member_id = 42, expert_member_id = NULL WHERE id = 1;
SELECT pg_temp.check((SELECT expert_member_id IS NULL AND archivist_member_id = 42 FROM kn_settings), 'blank is allowed (not named yet)');
UPDATE kn_settings SET expert_member_id = 40 WHERE id = 1;
RESET ROLE; SET ROLE knowledge_records_ro;
SELECT pg_temp.as_member(1);
SELECT pg_temp.check((SELECT expert_member_id = 40 AND archivist_member_id = 42 AND model_alias = 'smoke-model' FROM mcp_settings), 'mcp_settings shows the admin the two ids (and the model, as before)');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check((SELECT expert_member_id IS NULL AND archivist_member_id IS NULL AND model_alias IS NULL AND max_evidence = 9 FROM mcp_settings), 'a Curator sees the settings row but not the two ids (nor the model): admin-only, like model_alias');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check((SELECT expert_member_id IS NULL AND archivist_member_id IS NULL FROM mcp_settings) AND (SELECT count(*) FROM mcp_settings) = 1, 'a guest: the row, no ids');
SELECT pg_temp.as_member(NULL);
SELECT pg_temp.check((SELECT count(*) FROM mcp_settings) = 0, 'anonymous: no row (as before)');
SELECT pg_temp.check((SELECT array_agg(attname ORDER BY attnum DESC) FROM (SELECT attname, attnum FROM pg_attribute WHERE attrelid = 'mcp_settings'::regclass AND attnum > 0 ORDER BY attnum DESC LIMIT 2) a) = '{archivist_member_id,expert_member_id}'
    AND (SELECT attname FROM pg_attribute WHERE attrelid = 'mcp_settings'::regclass AND attnum = 1) = 'business_name', 'the two columns are appended LAST to mcp_settings; db/014''s columns keep their places');
RESET ROLE;
SELECT pg_temp.check(has_table_privilege('knowledge_records_ro', 'mcp_settings', 'SELECT') AND has_table_privilege('knowledge_rw', 'mcp_settings', 'SELECT') AND NOT has_table_privilege('knowledge_activity_ro', 'mcp_settings', 'SELECT')
    AND has_table_privilege('knowledge_records_ro', 'mcp_question_feedback', 'SELECT') AND NOT has_table_privilege('knowledge_records_ro', 'kn_settings', 'SELECT'), 'grants re-checked: the replaced views keep the records role and the writer, the activity role and the base table stay closed');
SET ROLE knowledge_rw;

-- ================================================================ 5. guidelines: a draft never shapes the graph
SELECT pg_temp.as_member(27);
SELECT pg_temp.check(pg_temp.gv(pg_temp.i('G')) = 1 AND pg_temp.st(pg_temp.i('G')) = 'empty' AND (SELECT count(*) FROM guidelines WHERE base_id = pg_temp.i('G') AND origin = 'template' AND is_active) = 4
    AND (SELECT count(*) FROM guideline_versions WHERE base_id = pg_temp.i('G')) = 4, 'a template copy still shapes nothing: version 1, four active guidelines, four version rows (as db/008)');
WITH ins AS (INSERT INTO sources (base_id, kind, title, origin) VALUES (pg_temp.i('G'), 'text', 'SMOKE Policy', 'paste') RETURNING id) SELECT pg_temp.keep('SG', id) FROM ins;
INSERT INTO sections (source_id, heading_path, position, kind, text, char_from, char_to) VALUES
 (pg_temp.i('SG'), 'Policy › A', 0, 'heading', 'Rule A applies on weekdays.', 0, 27), (pg_temp.i('SG'), 'Policy › B', 1, 'heading', 'Rule B applies at weekends.', 28, 55);
UPDATE sources SET status = 'ready' WHERE id = pg_temp.i('SG');
SELECT pg_temp.rebuild(pg_temp.i('G'));
SELECT pg_temp.keep('v', pg_temp.gv(pg_temp.i('G')));
SELECT pg_temp.check(pg_temp.unchanged(pg_temp.i('G'), pg_temp.i('v')::int) AND (SELECT count(*) FROM sections WHERE base_id = pg_temp.i('G') AND status = 'extracted') = 2, 'fixture: the base is built (ready, two extracted sections)');
-- the expert drafts
SELECT pg_temp.as_member(40);
WITH ins AS (INSERT INTO guidelines (base_id, kind, title, body, is_active, origin) VALUES (pg_temp.i('G'), 'extraction', 'Draft', 'Extract every weekday rule.', false, 'agent') RETURNING id) SELECT pg_temp.keep('D1', id) FROM ins;
SELECT pg_temp.check(pg_temp.unchanged(pg_temp.i('G'), pg_temp.i('v')::int), 'an INACTIVE extraction guideline inserted (the expert''s draft): no bump, nothing stale, still ready');
SELECT pg_temp.check((SELECT version_no = 1 AND NOT is_active AND base_guideline_version = pg_temp.i('v') AND saved_by = 40 FROM guideline_versions WHERE guideline_id = pg_temp.i('D1')), '…yet its words are kept: a version row at the base''s current version, saved by the expert');
UPDATE guidelines SET body = 'Extract every weekday and weekend rule.' WHERE id = pg_temp.i('D1');
SELECT pg_temp.check(pg_temp.unchanged(pg_temp.i('G'), pg_temp.i('v')::int) AND (SELECT version_no FROM guidelines WHERE id = pg_temp.i('D1')) = 2
    AND (SELECT count(*) FROM guideline_versions WHERE guideline_id = pg_temp.i('D1')) = 2, 'an inactive draft EDITED (inactive → inactive): the guideline is at version 2, the base unchanged');
UPDATE guidelines SET kind = 'exclusion', title = 'Draft exclusion' WHERE id = pg_temp.i('D1');
SELECT pg_temp.check(pg_temp.unchanged(pg_temp.i('G'), pg_temp.i('v')::int) AND (SELECT version_no FROM guidelines WHERE id = pg_temp.i('D1')) = 3, 'an inactive draft changing kind (still inactive): nothing');
WITH ins AS (INSERT INTO guidelines (base_id, kind, body, is_active, origin) VALUES (pg_temp.i('G'), 'vocabulary', 'Prefer "weekday" over "working day".', false, 'agent') RETURNING id) SELECT pg_temp.keep('D2', id) FROM ins;
SELECT pg_temp.check(pg_temp.unchanged(pg_temp.i('G'), pg_temp.i('v')::int), 'an inactive VOCABULARY draft: nothing');
UPDATE guidelines SET position = 7 WHERE id = pg_temp.i('D1');
SELECT pg_temp.check(pg_temp.unchanged(pg_temp.i('G'), pg_temp.i('v')::int) AND (SELECT version_no FROM guidelines WHERE id = pg_temp.i('D1')) = 3, 'a position change is still not a save');
-- a person accepts the draft
SELECT pg_temp.as_member(27);
UPDATE guidelines SET is_active = true WHERE id = pg_temp.i('D1');
SELECT pg_temp.check(pg_temp.shaped(pg_temp.i('G'), pg_temp.i('v')::int + 1, 2), 'inactive → ACTIVE (a draft accepted) bumps the version, marks the two sections stale, needs_rebuild');
SELECT pg_temp.check((SELECT base_guideline_version = pg_temp.i('v') + 1 AND is_active FROM guideline_versions WHERE guideline_id = pg_temp.i('D1') ORDER BY version_no DESC LIMIT 1), 'the version row records the base version it produced');
SELECT pg_temp.rebuild(pg_temp.i('G'));
UPDATE guidelines SET body = 'Ignore every footer.' WHERE id = pg_temp.i('D1');
SELECT pg_temp.check(pg_temp.shaped(pg_temp.i('G'), pg_temp.i('v')::int + 2, 2), 'an ACTIVE graph-shaping guideline edited bumps (as db/008)');
SELECT pg_temp.rebuild(pg_temp.i('G'));
UPDATE guidelines SET is_active = false WHERE id = pg_temp.i('D1');
SELECT pg_temp.check(pg_temp.shaped(pg_temp.i('G'), pg_temp.i('v')::int + 3, 2), 'active → INACTIVE (switched off) bumps: the compiled prompt lost it');
SELECT pg_temp.rebuild(pg_temp.i('G'));
DELETE FROM guidelines WHERE id = pg_temp.i('D1');
SELECT pg_temp.check(pg_temp.unchanged(pg_temp.i('G'), pg_temp.i('v')::int + 3), 'deleting an INACTIVE guideline: nothing (it was not in the prompt)');
DELETE FROM guidelines WHERE id = pg_temp.i('D2');
SELECT pg_temp.check(pg_temp.unchanged(pg_temp.i('G'), pg_temp.i('v')::int + 3), 'deleting the inactive vocabulary draft: nothing');
-- answering and audience: still nothing, active or not
UPDATE guidelines SET body = 'Answer in one line.' WHERE base_id = pg_temp.i('G') AND kind = 'answering';
SELECT pg_temp.check(pg_temp.unchanged(pg_temp.i('G'), pg_temp.i('v')::int + 3), 'an active ANSWERING save: nothing (as db/008)');
UPDATE guidelines SET is_active = false WHERE base_id = pg_temp.i('G') AND kind = 'audience';
UPDATE guidelines SET is_active = true, body = 'Field staff.' WHERE base_id = pg_temp.i('G') AND kind = 'audience';
SELECT pg_temp.check(pg_temp.unchanged(pg_temp.i('G'), pg_temp.i('v')::int + 3) AND (SELECT version_no FROM guidelines WHERE base_id = pg_temp.i('G') AND kind = 'audience') = 3, 'an AUDIENCE guideline switched off and on again: versioned, nothing of the graph');
INSERT INTO guidelines (base_id, kind, body, is_active) VALUES (pg_temp.i('G'), 'answering', 'A draft way of answering.', false);
SELECT pg_temp.check(pg_temp.unchanged(pg_temp.i('G'), pg_temp.i('v')::int + 3), 'an inactive answering draft: nothing');
-- a kind change on an active guideline counts on either side
UPDATE guidelines SET kind = 'extraction' WHERE base_id = pg_temp.i('G') AND kind = 'answering' AND is_active;
SELECT pg_temp.check(pg_temp.shaped(pg_temp.i('G'), pg_temp.i('v')::int + 4, 2), 'an ACTIVE answering guideline turned into an extraction one bumps');
SELECT pg_temp.rebuild(pg_temp.i('G'));
UPDATE guidelines SET kind = 'answering' WHERE base_id = pg_temp.i('G') AND kind = 'extraction' AND origin = 'template' AND title = 'How to answer';
SELECT pg_temp.check(pg_temp.shaped(pg_temp.i('G'), pg_temp.i('v')::int + 5, 2), '…and back (an active extraction guideline leaving the graph-shaping kinds) bumps too');
SELECT pg_temp.rebuild(pg_temp.i('G'));
DELETE FROM guidelines WHERE base_id = pg_temp.i('G') AND kind = 'exclusion' AND is_active;
SELECT pg_temp.check(pg_temp.shaped(pg_temp.i('G'), pg_temp.i('v')::int + 6, 2), 'deleting an ACTIVE exclusion guideline bumps (as db/008)');
SELECT pg_temp.rebuild(pg_temp.i('G'));
INSERT INTO guidelines (base_id, kind, title, body) VALUES (pg_temp.i('G'), 'exclusion', 'Ignore', 'Ignore page numbers.');
SELECT pg_temp.check(pg_temp.shaped(pg_temp.i('G'), pg_temp.i('v')::int + 7, 2), 'a person''s new ACTIVE exclusion guideline bumps (as db/008)');
SELECT pg_temp.check(pg_temp.gv(pg_temp.i('P')) >= 2 AND pg_temp.gv(pg_temp.i('U')) = 1 AND pg_temp.st(pg_temp.i('U')) = 'empty', 'other bases are untouched by all this');

-- ---------------------------------------------------------------- the one that proves the proof
SELECT pg_temp.check((SELECT count(*) FROM members) = 7 AND (SELECT count(*) FROM knowledge_bases) = 6, 'the fixtures are there until the rollback');
RESET ROLE;

\o
\pset tuples_only on
SELECT CASE WHEN ok THEN ' ok   ' ELSE ' FAIL ' END || label FROM proof ORDER BY n;
SELECT ' ' || count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed' FROM proof;
SELECT count(*) FILTER (WHERE NOT ok) > 0 AS anyfail FROM proof \gset
ROLLBACK;
\if :anyfail
    \echo 'PHASE 1 PROOF FAILED'
    \set ON_ERROR_STOP on
    SELECT 1 / 0;   -- a non-zero exit for the caller
\endif
