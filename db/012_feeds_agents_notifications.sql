-- 012: FEEDS from the siblings (K7), DISPATCHING to agents and the ARCHIVIST'S PROPOSALS, TALKING to people (the bell,
-- preferences, the outbox), and the application's own count of ENGINE USAGE (design §5, §6.3, §8; D13).
--
-- THE ESTATE'S DATA MODEL (design §6.4), the reused tables of this file:
--   notifications, notification_prefs, notification_outbox — General Ledger db/014 CANONICAL. The `kind` lists are ours; the
--     outbox has NO external-party column — every recipient here is a member (a guest is a member) — so member_id is NOT NULL
--     and the pair CHECK is dropped (Spaces' substitution, recorded in the catalogue).
--   agent_dispatches — GL db/014 = Consultant Tracking db/013 CANONICAL; `kind` is ours (ask | propose_areas |
--     draft_guidelines | report); APPENDS base_id, build_id.
--   feeds, archivist_proposals, ai_usage — new (nothing close; GL's ai_statements are the kernel's statement imported).
BEGIN;

-- ---------------------------------------------------------------------------------------------
-- Feeds: a sibling's shared tool read through K7 on a schedule; each record becomes a `feed` source keyed by the provider's
-- id (sources.feed_id + feed_item_key), never copied otherwise. Absent connection → last_status no_connection.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE feeds (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    base_id         bigint NOT NULL REFERENCES knowledge_bases(id) ON DELETE CASCADE,
    provider        text NOT NULL CHECK (provider ~ '^[a-z0-9_]{1,60}$'),      -- the sibling's catalog key: spaces, helpdesk
    tool            text NOT NULL CHECK (tool ~ '^[a-z][a-z0-9_]{0,59}$'),     -- its shared tool: pages_index, articles_published
    arguments       jsonb NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(arguments) = 'object'),
    focus_area_id   bigint REFERENCES focus_areas(id) ON DELETE SET NULL,
    every_minutes   integer NOT NULL DEFAULT 60 CHECK (every_minutes BETWEEN 5 AND 10080),
    enabled         boolean NOT NULL DEFAULT true,
    last_sync_at    timestamptz,
    next_sync_at    timestamptz NOT NULL DEFAULT now(),
    last_status     text CHECK (last_status IS NULL OR last_status IN ('ok', 'failed', 'no_connection', 'refused')),
    last_error      text,
    items           integer NOT NULL DEFAULT 0 CHECK (items >= 0),           -- sources alive from this feed
    created_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX feeds_one_per_tool ON feeds (base_id, provider, tool, md5(arguments::text));
CREATE INDEX feeds_due_idx ON feeds (next_sync_at) WHERE enabled;
CREATE TRIGGER feeds_touch BEFORE UPDATE ON feeds FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE OR REPLACE FUNCTION kn_feeds_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN NEW.created_by := COALESCE(NEW.created_by, app_current_member_id()); END IF;
    IF NEW.focus_area_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM focus_areas a WHERE a.id = NEW.focus_area_id AND a.base_id = NEW.base_id) THEN
        RAISE EXCEPTION 'A feed''s area is an area of its base' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.last_sync_at IS DISTINCT FROM OLD.last_sync_at AND NEW.last_sync_at IS NOT NULL THEN
        NEW.next_sync_at := NEW.last_sync_at + make_interval(mins => NEW.every_minutes);
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER feeds_before BEFORE INSERT OR UPDATE ON feeds FOR EACH ROW EXECUTE FUNCTION kn_feeds_before();

ALTER TABLE sources ADD CONSTRAINT sources_feed_fk FOREIGN KEY (feed_id) REFERENCES feeds(id) ON DELETE SET NULL;

-- a feed's live item count follows its sources
CREATE OR REPLACE FUNCTION kn_feed_items_recount() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE fid bigint;
BEGIN
    FOR fid IN SELECT DISTINCT x FROM unnest(ARRAY[CASE WHEN TG_OP <> 'DELETE' THEN NEW.feed_id END, CASE WHEN TG_OP <> 'INSERT' THEN OLD.feed_id END]) x WHERE x IS NOT NULL LOOP
        UPDATE feeds SET items = (SELECT count(*) FROM sources s WHERE s.feed_id = fid AND s.status <> 'forgotten') WHERE id = fid;
    END LOOP;
    RETURN NULL;
END$$;
CREATE TRIGGER sources_feed_items AFTER INSERT OR UPDATE OF status, feed_id OR DELETE ON sources FOR EACH ROW EXECUTE FUNCTION kn_feed_items_recount();

-- ---------------------------------------------------------------------------------------------
-- Dispatching to agents (design §5): "the first build finished — propose areas and draft guidelines" wakes the expert
-- through one chat turn; an `ask` is a person's question handed to the expert when the engine had evidence only.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE agent_dispatches (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type      text,
    record_id        bigint,
    agent_member_id  bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind             text NOT NULL CHECK (kind IN ('ask', 'propose_areas', 'draft_guidelines', 'report')),
    via              text NOT NULL CHECK (via IN ('chat', 'wake', 'duty')),
    acting_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    run_id           bigint,
    request_id       text,
    status           text NOT NULL DEFAULT 'sent' CHECK (status IN ('sent', 'answered', 'refused', 'failed', 'awaiting_approval')),
    reply_excerpt    text,                                                 -- at most 200 characters
    detail           text,
    attempts         integer NOT NULL DEFAULT 1,
    created_at       timestamptz NOT NULL DEFAULT now(),
    answered_at      timestamptz,
    -- appended (design §6.4)
    base_id          bigint REFERENCES knowledge_bases(id) ON DELETE CASCADE,
    build_id         bigint REFERENCES builds(id) ON DELETE SET NULL,
    next_attempt_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX agent_dispatches_record_idx ON agent_dispatches (record_type, record_id, created_at DESC);
CREATE INDEX agent_dispatches_agent_idx ON agent_dispatches (agent_member_id, created_at DESC);
CREATE INDEX agent_dispatches_acting_idx ON agent_dispatches (acting_member_id);
CREATE INDEX agent_dispatches_base_idx ON agent_dispatches (base_id, created_at DESC);
CREATE INDEX agent_dispatches_queue_idx ON agent_dispatches (next_attempt_at) WHERE status = 'sent' AND run_id IS NULL;

CREATE OR REPLACE FUNCTION kn_agent_dispatches_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM members m WHERE m.id = NEW.agent_member_id AND m.member_kind = 'agent') THEN
        RAISE EXCEPTION 'A dispatch goes to an agent' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.reply_excerpt IS NOT NULL THEN NEW.reply_excerpt := left(NEW.reply_excerpt, 200); END IF;
    IF TG_OP = 'INSERT' THEN NEW.acting_member_id := COALESCE(NEW.acting_member_id, app_current_member_id()); END IF;
    IF NEW.build_id IS NOT NULL AND NEW.base_id IS NULL THEN SELECT base_id INTO NEW.base_id FROM builds WHERE id = NEW.build_id; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER agent_dispatches_before BEFORE INSERT OR UPDATE ON agent_dispatches FOR EACH ROW EXECUTE FUNCTION kn_agent_dispatches_before();

-- The Archivist's proposals (design §5): each one a curator accepts or dismisses; one open proposal per kind and subject.
CREATE TABLE archivist_proposals (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    base_id       bigint NOT NULL REFERENCES knowledge_bases(id) ON DELETE CASCADE,
    kind          text NOT NULL CHECK (kind IN ('area', 'merge', 'gap', 'stale_source', 'failed_source', 'review_pin', 'unassigned', 'unverified', 'feed_stopped', 'limit_near', 'url_changed')),
    subject_type  text CHECK (subject_type IS NULL OR subject_type IN ('source', 'section', 'question', 'pinned_answer', 'focus_area', 'feed', 'vocabulary_term', 'base')),
    subject_id    bigint,
    title         text NOT NULL CHECK (length(title) BETWEEN 1 AND 300),
    reason        text NOT NULL,
    payload       jsonb NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(payload) = 'object'),   -- the clustered questions, the duplicate names, the suggested area
    status        text NOT NULL DEFAULT 'proposed' CHECK (status IN ('proposed', 'accepted', 'dismissed')),
    proposed_by   bigint REFERENCES members(id) ON DELETE SET NULL,     -- the Archivist (or the expert)
    decided_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at    timestamptz,
    dispatch_id   bigint REFERENCES agent_dispatches(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    CHECK ((status = 'proposed') = (decided_at IS NULL)),
    CHECK ((subject_type IS NULL) = (subject_id IS NULL))
);
CREATE INDEX archivist_proposals_base_idx ON archivist_proposals (base_id, status, created_at DESC);
CREATE UNIQUE INDEX archivist_proposals_one_open ON archivist_proposals (base_id, kind, COALESCE(subject_type, ''), COALESCE(subject_id, 0)) WHERE status = 'proposed';

CREATE OR REPLACE FUNCTION kn_archivist_proposals_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        NEW.proposed_by := COALESCE(NEW.proposed_by, app_current_member_id());
        NEW.status := 'proposed'; NEW.decided_at := NULL; NEW.decided_by := NULL;
    ELSE
        IF OLD.status <> 'proposed' AND NEW.status <> OLD.status THEN
            RAISE EXCEPTION 'A decided proposal stays %', OLD.status USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.status <> 'proposed' AND OLD.status = 'proposed' THEN
            NEW.decided_by := COALESCE(NEW.decided_by, app_current_member_id());
            NEW.decided_at := COALESCE(NEW.decided_at, now());
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER archivist_proposals_before BEFORE INSERT OR UPDATE ON archivist_proposals FOR EACH ROW EXECUTE FUNCTION kn_archivist_proposals_before();

-- ---------------------------------------------------------------------------------------------
-- Talking.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE notifications (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id    bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind         text NOT NULL CHECK (kind IN ('build_done', 'build_failed', 'limit_near', 'proposal', 'feedback', 'review_due', 'monday_note', 'shared', 'source_failed')),
    record_type  text,
    record_id    bigint,
    title        text NOT NULL,
    body         text,
    read_at      timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX notifications_member_idx ON notifications (member_id, created_at DESC) WHERE read_at IS NULL;
CREATE INDEX notifications_record_idx ON notifications (record_type, record_id);

CREATE TABLE notification_prefs (
    member_id      bigint PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,
    email_enabled  boolean NOT NULL DEFAULT true,
    text_enabled   boolean NOT NULL DEFAULT false,                -- K6: the kernel keeps the opt-out; this is the person's choice here
    kinds          text[] NOT NULL DEFAULT '{build_done,build_failed,limit_near,proposal,review_due,monday_note,shared}',
    text_kinds     text[] NOT NULL DEFAULT '{build_failed}',
    updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER notification_prefs_touch BEFORE UPDATE ON notification_prefs FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- One row per recipient AND channel; a K6 refusal is a SKIP with the code and the email still goes.
CREATE TABLE notification_outbox (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel        text NOT NULL CHECK (channel IN ('email', 'text')),
    member_id      bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    to_email       citext,                                                 -- the member's address at send time
    kind           text NOT NULL,
    record_type    text,
    record_id      bigint,
    dedupe_key     text,
    subject        text,
    body           text NOT NULL,
    body_html      text,
    status         text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'skipped', 'failed')),
    attempts       integer NOT NULL DEFAULT 0,
    detail         text,
    provider_ref   text,                                                   -- MaluMail's message id, or the kernel's notification id
    send_after     timestamptz NOT NULL DEFAULT now(),
    sent_at        timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX notification_outbox_dedupe ON notification_outbox (dedupe_key) WHERE dedupe_key IS NOT NULL;
CREATE INDEX notification_outbox_queue_idx ON notification_outbox (status, send_after) WHERE status = 'queued';
CREATE INDEX notification_outbox_member_idx ON notification_outbox (member_id, created_at DESC);
CREATE INDEX notification_outbox_record_idx ON notification_outbox (record_type, record_id);

-- Notifying: one function the writers call. The bell row always (an agent is dispatched, never notified); email and text
-- per the member's preferences; the outbox rows queued here.
CREATE OR REPLACE FUNCTION kn_notify(p_member_id bigint, p_kind text, p_title text, p_body text DEFAULT NULL,
                                     p_record_type text DEFAULT NULL, p_record_id bigint DEFAULT NULL, p_dedupe text DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE nid bigint; prefs notification_prefs%ROWTYPE; m members%ROWTYPE;
BEGIN
    IF p_member_id IS NULL THEN RETURN NULL; END IF;
    SELECT * INTO m FROM members WHERE id = p_member_id AND status = 'active' AND capability IS NOT NULL;
    IF NOT FOUND OR m.member_kind = 'agent' THEN RETURN NULL; END IF;
    IF p_dedupe IS NOT NULL AND EXISTS (SELECT 1 FROM notification_outbox WHERE dedupe_key = p_dedupe || ':email') THEN RETURN NULL; END IF;
    INSERT INTO notifications (member_id, kind, title, body, record_type, record_id)
    VALUES (p_member_id, p_kind, left(p_title, 200), left(p_body, 500), p_record_type, p_record_id)
    RETURNING id INTO nid;
    SELECT * INTO prefs FROM notification_prefs WHERE member_id = p_member_id;
    IF NOT FOUND THEN
        INSERT INTO notification_prefs (member_id) VALUES (p_member_id) RETURNING * INTO prefs;
    END IF;
    IF prefs.email_enabled AND p_kind = ANY (prefs.kinds) AND m.email IS NOT NULL THEN
        INSERT INTO notification_outbox (channel, member_id, to_email, kind, record_type, record_id, dedupe_key, subject, body)
        VALUES ('email', p_member_id, m.email, p_kind, p_record_type, p_record_id, CASE WHEN p_dedupe IS NULL THEN NULL ELSE p_dedupe || ':email' END,
                left(p_title, 200), COALESCE(p_body, p_title));
    END IF;
    IF prefs.text_enabled AND p_kind = ANY (prefs.text_kinds) THEN
        INSERT INTO notification_outbox (channel, member_id, kind, record_type, record_id, dedupe_key, body)
        VALUES ('text', p_member_id, p_kind, p_record_type, p_record_id, CASE WHEN p_dedupe IS NULL THEN NULL ELSE p_dedupe || ':text' END,
                left(p_title || CASE WHEN p_body IS NULL THEN '' ELSE ': ' || left(p_body, 120) END, 480));
    END IF;
    RETURN nid;
END$$;

-- The curators of a base (owner + own/curate members, departments' live members, agents excluded): who a build's end, a
-- proposal or the Monday note reaches.
CREATE OR REPLACE FUNCTION kn_base_curator_ids(p_base_id bigint) RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN QUERY
        SELECT b.owner_member_id FROM knowledge_bases b WHERE b.id = p_base_id
        UNION
        SELECT bm.principal_id FROM base_members bm JOIN members m ON m.id = bm.principal_id AND m.member_kind = 'human'
         WHERE bm.base_id = p_base_id AND bm.principal_kind = 'member' AND bm.level IN ('curate', 'own')
        UNION
        SELECT dm.member_id FROM base_members bm JOIN department_members dm ON dm.department_id = bm.principal_id AND dm.left_at IS NULL
          JOIN members m ON m.id = dm.member_id AND m.member_kind = 'human' AND m.status = 'active'
         WHERE bm.base_id = p_base_id AND bm.principal_kind = 'department' AND bm.level IN ('curate', 'own');
END$$;

-- A finished or failed build tells the base's curators (the referee writes the bell; the worker sends the outbox).
CREATE OR REPLACE FUNCTION kn_builds_notify() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE rid bigint; bname text;
BEGIN
    IF NEW.kind NOT IN ('extract', 'rebuild_all') OR NEW.status NOT IN ('done', 'failed') OR OLD.status = NEW.status THEN RETURN NULL; END IF;
    SELECT name INTO bname FROM knowledge_bases WHERE id = NEW.base_id;
    FOR rid IN SELECT kn_base_curator_ids(NEW.base_id) LOOP
        PERFORM kn_notify(rid, CASE WHEN NEW.status = 'done' THEN 'build_done' ELSE 'build_failed' END,
                          CASE WHEN NEW.status = 'done' THEN 'Build finished: ' || bname ELSE 'Build failed: ' || bname END,
                          NEW.items_done || ' sections extracted, ' || NEW.items_failed || ' failed' || COALESCE('. ' || left(NEW.error, 200), ''),
                          'build', NEW.id, 'build:' || NEW.id || ':' || NEW.status || ':' || rid);
    END LOOP;
    RETURN NULL;
END$$;
CREATE TRIGGER builds_notify AFTER UPDATE OF status ON builds FOR EACH ROW EXECUTE FUNCTION kn_builds_notify();

-- A proposal tells the curators; a share tells the member (or the department's members).
CREATE OR REPLACE FUNCTION kn_proposals_notify() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE rid bigint;
BEGIN
    FOR rid IN SELECT kn_base_curator_ids(NEW.base_id) LOOP
        PERFORM kn_notify(rid, 'proposal', 'Archivist: ' || NEW.title, left(NEW.reason, 300), 'archivist_proposal', NEW.id, 'proposal:' || NEW.id || ':' || rid);
    END LOOP;
    RETURN NULL;
END$$;
CREATE TRIGGER archivist_proposals_notify AFTER INSERT ON archivist_proposals FOR EACH ROW EXECUTE FUNCTION kn_proposals_notify();

CREATE OR REPLACE FUNCTION kn_share_notify() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE bname text; who text; rid bigint;
BEGIN
    IF NEW.principal_kind NOT IN ('member', 'department') THEN RETURN NULL; END IF;
    SELECT name INTO bname FROM knowledge_bases WHERE id = NEW.base_id;
    SELECT display_name INTO who FROM members WHERE id = app_current_member_id();
    IF NEW.principal_kind = 'member' THEN
        PERFORM kn_notify(NEW.principal_id, 'shared', COALESCE(who, 'Someone') || ' shared "' || bname || '" with you', 'You can ' || NEW.level, 'base', NEW.base_id);
    ELSE
        FOR rid IN SELECT dm.member_id FROM department_members dm WHERE dm.department_id = NEW.principal_id AND dm.left_at IS NULL LOOP
            PERFORM kn_notify(rid, 'shared', COALESCE(who, 'Someone') || ' shared "' || bname || '" with your department', 'You can ' || NEW.level, 'base', NEW.base_id);
        END LOOP;
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER base_members_notify AFTER INSERT ON base_members FOR EACH ROW EXECUTE FUNCTION kn_share_notify();

-- ---------------------------------------------------------------------------------------------
-- Engine usage: the application's own count per base, month and kind. Dollars are the kernel's (K24, the AI statement).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE ai_usage (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    base_id        bigint NOT NULL REFERENCES knowledge_bases(id) ON DELETE CASCADE,
    month          date NOT NULL CHECK (month = date_trunc('month', month)::date),
    kind           text NOT NULL CHECK (kind IN ('extract', 'answer', 'embed')),
    calls          integer NOT NULL DEFAULT 0 CHECK (calls >= 0),
    input_tokens   bigint NOT NULL DEFAULT 0 CHECK (input_tokens >= 0),
    output_tokens  bigint NOT NULL DEFAULT 0 CHECK (output_tokens >= 0),
    chars          bigint NOT NULL DEFAULT 0 CHECK (chars >= 0),           -- characters of text sent (the monthly limit counts these)
    updated_at     timestamptz NOT NULL DEFAULT now(),
    UNIQUE (base_id, month, kind)
);
CREATE INDEX ai_usage_month_idx ON ai_usage (month, kind);
CREATE TRIGGER ai_usage_touch BEFORE UPDATE ON ai_usage FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- One upsert per engine call (the worker and kn_ask()).
CREATE OR REPLACE FUNCTION kn_usage_add(p_base_id bigint, p_kind text, p_calls integer DEFAULT 1, p_input_tokens bigint DEFAULT 0, p_output_tokens bigint DEFAULT 0,
                                        p_chars bigint DEFAULT 0, p_at timestamptz DEFAULT now()) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    INSERT INTO ai_usage (base_id, month, kind, calls, input_tokens, output_tokens, chars)
    VALUES (p_base_id, date_trunc('month', p_at)::date, p_kind, p_calls, p_input_tokens, p_output_tokens, p_chars)
    ON CONFLICT (base_id, month, kind) DO UPDATE
       SET calls = ai_usage.calls + EXCLUDED.calls, input_tokens = ai_usage.input_tokens + EXCLUDED.input_tokens,
           output_tokens = ai_usage.output_tokens + EXCLUDED.output_tokens, chars = ai_usage.chars + EXCLUDED.chars;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON feeds, agent_dispatches, archivist_proposals, notifications, notification_prefs, notification_outbox, ai_usage TO knowledge_rw;
GRANT EXECUTE ON FUNCTION kn_base_curator_ids(bigint) TO knowledge_rw, knowledge_records_ro, knowledge_activity_ro;
REVOKE ALL ON FUNCTION kn_notify(bigint, text, text, text, text, bigint, text), kn_usage_add(bigint, text, integer, bigint, bigint, bigint, timestamptz) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION kn_notify(bigint, text, text, text, text, bigint, text), kn_usage_add(bigint, text, integer, bigint, bigint, bigint, timestamptz) TO knowledge_rw;

COMMIT;
