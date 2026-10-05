-- 014: WHO SEES WHAT — the mcp_* visibility views, the only thing the two read roles see (memory.md §1; design §3, §6.4).
-- Every WHERE clause IS the row rule; the servers never filter in Python. Caller checks are scalar subqueries or uncorrelated
-- sets tested ONCE per statement (the kernel's db/160 lesson): kn_visible_base_ids(), kn_curated_base_ids(),
-- kn_visible_member_ids(), kn_is_admin(). Plus the two functions the MCP servers need at first contact.
--
-- The primary key is re-aliased <entity>_id; raw columns that hide internals are not selected: a key's hash NEVER, a session
-- hash, an outbox body. A section's text IS content a Reader may read (that is how an answer is trusted) — it is here; a
-- source's full text is not (kn_section_markdown and the source screens read it through the writer). Guidelines, versions,
-- vocabulary, builds, keys, feeds, usage and other people's questions are the CURATOR's (§3).
BEGIN;

CREATE OR REPLACE FUNCTION mcp_admit_agent(p_member_id bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    UPDATE members SET capability = 'write', synced_at = now()
     WHERE id = p_member_id AND member_kind = 'agent' AND status = 'active' AND capability IS NULL;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n = 1;
END$$;
REVOKE ALL ON FUNCTION mcp_admit_agent(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_admit_agent(bigint) TO knowledge_records_ro, knowledge_activity_ro, knowledge_rw;

CREATE OR REPLACE FUNCTION mcp_member_kind(p_member_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT member_kind FROM members WHERE id = p_member_id AND status = 'active' AND capability IS NOT NULL;
$$;
REVOKE ALL ON FUNCTION mcp_member_kind(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_member_kind(bigint) TO knowledge_records_ro, knowledge_activity_ro, knowledge_rw;

-- The people the caller may see: everyone admitted, for a member of the business; for a guest, themselves and the people
-- present on the bases shared with them (owners, curators, named members).
CREATE OR REPLACE FUNCTION kn_visible_member_ids() RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id();
BEGIN
    IF me IS NULL OR NOT app_is_active_member() THEN RETURN; END IF;
    IF NOT kn_is_guest() THEN
        RETURN QUERY SELECT m.id FROM members m WHERE m.status = 'active' AND m.capability IS NOT NULL;
        RETURN;
    END IF;
    RETURN QUERY
        SELECT me
        UNION SELECT b.owner_member_id FROM knowledge_bases b WHERE b.id IN (SELECT kn_visible_base_ids())
        UNION SELECT bm.principal_id FROM base_members bm WHERE bm.principal_kind IN ('member', 'agent') AND bm.base_id IN (SELECT kn_visible_base_ids());
END$$;
GRANT EXECUTE ON FUNCTION kn_visible_member_ids() TO knowledge_rw, knowledge_records_ro, knowledge_activity_ro;

-- The questions the caller may see: their own, and every one on a base they curate.
CREATE OR REPLACE FUNCTION kn_visible_question_ids() RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id();
BEGIN
    IF me IS NULL OR NOT app_is_active_member() THEN RETURN; END IF;
    RETURN QUERY
        SELECT q.id FROM questions q WHERE q.base_id IN (SELECT kn_curated_base_ids())
        UNION
        SELECT q.id FROM questions q WHERE q.asked_by_member_id = me AND q.base_id IN (SELECT kn_visible_base_ids());
END$$;
GRANT EXECUTE ON FUNCTION kn_visible_question_ids() TO knowledge_rw, knowledge_records_ro, knowledge_activity_ro;

-- ---------------------------------------------------------------------------------------------
-- People, departments, settings, templates.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_members WITH (security_barrier = true) AS
SELECT m.id AS member_id, m.display_name, m.member_kind, m.is_agent, m.business_role, m.is_external, kn_member_is_guest(m.id) AS is_guest,
       m.status, m.capability, m.roles, m.job_title, m.timezone,
       CASE WHEN m.id = app_current_member_id() THEN m.email::text END AS email,
       (SELECT array_agg(dm.department_id) FROM department_members dm WHERE dm.member_id = m.id AND dm.left_at IS NULL) AS department_ids
  FROM members m
 WHERE m.status = 'active' AND m.capability IS NOT NULL AND m.id IN (SELECT kn_visible_member_ids());

CREATE OR REPLACE VIEW mcp_departments WITH (security_barrier = true) AS
SELECT d.id AS department_id, d.name, d.description, d.parent_id, d.manager_member_id, d.is_system, d.system_key, d.archived_at
  FROM departments d WHERE (SELECT kn_is_member_here());

CREATE OR REPLACE VIEW mcp_settings WITH (security_barrier = true) AS
SELECT s.business_name, s.default_monthly_text_mb, s.max_source_bytes, s.url_fetch_allowed, s.url_fetch_max_bytes, s.evidence_threshold, s.max_evidence,
       s.max_question_chars, s.conversation_turns, s.question_retention_days, s.key_rate_per_minute, s.key_rate_per_day, s.key_rotation_overlap_hours,
       s.pin_review_months, s.section_target_chars, s.section_max_chars,
       CASE WHEN (SELECT kn_is_admin()) THEN s.model_alias END AS model_alias,
       CASE WHEN (SELECT kn_is_admin()) THEN s.embedding_model END AS embedding_model,
       s.limit_warn_pct, s.timezone, s.updated_at
  FROM kn_settings s WHERE s.id = 1 AND (SELECT app_is_active_member());

CREATE OR REPLACE VIEW mcp_templates WITH (security_barrier = true) AS
SELECT t.key AS template_key, t.name, t.description, t.version, t.guidelines, t.subject_types, t.area_rule, t.default_visibility, t.is_active, t.sort_order
  FROM base_templates t WHERE (SELECT app_is_active_member());

-- ---------------------------------------------------------------------------------------------
-- Bases and who reads them.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_bases WITH (security_barrier = true) AS
SELECT b.id AS base_id, b.key, b.name, b.description, b.template, b.template_version, b.visibility, b.owner_member_id, o.display_name AS owner_name,
       b.namespace, b.status, b.guideline_version, b.auto_extract, b.monthly_text_mb_limit, b.sensitivity, b.language,
       b.documents_count, b.subjects_count, b.statements_count, b.communities_count, b.last_built_at, b.archived_at, b.created_by, b.created_at, b.updated_at,
       kn_base_level(b.id) AS my_level,
       b.id IN (SELECT kn_curated_base_ids()) AS i_curate,
       (SELECT count(*) FROM sources s WHERE s.base_id = b.id AND s.status <> 'forgotten') AS source_count,
       (SELECT count(*) FROM sections x WHERE x.base_id = b.id AND x.status <> 'forgotten') AS section_count,
       (SELECT count(*) FROM sections x WHERE x.base_id = b.id AND x.status = 'stale') AS stale_count,
       (SELECT count(*) FROM focus_areas a WHERE a.base_id = b.id AND a.status = 'active') AS area_count,
       (SELECT count(*) FROM pinned_answers p WHERE p.base_id = b.id AND p.status = 'active') AS pinned_count,
       (SELECT x.id FROM builds x WHERE x.base_id = b.id AND x.status IN ('queued', 'running') LIMIT 1) AS running_build_id
  FROM knowledge_bases b LEFT JOIN members o ON o.id = b.owner_member_id
 WHERE b.id IN (SELECT kn_visible_base_ids());

CREATE OR REPLACE VIEW mcp_base_members WITH (security_barrier = true) AS
SELECT bm.id AS base_member_id, bm.base_id, bm.principal_kind, bm.principal_id, bm.principal_ref, bm.level, bm.granted_by, bm.created_at,
       CASE bm.principal_kind WHEN 'member' THEN m.display_name WHEN 'agent' THEN m.display_name WHEN 'department' THEN d.name
                              WHEN 'application' THEN bm.principal_ref ELSE 'Everyone in the business' END AS principal_name
  FROM base_members bm LEFT JOIN members m ON m.id = bm.principal_id AND bm.principal_kind IN ('member', 'agent')
  LEFT JOIN departments d ON d.id = bm.principal_id AND bm.principal_kind = 'department'
 WHERE bm.base_id IN (SELECT kn_curated_base_ids());

-- ---------------------------------------------------------------------------------------------
-- Sources and sections (a Reader's: that is how an answer is trusted).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_sources WITH (security_barrier = true) AS
SELECT s.id AS source_id, s.base_id, s.kind, s.title, s.origin, s.attachment_id, s.mime_type, s.text_sha256, s.char_count, s.page_count, s.language, s.status,
       s.sections_count, s.error, s.added_by, a.display_name AS added_by_name, s.added_at, s.superseded_by, s.url_checked_at, s.url_changed_at, s.feed_id, s.feed_item_key,
       s.forgotten_at, s.updated_at,
       (SELECT array_agg(sfa.focus_area_id) FROM source_focus_areas sfa WHERE sfa.source_id = s.id) AS focus_area_ids,
       EXISTS (SELECT 1 FROM sections x WHERE x.source_id = s.id AND x.status = 'stale') AS is_stale,
       (SELECT min(x.extracted_version) FROM sections x WHERE x.source_id = s.id AND x.status = 'extracted') AS built_under
  FROM sources s LEFT JOIN members a ON a.id = s.added_by
 WHERE s.base_id IN (SELECT kn_visible_base_ids());

CREATE OR REPLACE VIEW mcp_source_pages WITH (security_barrier = true) AS
SELECT p.source_id, p.page_no, p.char_from, p.char_to
  FROM source_pages p JOIN sources s ON s.id = p.source_id WHERE s.base_id IN (SELECT kn_visible_base_ids());

CREATE OR REPLACE VIEW mcp_sections WITH (security_barrier = true) AS
SELECT x.id AS section_id, x.base_id, x.source_id, s.title AS source_title, x.heading_path, x.heading, x.level, x.parent_id, x.position, x.kind,
       CASE WHEN x.status = 'forgotten' THEN '' ELSE x.text END AS text, length(x.text) AS char_count, x.char_from, x.char_to, x.page_no, x.status,
       x.extracted_version, x.maludb_document_id, x.extracted_at, x.error, x.created_at, x.updated_at
  FROM sections x JOIN sources s ON s.id = x.source_id
 WHERE x.base_id IN (SELECT kn_visible_base_ids());

CREATE OR REPLACE VIEW mcp_attachments WITH (security_barrier = true) AS
SELECT a.id AS attachment_id, a.record_type, a.record_id, a.filename, a.mime_type, a.byte_size, a.sha256, a.uploaded_by, a.created_at
  FROM attachments a WHERE kn_record_base_id(a.record_type, a.record_id) IN (SELECT kn_visible_base_ids());

CREATE OR REPLACE VIEW mcp_notes WITH (security_barrier = true) AS
SELECT n.id AS note_id, n.record_type, n.record_id, n.member_id, m.display_name AS member_name, n.body, n.created_at
  FROM notes n LEFT JOIN members m ON m.id = n.member_id WHERE kn_record_base_id(n.record_type, n.record_id) IN (SELECT kn_visible_base_ids());

-- ---------------------------------------------------------------------------------------------
-- Guidelines, vocabulary (curators), areas (readers).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_guidelines WITH (security_barrier = true) AS
SELECT g.id AS guideline_id, g.base_id, g.kind, g.kind = ANY (kn_graph_shaping_kinds()) AS shapes_graph, g.title, g.body, g.position, g.is_active, g.origin, g.version_no,
       g.created_by, g.updated_by, g.created_at, g.updated_at
  FROM guidelines g WHERE g.base_id IN (SELECT kn_curated_base_ids());

CREATE OR REPLACE VIEW mcp_guideline_versions WITH (security_barrier = true) AS
SELECT v.id AS guideline_version_id, v.guideline_id, v.base_id, v.version_no, v.kind, v.title, v.body, v.is_active, v.base_guideline_version, v.saved_by, v.saved_at
  FROM guideline_versions v WHERE v.base_id IN (SELECT kn_curated_base_ids());

CREATE OR REPLACE VIEW mcp_vocabulary_terms WITH (security_barrier = true) AS
SELECT v.id AS term_id, v.base_id, v.canonical_name, v.aliases, v.subject_type, v.description, v.maludb_subject_id, v.created_by, v.created_at, v.updated_at
  FROM vocabulary_terms v WHERE v.base_id IN (SELECT kn_curated_base_ids());

CREATE OR REPLACE VIEW mcp_focus_areas WITH (security_barrier = true) AS
SELECT a.id AS focus_area_id, a.base_id, a.name, a.slug, a.description, a.seed_terms, a.example_questions, a.maludb_project_id, a.status, a.proposed_by,
       p.display_name AS proposed_by_name, a.proposal_note, a.decided_by, a.decided_at, a.position, a.created_by, a.created_at, a.updated_at,
       (SELECT count(*) FROM source_focus_areas sfa JOIN sources s ON s.id = sfa.source_id AND s.status <> 'forgotten' WHERE sfa.focus_area_id = a.id) AS source_count
  FROM focus_areas a LEFT JOIN members p ON p.id = a.proposed_by
 WHERE a.base_id IN (SELECT kn_visible_base_ids());

CREATE OR REPLACE VIEW mcp_source_focus_areas WITH (security_barrier = true) AS
SELECT sfa.source_id, sfa.focus_area_id, sfa.assigned_by, sfa.created_at
  FROM source_focus_areas sfa JOIN sources s ON s.id = sfa.source_id WHERE s.base_id IN (SELECT kn_visible_base_ids());

-- ---------------------------------------------------------------------------------------------
-- Builds (curators).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_builds WITH (security_barrier = true) AS
SELECT b.id AS build_id, b.base_id, b.kind, b.status, b.guideline_version, b.started_by, m.display_name AS started_by_name, b.requested_at, b.started_at, b.finished_at,
       b.items_total, b.items_done, b.items_failed, b.chars_processed, b.subjects_added, b.statements_added, b.usage, b.error, b.created_at, b.updated_at
  FROM builds b LEFT JOIN members m ON m.id = b.started_by WHERE b.base_id IN (SELECT kn_curated_base_ids());

CREATE OR REPLACE VIEW mcp_build_items WITH (security_barrier = true) AS
SELECT i.id AS build_item_id, i.build_id, b.base_id, i.section_id, s.heading_path, i.status, i.chars, i.started_at, i.finished_at, i.error, i.usage
  FROM build_items i JOIN builds b ON b.id = i.build_id LEFT JOIN sections s ON s.id = i.section_id
 WHERE b.base_id IN (SELECT kn_curated_base_ids());

-- ---------------------------------------------------------------------------------------------
-- Questions: one's own, and every one on a base one curates; a key's shows the key's label; pins follow the base.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_questions WITH (security_barrier = true) AS
SELECT q.id AS question_id, q.base_id, q.focus_area_id, q.asked_by_member_id, m.display_name AS asker_name, m.member_kind = 'agent' AS asker_is_agent,
       q.token_id, t.label AS key_label, q.application_key, q.conversation_id, q.turn_no, q.door, q.question, q.answer_md, q.status, q.confidence, q.evidence,
       q.guideline_version, q.model, q.latency_ms, q.usage, q.error, q.created_at,
       (SELECT count(*) FROM citations c WHERE c.question_id = q.id) AS citation_count,
       EXISTS (SELECT 1 FROM pinned_answers p WHERE p.question_id = q.id AND p.status = 'active') AS pinned
  FROM questions q LEFT JOIN members m ON m.id = q.asked_by_member_id LEFT JOIN mcp_access_tokens t ON t.id = q.token_id
 WHERE q.id IN (SELECT kn_visible_question_ids());

CREATE OR REPLACE VIEW mcp_citations WITH (security_barrier = true) AS
SELECT c.id AS citation_id, c.question_id, c.rank, c.section_id, s.heading_path, c.source_id, src.title AS source_title, c.page_no, c.quote, c.maludb_chunk_id, c.verified
  FROM citations c LEFT JOIN sections s ON s.id = c.section_id LEFT JOIN sources src ON src.id = c.source_id
 WHERE c.question_id IN (SELECT kn_visible_question_ids());

CREATE OR REPLACE VIEW mcp_question_feedback WITH (security_barrier = true) AS
SELECT f.id AS feedback_id, f.question_id, q.base_id, f.member_id, m.display_name AS member_name, f.rating, f.comment, f.created_at, f.updated_at
  FROM question_feedback f JOIN questions q ON q.id = f.question_id LEFT JOIN members m ON m.id = f.member_id
 WHERE f.question_id IN (SELECT kn_visible_question_ids());

CREATE OR REPLACE VIEW mcp_pinned_answers WITH (security_barrier = true) AS
SELECT p.id AS pinned_answer_id, p.base_id, p.question_id, p.title, p.question_text, p.answer_md, p.owner_member_id, o.display_name AS owner_name, p.review_until,
       p.review_until < current_date AS review_due, p.verified_at, p.verified_by, p.source_id, p.note_id, p.status, p.retired_at, p.retired_by, p.created_at, p.updated_at
  FROM pinned_answers p LEFT JOIN members o ON o.id = p.owner_member_id WHERE p.base_id IN (SELECT kn_visible_base_ids());

-- ---------------------------------------------------------------------------------------------
-- Keys (curators; never the hash), feeds, usage.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_keys WITH (security_barrier = true) AS
SELECT t.id AS key_id, t.base_id, t.focus_area_id, t.member_id AS minted_by, m.display_name AS minted_by_name, t.label, t.rate_per_minute, t.rate_per_day, t.rotated_from,
       t.last_used_at, t.expires_at, t.revoked_at, t.created_at,
       t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now()) AS is_live,
       kn_key_calls_today(t.id) AS calls_today
  FROM mcp_access_tokens t LEFT JOIN members m ON m.id = t.member_id
 WHERE t.scope = 'api' AND t.base_id IN (SELECT kn_curated_base_ids());

CREATE OR REPLACE VIEW mcp_feeds WITH (security_barrier = true) AS
SELECT f.id AS feed_id, f.base_id, f.provider, f.tool, f.arguments, f.focus_area_id, f.every_minutes, f.enabled, f.last_sync_at, f.next_sync_at, f.last_status, f.last_error,
       f.items, f.created_by, f.created_at, f.updated_at
  FROM feeds f WHERE f.base_id IN (SELECT kn_curated_base_ids());

CREATE OR REPLACE VIEW mcp_ai_usage WITH (security_barrier = true) AS
SELECT u.id AS ai_usage_id, u.base_id, u.month, u.kind, u.calls, u.input_tokens, u.output_tokens, u.chars, u.updated_at
  FROM ai_usage u WHERE u.base_id IN (SELECT kn_curated_base_ids());

-- ---------------------------------------------------------------------------------------------
-- Agents and proposals, notifications, one's own tokens.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_agent_dispatches WITH (security_barrier = true) AS
SELECT d.id AS dispatch_id, d.record_type, d.record_id, d.agent_member_id, m.display_name AS agent_name, d.kind, d.via, d.acting_member_id, d.run_id, d.request_id,
       d.status, d.reply_excerpt, d.detail, d.attempts, d.created_at, d.answered_at, d.base_id, d.build_id, d.next_attempt_at
  FROM agent_dispatches d JOIN members m ON m.id = d.agent_member_id
 WHERE (SELECT kn_is_admin()) OR d.acting_member_id = app_current_member_id() OR d.agent_member_id = app_current_member_id()
    OR (d.base_id IS NOT NULL AND d.base_id IN (SELECT kn_curated_base_ids()));

CREATE OR REPLACE VIEW mcp_archivist_proposals WITH (security_barrier = true) AS
SELECT p.id AS proposal_id, p.base_id, p.kind, p.subject_type, p.subject_id, p.title, p.reason, p.payload, p.status, p.proposed_by, m.display_name AS proposed_by_name,
       p.decided_by, p.decided_at, p.dispatch_id, p.created_at
  FROM archivist_proposals p LEFT JOIN members m ON m.id = p.proposed_by WHERE p.base_id IN (SELECT kn_curated_base_ids());

CREATE OR REPLACE VIEW mcp_notifications WITH (security_barrier = true) AS
SELECT n.id AS notification_id, n.member_id, n.kind, n.title, n.body, n.record_type, n.record_id, n.read_at, n.created_at
  FROM notifications n WHERE n.member_id = app_current_member_id();

CREATE OR REPLACE VIEW mcp_notification_prefs WITH (security_barrier = true) AS
SELECT p.member_id, p.email_enabled, p.text_enabled, p.kinds, p.text_kinds, p.updated_at FROM notification_prefs p WHERE p.member_id = app_current_member_id();

CREATE OR REPLACE VIEW mcp_access_tokens_mine WITH (security_barrier = true) AS
SELECT t.id AS token_id, t.label, t.scope, t.base_id, t.focus_area_id, t.rate_per_minute, t.rate_per_day, t.rotated_from, t.last_used_at, t.expires_at, t.revoked_at, t.created_at
  FROM mcp_access_tokens t WHERE t.member_id = app_current_member_id();

-- ---------------------------------------------------------------------------------------------
-- Activity: a record's history is the log (design §6.4). No text is ever in a payload, so nothing to hide but the request
-- internals. The caller sees rows about bases they may see, their own rows, and the admin everything.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_activity_log WITH (security_barrier = true) AS
SELECT a.id AS activity_id, a.occurred_at, a.actor_member_id, m.display_name AS actor_name, m.member_kind = 'agent' AS actor_is_agent, a.source, a.action, a.screen, a.route,
       a.entity_type, a.entity_id, a.before, a.after, a.request_id, a.agent_run_id, a.department_id, a.base_id, a.token_id
  FROM activity_log a LEFT JOIN members m ON m.id = a.actor_member_id
 WHERE (SELECT app_is_active_member())
   AND (a.actor_member_id = app_current_member_id()
        OR (SELECT kn_is_admin())
        OR (a.base_id IS NOT NULL AND a.base_id IN (SELECT kn_visible_base_ids()))
        OR (a.entity_type = 'base' AND a.entity_id IN (SELECT kn_visible_base_ids())));

-- ---------------------------------------------------------------------------------------------
-- Grants: the read roles see the views and nothing else; the writer reads them too (one shape for screens and tools).
-- ---------------------------------------------------------------------------------------------
GRANT SELECT ON mcp_members, mcp_departments, mcp_settings, mcp_templates, mcp_bases, mcp_base_members, mcp_sources, mcp_source_pages, mcp_sections, mcp_attachments, mcp_notes,
    mcp_guidelines, mcp_guideline_versions, mcp_vocabulary_terms, mcp_focus_areas, mcp_source_focus_areas, mcp_builds, mcp_build_items,
    mcp_questions, mcp_citations, mcp_question_feedback, mcp_pinned_answers, mcp_keys, mcp_feeds, mcp_ai_usage,
    mcp_agent_dispatches, mcp_archivist_proposals, mcp_notifications, mcp_notification_prefs, mcp_access_tokens_mine
    TO knowledge_records_ro, knowledge_rw;
GRANT SELECT ON mcp_activity_log TO knowledge_activity_ro, knowledge_rw;
GRANT SELECT ON mcp_members, mcp_departments, mcp_bases, mcp_sources, mcp_focus_areas TO knowledge_activity_ro;   -- names and record facts beside an activity row

COMMIT;
