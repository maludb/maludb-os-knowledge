-- 013: THE READS THAT MATTER, as SQL functions the screens, the MCP tools and the exports all share (design §6.4, §7).
--
-- Every function enforces reach itself (kn_can_read_base / kn_can_curate_base — the read roles have no table grants), so a
-- tool and a screen cannot disagree: a base the caller may not read answers NULL or no rows; a curator's read asked by a
-- Reader answers nothing. The engine reads (graph, communities, subjects) are NOT here — they are one PHP module,
-- app/knowledge/engine.php, the only place the MaluDB token is read.
BEGIN;

-- ---------------------------------------------------------------------------------------------
-- B1 get_base: the base at a glance.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION kn_base_overview(p_base_id bigint) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE b knowledge_bases%ROWTYPE; lvl text; j jsonb;
BEGIN
    lvl := kn_base_level(p_base_id);
    IF lvl = 'none' THEN RETURN NULL; END IF;
    SELECT * INTO b FROM knowledge_bases WHERE id = p_base_id;
    j := jsonb_build_object(
        'base_id', b.id, 'key', b.key, 'name', b.name, 'description', b.description, 'template', b.template, 'template_version', b.template_version,
        'visibility', b.visibility, 'owner_member_id', b.owner_member_id,
        'owner_name', (SELECT display_name FROM members WHERE id = b.owner_member_id),
        'namespace', b.namespace, 'status', b.status, 'guideline_version', b.guideline_version, 'auto_extract', b.auto_extract,
        'monthly_text_mb_limit', b.monthly_text_mb_limit, 'sensitivity', b.sensitivity, 'language', b.language,
        'documents_count', b.documents_count, 'subjects_count', b.subjects_count, 'statements_count', b.statements_count, 'communities_count', b.communities_count,
        'last_built_at', b.last_built_at, 'archived_at', b.archived_at, 'created_at', b.created_at, 'updated_at', b.updated_at,
        'my_level', lvl,
        'sources', (SELECT jsonb_build_object('total', count(*) FILTER (WHERE status <> 'forgotten'),
                                              'ready', count(*) FILTER (WHERE status = 'ready'),
                                              'pending', count(*) FILTER (WHERE status IN ('uploaded', 'extracting_text', 'sectioning')),
                                              'no_text', count(*) FILTER (WHERE status = 'no_text'),
                                              'failed', count(*) FILTER (WHERE status = 'failed'),
                                              'by_kind', (SELECT COALESCE(jsonb_object_agg(kind, n), '{}') FROM (SELECT kind, count(*) n FROM sources WHERE base_id = b.id AND status <> 'forgotten' GROUP BY kind) k))
                      FROM sources WHERE base_id = b.id),
        'sections', (SELECT jsonb_build_object('total', count(*) FILTER (WHERE status <> 'forgotten'),
                                               'extracted', count(*) FILTER (WHERE status = 'extracted'),
                                               'queued', count(*) FILTER (WHERE status = 'queued'),
                                               'stale', count(*) FILTER (WHERE status = 'stale'),
                                               'failed', count(*) FILTER (WHERE status = 'failed'),
                                               'characters', COALESCE(sum(length(text)) FILTER (WHERE status <> 'forgotten'), 0))
                       FROM sections WHERE base_id = b.id),
        'areas', (SELECT COALESCE(jsonb_agg(jsonb_build_object('focus_area_id', a.id, 'name', a.name, 'slug', a.slug, 'status', a.status,
                                                               'source_count', (SELECT count(*) FROM source_focus_areas sfa JOIN sources s ON s.id = sfa.source_id AND s.status <> 'forgotten' WHERE sfa.focus_area_id = a.id))
                                            ORDER BY a.status = 'proposed', a.position, a.name), '[]')
                    FROM focus_areas a WHERE a.base_id = b.id AND a.status <> 'archived'),
        'last_build', (SELECT jsonb_build_object('build_id', x.id, 'kind', x.kind, 'status', x.status, 'items_total', x.items_total, 'items_done', x.items_done,
                                                 'items_failed', x.items_failed, 'requested_at', x.requested_at, 'finished_at', x.finished_at)
                         FROM builds x WHERE x.base_id = b.id ORDER BY x.requested_at DESC LIMIT 1),
        'running_build_id', (SELECT x.id FROM builds x WHERE x.base_id = b.id AND x.status IN ('queued', 'running') LIMIT 1),
        'pinned_count', (SELECT count(*) FROM pinned_answers p WHERE p.base_id = b.id AND p.status = 'active'),
        'questions_30d', (SELECT count(*) FROM questions q WHERE q.base_id = b.id AND q.created_at > now() - interval '30 days'
                             AND (lvl IN ('own', 'curate') OR q.asked_by_member_id = app_current_member_id())),
        'curators', (SELECT COALESCE(jsonb_agg(jsonb_build_object('member_id', m.id, 'display_name', m.display_name) ORDER BY m.display_name), '[]')
                       FROM members m WHERE m.id IN (SELECT kn_base_curator_ids(b.id))));
    IF lvl IN ('own', 'curate') THEN
        j := j || jsonb_build_object(
            'guidelines', (SELECT COALESCE(jsonb_object_agg(kind, n), '{}') FROM (SELECT kind, count(*) n FROM guidelines WHERE base_id = b.id AND is_active GROUP BY kind) g),
            'vocabulary_count', (SELECT count(*) FROM vocabulary_terms WHERE base_id = b.id),
            'keys_count', (SELECT count(*) FROM mcp_access_tokens t WHERE t.base_id = b.id AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())),
            'feeds_count', (SELECT count(*) FROM feeds f WHERE f.base_id = b.id AND f.enabled),
            'open_proposals', (SELECT count(*) FROM archivist_proposals p WHERE p.base_id = b.id AND p.status = 'proposed'),
            'gaps_30d', (SELECT count(*) FROM questions q WHERE q.base_id = b.id AND q.status IN ('no_evidence', 'unverified') AND q.created_at > now() - interval '30 days'),
            'estimate', kn_build_estimate(b.id));
    END IF;
    RETURN j;
END$$;

-- ---------------------------------------------------------------------------------------------
-- S2 source_outline: a source's sections as a tree in reading order (no text — get_section / kn_section_markdown has it).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION kn_source_outline(p_source_id bigint)
    RETURNS TABLE (section_id bigint, parent_id bigint, level smallint, pos integer, heading text, heading_path text, kind text, page_no integer,
                   char_count integer, status text, extracted_version integer)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE bid bigint;
BEGIN
    SELECT base_id INTO bid FROM sources WHERE id = p_source_id;
    IF bid IS NULL OR NOT kn_can_read_base(bid) THEN RETURN; END IF;
    RETURN QUERY
        SELECT s.id, s.parent_id, s.level, s.position, s.heading, s.heading_path, s.kind, s.page_no, length(s.text), s.status, s.extracted_version
          FROM sections s WHERE s.source_id = p_source_id AND s.status <> 'forgotten' ORDER BY s.position;
END$$;

-- ---------------------------------------------------------------------------------------------
-- S2 get_section: one section as Markdown — its heading path, the text, and where it came from.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION kn_section_markdown(p_section_id bigint) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE s sections%ROWTYPE; src sources%ROWTYPE;
BEGIN
    SELECT * INTO s FROM sections WHERE id = p_section_id;
    IF NOT FOUND OR NOT kn_can_read_base(s.base_id) THEN RETURN NULL; END IF;
    SELECT * INTO src FROM sources WHERE id = s.source_id;
    RETURN '# ' || s.heading_path || E'\n\n' || s.text || E'\n\n— ' || src.title
        || CASE WHEN s.page_no IS NOT NULL THEN ', page ' || s.page_no ELSE '' END
        || ' (section ' || s.id || CASE WHEN s.status = 'forgotten' THEN ', forgotten' WHEN s.status = 'stale' THEN ', stale' ELSE '' END || ')';
END$$;

-- ---------------------------------------------------------------------------------------------
-- Q4/Q5 my_questions / questions: the log with its filters. A curator of the base sees every asker's; a Reader their own.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION kn_questions(p_base_id bigint, p_status text DEFAULT NULL, p_since timestamptz DEFAULT NULL, p_asked_by bigint DEFAULT NULL,
                                        p_door text DEFAULT NULL, p_token_id bigint DEFAULT NULL, p_focus_area_id bigint DEFAULT NULL, p_search text DEFAULT NULL,
                                        p_limit integer DEFAULT 50, p_offset integer DEFAULT 0)
    RETURNS TABLE (question_id bigint, base_id bigint, focus_area_id bigint, asked_by_member_id bigint, asker_name text, asker_is_agent boolean, token_id bigint,
                   key_label text, application_key text, conversation_id text, turn_no integer, door text, question text, answer_md text, status text,
                   confidence numeric, citation_count bigint, verified_citations bigint, thumbs_up bigint, thumbs_down bigint, my_rating text, pinned boolean,
                   guideline_version integer, model text, latency_ms integer, created_at timestamptz)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id(); curate boolean;
BEGIN
    IF NOT kn_can_read_base(p_base_id) THEN RETURN; END IF;
    curate := kn_can_curate_base(p_base_id);
    RETURN QUERY
        SELECT q.id, q.base_id, q.focus_area_id, q.asked_by_member_id, m.display_name, m.member_kind = 'agent', q.token_id, t.label, q.application_key,
               q.conversation_id, q.turn_no, q.door, q.question, q.answer_md, q.status, q.confidence,
               (SELECT count(*) FROM citations c WHERE c.question_id = q.id), (SELECT count(*) FROM citations c WHERE c.question_id = q.id AND c.verified),
               (SELECT count(*) FROM question_feedback f WHERE f.question_id = q.id AND f.rating = 'up'),
               (SELECT count(*) FROM question_feedback f WHERE f.question_id = q.id AND f.rating = 'down'),
               (SELECT f.rating FROM question_feedback f WHERE f.question_id = q.id AND f.member_id = me),
               EXISTS (SELECT 1 FROM pinned_answers p WHERE p.question_id = q.id AND p.status = 'active'),
               q.guideline_version, q.model, q.latency_ms, q.created_at
          FROM questions q LEFT JOIN members m ON m.id = q.asked_by_member_id LEFT JOIN mcp_access_tokens t ON t.id = q.token_id
         WHERE q.base_id = p_base_id
           AND (curate OR q.asked_by_member_id = me)
           AND (p_status IS NULL OR q.status = p_status)
           AND (p_since IS NULL OR q.created_at >= p_since)
           AND (p_asked_by IS NULL OR q.asked_by_member_id = p_asked_by)
           AND (p_door IS NULL OR q.door = p_door)
           AND (p_token_id IS NULL OR q.token_id = p_token_id)
           AND (p_focus_area_id IS NULL OR q.focus_area_id = p_focus_area_id)
           AND (p_search IS NULL OR q.question ILIKE '%' || p_search || '%' OR q.answer_md ILIKE '%' || p_search || '%')
         ORDER BY q.created_at DESC
         LIMIT LEAST(GREATEST(COALESCE(p_limit, 50), 1), 500) OFFSET GREATEST(COALESCE(p_offset, 0), 0);
END$$;

-- ---------------------------------------------------------------------------------------------
-- Q6 question_gaps: what the base could NOT answer, clustered by their significant words ("nine questions about returns").
-- Deterministic and small: lower-case, unaccented words of four letters or more that are not in the stop list, a trailing
-- 's' dropped; a question joins the cluster of its most frequently-asked-about word; a question with no such word is '(other)'.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION kn_significant_words(p text) RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$
    SELECT COALESCE(array_agg(DISTINCT w ORDER BY w), '{}')
      FROM (SELECT CASE WHEN length(x) > 4 AND x LIKE '%s' AND x NOT LIKE '%ss' THEN left(x, -1) ELSE x END AS w
              FROM regexp_split_to_table(lower(unaccent(COALESCE(p, ''))), '[^a-z0-9]+') x
             WHERE length(x) >= 4
               AND x NOT IN ('what','when','where','which','with','does','that','this','from','have','there','their','about','into','your','they','them','will','would',
                             'could','should','than','then','these','those','also','been','being','were','many','much','some','please','tell','know','need','want',
                             'like','there','here','just','only','ever','still','again','really','thing','things','something','anything','kind','sort','mean',
                             'means','give','gives','show','find','explain','question','answer','cannot','must','might','every','each','such','very','more','most',
                             'other','another','same','different','time','times','going','come','make','made','take','took','used','using','over','under','after',
                             'before','between','through','while','whether','because','since','until','within','without','around','against')) t
     WHERE length(w) >= 4;
$$;

CREATE OR REPLACE FUNCTION kn_question_gaps(p_base_id bigint, p_since timestamptz DEFAULT now() - interval '30 days')
    RETURNS TABLE (cluster_key text, question_count bigint, sample_question text, last_asked_at timestamptz, question_ids bigint[])
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT kn_can_curate_base(p_base_id) THEN RETURN; END IF;
    RETURN QUERY
        WITH q AS (SELECT id, question, created_at FROM questions
                    WHERE base_id = p_base_id AND status IN ('no_evidence', 'unverified') AND created_at >= COALESCE(p_since, '-infinity')),
             w AS (SELECT q.id, word FROM q, LATERAL unnest(kn_significant_words(q.question)) AS word),
             freq AS (SELECT word, count(DISTINCT id) AS n FROM w GROUP BY word),
             pick AS (SELECT DISTINCT ON (w.id) w.id, w.word FROM w JOIN freq USING (word) ORDER BY w.id, freq.n DESC, w.word),
             keyed AS (SELECT q.id, q.question, q.created_at, COALESCE(p.word, '(other)') AS k FROM q LEFT JOIN pick p ON p.id = q.id)
        SELECT k, count(*), (array_agg(question ORDER BY created_at DESC))[1], max(created_at), array_agg(id ORDER BY created_at DESC)
          FROM keyed GROUP BY k ORDER BY count(*) DESC, max(created_at) DESC;
END$$;

-- ---------------------------------------------------------------------------------------------
-- L1 build_progress; L2 build_estimate; L3 base_usage.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION kn_build_progress(p_build_id bigint) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE b builds%ROWTYPE; queued integer; running integer; skipped integer; secs numeric;
BEGIN
    SELECT * INTO b FROM builds WHERE id = p_build_id;
    IF NOT FOUND OR NOT kn_can_curate_base(b.base_id) THEN RETURN NULL; END IF;
    SELECT count(*) FILTER (WHERE status = 'queued'), count(*) FILTER (WHERE status = 'running'), count(*) FILTER (WHERE status = 'skipped')
      INTO queued, running, skipped FROM build_items WHERE build_id = b.id;
    secs := CASE WHEN b.started_at IS NULL THEN NULL ELSE extract(epoch FROM COALESCE(b.finished_at, now()) - b.started_at) END;
    RETURN jsonb_build_object(
        'build_id', b.id, 'base_id', b.base_id, 'kind', b.kind, 'status', b.status, 'guideline_version', b.guideline_version,
        'started_by', b.started_by, 'requested_at', b.requested_at, 'started_at', b.started_at, 'finished_at', b.finished_at,
        'items_total', b.items_total, 'items_done', b.items_done, 'items_failed', b.items_failed, 'items_queued', queued, 'items_running', running, 'items_skipped', skipped,
        'percent', CASE WHEN b.items_total = 0 THEN 0 ELSE round(100.0 * (b.items_done + b.items_failed + skipped) / b.items_total) END,
        'chars_processed', b.chars_processed, 'subjects_added', b.subjects_added, 'statements_added', b.statements_added, 'usage', b.usage,
        'elapsed_seconds', CASE WHEN secs IS NULL THEN NULL ELSE round(secs) END,
        'eta_seconds', CASE WHEN b.status <> 'running' OR secs IS NULL OR (b.items_done + b.items_failed) = 0 THEN NULL
                            ELSE round(secs / (b.items_done + b.items_failed) * (queued + running)) END,
        'error', b.error,
        'failed_items', (SELECT COALESCE(jsonb_agg(jsonb_build_object('section_id', i.section_id, 'heading_path', s.heading_path, 'error', left(i.error, 300)) ORDER BY i.id), '[]')
                           FROM build_items i JOIN sections s ON s.id = i.section_id WHERE i.build_id = b.id AND i.status = 'failed'));
END$$;

-- What a build would do now, against the monthly limit (D9: 1 MB of text = 1,048,576 characters, the approximation the
-- limit is written in). A rebuild_all counts every live section.
CREATE OR REPLACE FUNCTION kn_build_estimate(p_base_id bigint, p_kind text DEFAULT 'extract') RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE b knowledge_bases%ROWTYPE; q integer; st integer; f integer; tot integer; chars bigint; used bigint; lim bigint;
BEGIN
    SELECT * INTO b FROM knowledge_bases WHERE id = p_base_id;
    IF NOT FOUND OR NOT kn_can_curate_base(p_base_id) THEN RETURN NULL; END IF;
    SELECT count(*) FILTER (WHERE status = 'queued'), count(*) FILTER (WHERE status = 'stale'), count(*) FILTER (WHERE status = 'failed'), count(*),
           COALESCE(sum(length(text)) FILTER (WHERE p_kind = 'rebuild_all' OR status IN ('queued', 'stale', 'failed')), 0)
      INTO q, st, f, tot, chars FROM sections WHERE base_id = p_base_id AND status <> 'forgotten';
    SELECT COALESCE(sum(u.chars), 0) INTO used FROM ai_usage u WHERE u.base_id = p_base_id AND u.kind = 'extract' AND u.month = date_trunc('month', now())::date;
    lim := b.monthly_text_mb_limit::bigint * 1048576;
    RETURN jsonb_build_object(
        'base_id', p_base_id, 'kind', p_kind,
        'sections', CASE WHEN p_kind = 'rebuild_all' THEN tot ELSE q + st + f END,
        'queued_sections', q, 'stale_sections', st, 'failed_sections', f, 'total_sections', tot,
        'characters', chars,
        'month', to_char(now(), 'YYYY-MM'), 'month_used_chars', used, 'month_limit_chars', lim, 'month_limit_mb', b.monthly_text_mb_limit,
        'remaining_chars', GREATEST(lim - used, 0),
        'within_limit', used + chars <= lim,
        'limit_pct_after', CASE WHEN lim = 0 THEN 100 ELSE round(100.0 * (used + chars) / lim, 1) END,
        'running_build_id', (SELECT x.id FROM builds x WHERE x.base_id = p_base_id AND x.status IN ('queued', 'running') LIMIT 1),
        'guideline_version', b.guideline_version);
END$$;

CREATE OR REPLACE FUNCTION kn_usage(p_base_id bigint, p_month date DEFAULT date_trunc('month', now())::date)
    RETURNS TABLE (kind text, calls integer, input_tokens bigint, output_tokens bigint, chars bigint, updated_at timestamptz)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT kn_can_curate_base(p_base_id) THEN RETURN; END IF;
    RETURN QUERY
        SELECT u.kind, u.calls, u.input_tokens, u.output_tokens, u.chars, u.updated_at FROM ai_usage u
         WHERE u.base_id = p_base_id AND u.month = date_trunc('month', COALESCE(p_month, now()))::date ORDER BY u.kind;
END$$;

-- ---------------------------------------------------------------------------------------------
-- G5 unassigned_sources, empty_areas; G3 unused_terms (the Archivist's and the curator's lists).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION kn_unassigned_sources(p_base_id bigint)
    RETURNS TABLE (source_id bigint, title text, kind text, status text, sections_count integer, added_at timestamptz)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT kn_can_curate_base(p_base_id) THEN RETURN; END IF;
    RETURN QUERY
        SELECT s.id, s.title, s.kind, s.status, s.sections_count, s.added_at FROM sources s
         WHERE s.base_id = p_base_id AND s.status <> 'forgotten'
           AND NOT EXISTS (SELECT 1 FROM source_focus_areas sfa JOIN focus_areas a ON a.id = sfa.focus_area_id AND a.status <> 'archived' WHERE sfa.source_id = s.id)
         ORDER BY s.added_at DESC;
END$$;

CREATE OR REPLACE FUNCTION kn_empty_areas(p_base_id bigint)
    RETURNS TABLE (focus_area_id bigint, name text, slug text, status text, created_at timestamptz)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT kn_can_curate_base(p_base_id) THEN RETURN; END IF;
    RETURN QUERY
        SELECT a.id, a.name, a.slug, a.status, a.created_at FROM focus_areas a
         WHERE a.base_id = p_base_id AND a.status <> 'archived'
           AND NOT EXISTS (SELECT 1 FROM source_focus_areas sfa JOIN sources s ON s.id = sfa.source_id AND s.status <> 'forgotten' WHERE sfa.focus_area_id = a.id)
         ORDER BY a.position, a.name;
END$$;

-- A term the graph never met: no live section's text contains its canonical name or any alias (case-insensitive).
CREATE OR REPLACE FUNCTION kn_unused_terms(p_base_id bigint)
    RETURNS TABLE (term_id bigint, canonical_name text, aliases text[], subject_type text, seeded boolean)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT kn_can_curate_base(p_base_id) THEN RETURN; END IF;
    RETURN QUERY
        SELECT v.id, v.canonical_name, v.aliases, v.subject_type, v.maludb_subject_id IS NOT NULL FROM vocabulary_terms v
         WHERE v.base_id = p_base_id
           AND NOT EXISTS (SELECT 1 FROM sections s WHERE s.base_id = p_base_id AND s.status <> 'forgotten'
                              AND EXISTS (SELECT 1 FROM unnest(v.aliases || v.canonical_name) nm WHERE nm <> '' AND position(lower(nm) IN lower(s.text)) > 0))
         ORDER BY v.canonical_name;
END$$;

GRANT EXECUTE ON FUNCTION kn_base_overview(bigint), kn_source_outline(bigint), kn_section_markdown(bigint),
    kn_questions(bigint, text, timestamptz, bigint, text, bigint, bigint, text, integer, integer), kn_significant_words(text), kn_question_gaps(bigint, timestamptz),
    kn_build_progress(bigint), kn_build_estimate(bigint, text), kn_usage(bigint, date), kn_unassigned_sources(bigint), kn_empty_areas(bigint), kn_unused_terms(bigint)
    TO knowledge_rw, knowledge_records_ro;

COMMIT;
