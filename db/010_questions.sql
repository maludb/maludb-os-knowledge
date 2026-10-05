-- 010: QUESTIONS — one ask with its answer, evidence and CITATIONS to sections — FEEDBACK and PINNED ANSWERS (design §6.3;
-- D10, D11).
--
-- kn_ask() in PHP (app/knowledge/ask.php) is the one function behind every door; what it writes is here. The referee's
-- rules: a question names its asker — a member, a key (token_id) or a sibling application (application_key) — and its base;
-- a citation's section belongs to the question's base (and its source and page follow the section); feedback is one per
-- member per question; a PIN, when accepted, writes a note on the base AND a source of kind `note` whose text is the pinned
-- answer, so the next build teaches the graph what a person confirmed (kn_pin_answer); retention purges questions older than
-- the setting but never a pinned one (kn_purge_questions). The question text is kept per the setting and is NEVER in a log
-- payload beyond 120 characters (D14).
--
-- THE ESTATE'S DATA MODEL (design §6.4): new — a logged question with evidence and citations exists nowhere; canonical.
BEGIN;

CREATE TABLE questions (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    base_id             bigint NOT NULL REFERENCES knowledge_bases(id) ON DELETE CASCADE,
    focus_area_id       bigint REFERENCES focus_areas(id) ON DELETE SET NULL,
    asked_by_member_id  bigint REFERENCES members(id) ON DELETE SET NULL,          -- the person or agent; a key's minter for an API call
    token_id            bigint REFERENCES mcp_access_tokens(id) ON DELETE SET NULL, -- the key, when asked through the token API
    application_key     text CHECK (application_key IS NULL OR application_key ~ '^[a-z0-9_]{1,60}$'),   -- the sibling, when asked through K7
    conversation_id     text CHECK (conversation_id IS NULL OR length(conversation_id) BETWEEN 1 AND 120),
    turn_no             integer NOT NULL DEFAULT 1 CHECK (turn_no >= 1),
    door                text NOT NULL CHECK (door IN ('web', 'api', 'mcp', 'share', 'agent')),
    question            text NOT NULL CHECK (length(question) BETWEEN 1 AND 20000),
    answer_md           text,
    status              text NOT NULL CHECK (status = ANY (kn_question_statuses())),
    confidence          numeric(4,3) CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 1),
    evidence            jsonb NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(evidence) = 'array'),   -- M1's evidence[], each mapped to a section
    guideline_version   integer,                                                      -- the base's at the time
    model               text,
    latency_ms          integer CHECK (latency_ms IS NULL OR latency_ms >= 0),
    usage               jsonb NOT NULL DEFAULT '{}',
    error               text,
    created_at          timestamptz NOT NULL DEFAULT now(),
    CHECK (asked_by_member_id IS NOT NULL OR token_id IS NOT NULL OR application_key IS NOT NULL),
    CHECK (door <> 'api' OR token_id IS NOT NULL),
    CHECK (door <> 'share' OR application_key IS NOT NULL)
);
CREATE INDEX questions_base_time_idx ON questions (base_id, created_at DESC);
CREATE INDEX questions_asker_idx ON questions (asked_by_member_id, created_at DESC);
CREATE INDEX questions_token_idx ON questions (token_id, created_at DESC) WHERE token_id IS NOT NULL;
CREATE INDEX questions_conversation_idx ON questions (conversation_id, turn_no) WHERE conversation_id IS NOT NULL;
CREATE INDEX questions_status_idx ON questions (base_id, status, created_at DESC);
CREATE INDEX questions_area_idx ON questions (focus_area_id) WHERE focus_area_id IS NOT NULL;

CREATE OR REPLACE FUNCTION kn_questions_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE tok mcp_access_tokens%ROWTYPE;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF NEW.base_id <> OLD.base_id OR NEW.question <> OLD.question OR NEW.door <> OLD.door THEN
            RAISE EXCEPTION 'A question''s base, words and door never change' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;
    IF NEW.focus_area_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM focus_areas a WHERE a.id = NEW.focus_area_id AND a.base_id = NEW.base_id) THEN
        RAISE EXCEPTION 'A question is scoped to an area of its own base' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.token_id IS NOT NULL THEN
        SELECT * INTO tok FROM mcp_access_tokens WHERE id = NEW.token_id;
        IF NOT FOUND OR tok.base_id IS DISTINCT FROM NEW.base_id THEN
            RAISE EXCEPTION 'A key asks the one base it is bound to' USING ERRCODE = 'check_violation';
        END IF;
        -- a key's question is logged under the key (its label on the screens) and its minter, whoever's session ran the call
        NEW.asked_by_member_id := COALESCE(NEW.asked_by_member_id, tok.member_id);
    END IF;
    NEW.asked_by_member_id := COALESCE(NEW.asked_by_member_id, app_current_member_id());
    IF NEW.guideline_version IS NULL THEN SELECT guideline_version INTO NEW.guideline_version FROM knowledge_bases WHERE id = NEW.base_id; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER questions_before BEFORE INSERT OR UPDATE ON questions FOR EACH ROW EXECUTE FUNCTION kn_questions_before();

CREATE TABLE citations (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    question_id       bigint NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
    rank              integer NOT NULL CHECK (rank >= 1),
    section_id        bigint NOT NULL REFERENCES sections(id) ON DELETE CASCADE,
    source_id         bigint REFERENCES sources(id) ON DELETE CASCADE,
    page_no           integer CHECK (page_no IS NULL OR page_no >= 1),
    quote             text NOT NULL,
    maludb_chunk_id   text,
    verified          boolean NOT NULL DEFAULT false,            -- M1 checked the quote against the evidence
    UNIQUE (question_id, rank)
);
CREATE INDEX citations_section_idx ON citations (section_id);
CREATE INDEX citations_source_idx ON citations (source_id);

-- A citation's section belongs to the question's base; source and page follow the section when not given.
CREATE OR REPLACE FUNCTION kn_citations_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE s sections%ROWTYPE; qb bigint;
BEGIN
    SELECT * INTO s FROM sections WHERE id = NEW.section_id;
    SELECT base_id INTO qb FROM questions WHERE id = NEW.question_id;
    IF s.base_id IS DISTINCT FROM qb THEN
        RAISE EXCEPTION 'A citation names a section of the question''s base' USING ERRCODE = 'check_violation';
    END IF;
    NEW.source_id := COALESCE(NEW.source_id, s.source_id);
    IF NEW.source_id <> s.source_id THEN RAISE EXCEPTION 'A citation''s source is its section''s' USING ERRCODE = 'check_violation'; END IF;
    NEW.page_no := COALESCE(NEW.page_no, s.page_no);
    RETURN NEW;
END$$;
CREATE TRIGGER citations_before BEFORE INSERT OR UPDATE ON citations FOR EACH ROW EXECUTE FUNCTION kn_citations_before();

CREATE TABLE question_feedback (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    question_id  bigint NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
    member_id    bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    rating       text NOT NULL CHECK (rating IN ('up', 'down')),
    comment      text CHECK (comment IS NULL OR length(comment) <= 2000),
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (question_id, member_id)
);
CREATE INDEX question_feedback_member_idx ON question_feedback (member_id);
CREATE TRIGGER question_feedback_touch BEFORE UPDATE ON question_feedback FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE OR REPLACE FUNCTION kn_question_feedback_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN NEW.member_id := COALESCE(NEW.member_id, app_current_member_id()); END IF;
    IF NEW.member_id IS NULL THEN RAISE EXCEPTION 'Feedback names who gave it' USING ERRCODE = 'not_null_violation'; END IF;
    RETURN NEW;
END$$;
ALTER TABLE question_feedback ALTER COLUMN member_id DROP NOT NULL;      -- the trigger fills it; the check keeps it set
ALTER TABLE question_feedback ADD CONSTRAINT question_feedback_member_set CHECK (member_id IS NOT NULL);
CREATE TRIGGER question_feedback_before BEFORE INSERT OR UPDATE ON question_feedback FOR EACH ROW EXECUTE FUNCTION kn_question_feedback_before();

CREATE TABLE pinned_answers (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    base_id          bigint NOT NULL REFERENCES knowledge_bases(id) ON DELETE CASCADE,
    question_id      bigint REFERENCES questions(id) ON DELETE SET NULL,      -- SET NULL: a pin outlives retention; the words are kept here
    title            text NOT NULL CHECK (length(title) BETWEEN 1 AND 300),
    question_text    text NOT NULL,
    answer_md        text NOT NULL,
    owner_member_id  bigint NOT NULL REFERENCES members(id) ON DELETE RESTRICT,
    review_until     date NOT NULL,
    verified_at      timestamptz NOT NULL DEFAULT now(),
    verified_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    source_id        bigint REFERENCES sources(id) ON DELETE SET NULL,        -- the note source it became
    note_id          bigint REFERENCES notes(id) ON DELETE SET NULL,
    status           text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'retired')),
    retired_at       timestamptz,
    retired_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CHECK ((status = 'retired') = (retired_at IS NOT NULL))
);
CREATE UNIQUE INDEX pinned_answers_one_per_question ON pinned_answers (question_id) WHERE question_id IS NOT NULL AND status = 'active';
CREATE INDEX pinned_answers_base_idx ON pinned_answers (base_id, status, review_until);
CREATE INDEX pinned_answers_owner_idx ON pinned_answers (owner_member_id);
CREATE TRIGGER pinned_answers_touch BEFORE UPDATE ON pinned_answers FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Retiring a pin forgets its note source (the graph unlearns what a person no longer vouches for).
CREATE OR REPLACE FUNCTION kn_pinned_answers_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.status = 'retired' AND OLD.status <> 'retired' AND NEW.source_id IS NOT NULL THEN
        UPDATE sources SET status = 'forgotten' WHERE id = NEW.source_id AND status <> 'forgotten';
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER pinned_answers_after AFTER UPDATE OF status ON pinned_answers FOR EACH ROW EXECUTE FUNCTION kn_pinned_answers_after();

-- Pin an answer: the note on the base, the note source carrying the question and the answer (ready to be cut and built —
-- its text is known, so it starts at `sectioning`), the pin row. The caller is the owner unless another is named. Only an
-- answered or evidence-only question is pinned — there is nothing to vouch for otherwise.
CREATE OR REPLACE FUNCTION kn_pin_answer(p_question_id bigint, p_member_id bigint DEFAULT NULL, p_title text DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE q questions%ROWTYPE; who bigint := COALESCE(p_member_id, app_current_member_id()); ttl text; nid bigint; sid bigint; pid bigint; body text;
BEGIN
    SELECT * INTO q FROM questions WHERE id = p_question_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such question' USING ERRCODE = 'no_data_found'; END IF;
    IF who IS NULL THEN RAISE EXCEPTION 'A pin has an owner' USING ERRCODE = 'not_null_violation'; END IF;
    IF q.status NOT IN ('answered', 'evidence_only') OR COALESCE(q.answer_md, '') = '' THEN
        RAISE EXCEPTION 'Only an answered question is pinned' USING ERRCODE = 'check_violation';
    END IF;
    IF EXISTS (SELECT 1 FROM pinned_answers WHERE question_id = p_question_id AND status = 'active') THEN
        RAISE EXCEPTION 'This answer is already pinned' USING ERRCODE = 'unique_violation';
    END IF;
    ttl := COALESCE(NULLIF(trim(p_title), ''), left(regexp_replace(q.question, '\s+', ' ', 'g'), 120));
    body := '# ' || ttl || E'\n\n**Question:** ' || q.question || E'\n\n' || q.answer_md;
    INSERT INTO notes (record_type, record_id, member_id, body) VALUES ('base', q.base_id, who, body) RETURNING id INTO nid;
    INSERT INTO sources (base_id, kind, title, origin, status, added_by) VALUES (q.base_id, 'note', ttl, 'pin:' || p_question_id, 'sectioning', who) RETURNING id INTO sid;
    INSERT INTO source_text (source_id, text, extractor) VALUES (sid, body, 'note');
    INSERT INTO source_pages (source_id, page_no, char_from, char_to) VALUES (sid, 1, 0, length(body));
    IF q.focus_area_id IS NOT NULL THEN
        INSERT INTO source_focus_areas (source_id, focus_area_id, assigned_by) VALUES (sid, q.focus_area_id, who) ON CONFLICT DO NOTHING;
    END IF;
    INSERT INTO pinned_answers (base_id, question_id, title, question_text, answer_md, owner_member_id, review_until, verified_by, source_id, note_id)
    VALUES (q.base_id, q.id, ttl, q.question, q.answer_md, who, (current_date + make_interval(months => kn_setting_int('pin_review_months')::integer))::date, who, sid, nid)
    RETURNING id INTO pid;
    RETURN pid;
END$$;

-- Retention (D11): questions older than the interval (default: the setting) go, with their citations and feedback; a question
-- behind an active pin stays (the pin keeps its words anyway). Returns how many went.
CREATE OR REPLACE FUNCTION kn_purge_questions(p_older_than interval DEFAULT NULL) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE cutoff timestamptz; n integer;
BEGIN
    cutoff := now() - COALESCE(p_older_than, make_interval(days => kn_setting_int('question_retention_days')::integer));
    DELETE FROM questions q WHERE q.created_at < cutoff
       AND NOT EXISTS (SELECT 1 FROM pinned_answers p WHERE p.question_id = q.id AND p.status = 'active');
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON questions, citations, question_feedback, pinned_answers TO knowledge_rw;
REVOKE ALL ON FUNCTION kn_pin_answer(bigint, bigint, text), kn_purge_questions(interval) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION kn_pin_answer(bigint, bigint, text), kn_purge_questions(interval) TO knowledge_rw;

COMMIT;
