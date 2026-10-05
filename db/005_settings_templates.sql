-- 005: the application's SETTINGS (one row) and the shipped TEMPLATES (design §6.2, §6.3; D6, D9, D11, D12).
--
-- THE ESTATE'S DATA MODEL (design §6.4): kn_settings is the siblings' one-row `app_settings` skeleton (id smallint = 1,
-- updated_at) with this application's columns. base_templates is new (nothing close): a template is DATA — a guideline set,
-- subject types, the area rule — so a second template (contracts, policies, a code) is a row, not a release.
--
-- The model alias and embedding model are MaluDB's own configuration (POST /v1/memory/config per namespace); the two columns
-- here are the admin's CHOICE the compile step sends, NULL = whatever the memory database answers. Changing them pauses (§5).
BEGIN;

CREATE TABLE kn_settings (
    id                          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    business_name               text,
    default_monthly_text_mb     integer NOT NULL DEFAULT 50 CHECK (default_monthly_text_mb BETWEEN 1 AND 100000),      -- D9: MB of text extracted per base per month
    max_source_bytes            bigint NOT NULL DEFAULT 52428800 CHECK (max_source_bytes BETWEEN 1048576 AND 2147483648),  -- D6: 50 MB per file
    url_fetch_allowed           boolean NOT NULL DEFAULT true,                                                            -- D6: one page per URL, public hosts only
    url_fetch_max_bytes         bigint NOT NULL DEFAULT 10485760 CHECK (url_fetch_max_bytes BETWEEN 65536 AND 104857600),
    evidence_threshold          numeric(4,3) NOT NULL DEFAULT 0.600 CHECK (evidence_threshold BETWEEN 0 AND 1),          -- M1 min_similarity: below it, no_evidence
    max_evidence                smallint NOT NULL DEFAULT 8 CHECK (max_evidence BETWEEN 1 AND 50),                       -- M1 max_evidence
    max_question_chars          integer NOT NULL DEFAULT 2000 CHECK (max_question_chars BETWEEN 100 AND 20000),          -- §6.3 the gate
    conversation_turns          smallint NOT NULL DEFAULT 3 CHECK (conversation_turns BETWEEN 0 AND 20),                 -- D10: the last three turns
    question_retention_days     integer NOT NULL DEFAULT 365 CHECK (question_retention_days BETWEEN 1 AND 3650),         -- D11
    key_rate_per_minute         integer NOT NULL DEFAULT 30 CHECK (key_rate_per_minute BETWEEN 1 AND 100000),            -- D12
    key_rate_per_day            integer NOT NULL DEFAULT 2000 CHECK (key_rate_per_day BETWEEN 1 AND 10000000),           -- D12
    key_rotation_overlap_hours  smallint NOT NULL DEFAULT 24 CHECK (key_rotation_overlap_hours BETWEEN 0 AND 168),       -- D12: the old key keeps 24 h
    pin_review_months           smallint NOT NULL DEFAULT 6 CHECK (pin_review_months IN (1, 3, 6, 12)),                   -- §6.3 pinned answers
    section_target_chars        integer NOT NULL DEFAULT 4000 CHECK (section_target_chars BETWEEN 500 AND 20000),        -- §6.1 unstructured text is cut here
    section_max_chars           integer NOT NULL DEFAULT 6000 CHECK (section_max_chars BETWEEN 1000 AND 50000),          -- §6.1 a longer section is split
    model_alias                 text,                                                                                    -- NULL = MaluDB's configured default
    embedding_model             text,
    limit_warn_pct              smallint NOT NULL DEFAULT 80 CHECK (limit_warn_pct BETWEEN 1 AND 100),                   -- the Archivist's "limit nearly spent"
    timezone                    text NOT NULL DEFAULT 'UTC',
    updated_at                  timestamptz NOT NULL DEFAULT now(),
    CHECK (section_max_chars >= section_target_chars)
);
INSERT INTO kn_settings (id) VALUES (1) ON CONFLICT DO NOTHING;
CREATE TRIGGER kn_settings_touch BEFORE UPDATE ON kn_settings FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE OR REPLACE FUNCTION kn_setting_int(p_name text) RETURNS bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v bigint;
BEGIN
    EXECUTE format('SELECT %I::bigint FROM kn_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;

-- ---------------------------------------------------------------------------------------------
-- The vocabularies of the design as functions, so a CHECK and a form agree in one place.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION kn_guideline_kinds() RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$ SELECT ARRAY['extraction', 'vocabulary', 'exclusion', 'answering', 'audience']; $$;
-- The three that change the graph (a save bumps the base's guideline_version and marks sections stale, §6.2).
CREATE OR REPLACE FUNCTION kn_graph_shaping_kinds() RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$ SELECT ARRAY['extraction', 'vocabulary', 'exclusion']; $$;
CREATE OR REPLACE FUNCTION kn_source_kinds() RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$ SELECT ARRAY['file', 'text', 'url', 'feed', 'note']; $$;
CREATE OR REPLACE FUNCTION kn_source_statuses() RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$ SELECT ARRAY['uploaded', 'extracting_text', 'sectioning', 'ready', 'no_text', 'failed', 'forgotten']; $$;
CREATE OR REPLACE FUNCTION kn_section_statuses() RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$ SELECT ARRAY['queued', 'extracted', 'stale', 'failed', 'forgotten']; $$;
CREATE OR REPLACE FUNCTION kn_question_statuses() RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$ SELECT ARRAY['answered', 'evidence_only', 'no_evidence', 'unverified', 'refused', 'failed']; $$;

-- ---------------------------------------------------------------------------------------------
-- Templates. `guidelines` is a JSON array of {kind, title, body}; a base made from a template starts with these as its own
-- guideline rows (db/008 copies them). `subject_types` seeds the vocabulary form's type list; `area_rule` says how the first
-- build proposes areas ('communities' = from the graph's communities, 'none' = never propose).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE base_templates (
    key                 text PRIMARY KEY CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name                text NOT NULL,
    description         text NOT NULL DEFAULT '',
    version             integer NOT NULL DEFAULT 1 CHECK (version >= 1),
    guidelines          jsonb NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(guidelines) = 'array'),
    subject_types       text[] NOT NULL DEFAULT '{}',
    area_rule           text NOT NULL DEFAULT 'communities' CHECK (area_rule IN ('communities', 'none')),
    default_visibility  text NOT NULL DEFAULT 'private' CHECK (default_visibility IN ('private', 'internal')),
    is_active           boolean NOT NULL DEFAULT true,
    sort_order          integer NOT NULL DEFAULT 0,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER base_templates_touch BEFORE UPDATE ON base_templates FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Every element of a template's guideline set names a known kind and carries a body.
CREATE OR REPLACE FUNCTION kn_template_guidelines_ok(p jsonb) RETURNS boolean
    LANGUAGE sql IMMUTABLE AS $$
    SELECT jsonb_typeof(p) = 'array'
       AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(p) g
                        WHERE jsonb_typeof(g) <> 'object'
                           OR NOT (g->>'kind' = ANY (kn_guideline_kinds()))
                           OR COALESCE(g->>'body', '') = '');
$$;
ALTER TABLE base_templates ADD CONSTRAINT base_templates_guidelines_shape CHECK (kn_template_guidelines_ok(guidelines));

-- The shipped template (design §6.2, D7): a light extraction prompt, default exclusions, the answering guideline and an
-- audience of a colleague who has not read the documents; areas proposed from communities. Synthetic words of our own.
INSERT INTO base_templates (key, name, description, version, guidelines, subject_types, area_rule, default_visibility, sort_order) VALUES
 ('general', 'General', 'Any pile of documents: manuals, policies, contracts, research. Entities, relationships, dates, amounts and owners; answers from the sources, cited, saying when they disagree or are silent.', 1,
  jsonb_build_array(
    jsonb_build_object('kind', 'extraction', 'title', 'What to extract',
        'body', 'Extract the named things the documents are about — products, systems, people by role, organisations, places, policies, procedures, terms defined in the text — and the relationships stated between them. Keep every date, deadline, duration, amount, limit, version number and owner as a statement attached to the thing it describes. A heading names a subject; a definition is a statement about the defined term. Prefer the document''s own words over paraphrase.'),
    jsonb_build_object('kind', 'exclusion', 'title', 'What to ignore',
        'body', 'Ignore page headers and footers, page numbers, running titles, tables of contents, copyright and confidentiality boilerplate, navigation text and anything that is clearly a template placeholder. Do not extract personal contact details (phone numbers, personal email addresses, home addresses) about individuals.'),
    jsonb_build_object('kind', 'answering', 'title', 'How to answer',
        'body', 'Answer only from the sources. Quote the exact words the answer relies on and cite the section they come from. When two sources disagree, say so and show both. When the sources are silent, say the knowledge base holds nothing on this — never guess. Keep to two paragraphs unless a list is clearer.'),
    jsonb_build_object('kind', 'audience', 'title', 'Who is asking',
        'body', 'A colleague who has not read the documents and may be reading on a phone: plain language, the answer first, the detail after, no jargon that the sources do not themselves use.')
  ),
  ARRAY['product', 'system', 'organisation', 'person', 'place', 'policy', 'procedure', 'term', 'document', 'event'],
  'communities', 'private', 1)
ON CONFLICT (key) DO NOTHING;

GRANT SELECT, INSERT, UPDATE, DELETE ON kn_settings, base_templates TO knowledge_rw;
GRANT EXECUTE ON FUNCTION kn_setting_int(text), kn_guideline_kinds(), kn_graph_shaping_kinds(), kn_source_kinds(), kn_source_statuses(),
    kn_section_statuses(), kn_question_statuses(), kn_template_guidelines_ok(jsonb)
    TO knowledge_rw, knowledge_records_ro, knowledge_activity_ro;

COMMIT;
