-- 004: Knowledge's roles and the rights they give (kernel db/145; roles-and-rights.md; the design §3, D2).
--
-- Published to the kernel by app_roles on the records MCP (os.app-roles/1). The kernel grants a SET of roles; the claims and
-- the feed carry them back into members.roles. What a role lets its holder do is the application's to enforce:
-- kn_has_right(right) answers from the catalogue and the mirror. The base role's KEY IS `user` on purpose: the installer's
-- --grant-standing-departments gives the standing departments the role keyed member/user/write, and everyone inside the
-- business may create a base of their own (§3) — so the switch is right here.
--
-- Rights are application-wide capabilities; WHO READS OR CURATES WHICH BASE is base_members (db/006): `curator` is the
-- capability to curate bases one does not own; curating a given base is base_members.level in (curate, own) or owning it.
-- A Knowledge admin sees every base, private ones included — logged `base.admin_view` (D2). A guest (an external holding
-- Reader and nothing above it) reaches only the bases named to them.
--
-- THE ESTATE'S DATA MODEL (design §6.4): the shape is Consultant Tracking db/004 (ct_ → kn_); the roles and rights are this
-- application's.
BEGIN;

CREATE TABLE kn_rights (
    right_key   text PRIMARY KEY CHECK (right_key ~ '^[a-z][a-z0-9_.]{0,59}$'),
    description text NOT NULL,
    sort_order  integer NOT NULL DEFAULT 0
);
CREATE TABLE kn_roles (
    role_key    text PRIMARY KEY CHECK (role_key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    description text NOT NULL,
    capability  text NOT NULL CHECK (capability IN ('read', 'write', 'admin')),
    is_admin    boolean NOT NULL DEFAULT false,
    sort_order  integer NOT NULL DEFAULT 0,
    CHECK (NOT is_admin OR capability = 'admin')
);
CREATE UNIQUE INDEX kn_roles_one_admin ON kn_roles (is_admin) WHERE is_admin;
CREATE TABLE kn_role_rights (
    role_key  text NOT NULL REFERENCES kn_roles(role_key) ON DELETE CASCADE,
    right_key text NOT NULL REFERENCES kn_rights(right_key) ON DELETE CASCADE,
    PRIMARY KEY (role_key, right_key)
);

INSERT INTO kn_rights (right_key, description, sort_order) VALUES
 ('bases.read',        'Ask the bases shared with them, read their sources, sections and graph, see their own questions', 1),
 ('answers.rate',      'Rate an answer (thumbs up or down, with a comment)', 2),
 ('bases.create',      'Create a knowledge base of their own — they are its curator', 3),
 ('sources.add',       'Add sources (files, text, a page) to a base where given curate', 4),
 ('questions.own',     'Keep a conversation: see and continue their own questions', 5),
 ('base.manage',       'On their bases: name, template, visibility, areas, guidelines, vocabulary, settings', 6),
 ('sources.manage',    'On their bases: add, replace and remove sources (removing pauses for an agent)', 7),
 ('builds.run',        'On their bases: start a build of new and stale sections under the monthly limit', 8),
 ('base.share',        'On their bases: share to members and departments; to a guest or an application pauses for an agent', 9),
 ('keys.manage',       'On their bases: mint, rotate and revoke API keys (pauses for an agent)', 10),
 ('answers.pin',       'On their bases: pin an answer as a note source with an owner and a review date', 11),
 ('questions.review',  'On their bases: the question log, the gaps, every asker''s questions and feedback', 12),
 ('feeds.manage',      'On their bases: follow a sibling''s feed through K7', 13),
 ('settings.manage',   'Run Knowledge: the application settings and templates', 14),
 ('model.manage',      'Change the model and embedding model MaluDB uses (pauses)', 15),
 ('limits.manage',     'Set the monthly text limit per base, the file size limit, key rate defaults', 16),
 ('retention.manage',  'Set question retention and purge before its time', 17),
 ('keys.all',          'See and revoke every key of every base', 18),
 ('costs.read',        'See engine usage by base and month', 19),
 ('agents.settings',   'See every agent''s dispatches and the Archivist''s proposals; link to the kernel for hires and grants', 20);

INSERT INTO kn_roles (role_key, name, description, capability, is_admin, sort_order) VALUES
 ('reader',  'Reader',          'Anyone a base is shared with: asks, reads sources and the graph, rates answers. An external holding only this role is a guest.', 'read',  false, 1),
 ('user',    'Member',          'Everyone inside the business: a Reader who may also create a base of their own and add sources where given curate.', 'write', false, 2),
 ('curator', 'Curator',         'Owns or curates bases: sources, guidelines, areas, builds, sharing, keys, pinned answers, the question log.', 'write', false, 3),
 ('admin',   'Knowledge admin', 'Every right on every base, the settings, the model, limits, retention, every key and the costs; sees private bases, logged. A super-admin holds this role.', 'admin', true, 4);

INSERT INTO kn_role_rights (role_key, right_key)
SELECT 'reader', r FROM unnest(ARRAY['bases.read','answers.rate']) r
UNION ALL SELECT 'user', r FROM unnest(ARRAY['bases.read','answers.rate','bases.create','sources.add','questions.own']) r
UNION ALL SELECT 'curator', r FROM unnest(ARRAY['bases.read','answers.rate','bases.create','sources.add','questions.own',
                                                 'base.manage','sources.manage','builds.run','base.share','keys.manage','answers.pin','questions.review','feeds.manage']) r
UNION ALL SELECT 'admin', right_key FROM kn_rights;

-- The rule: a right the member's roles give. The member must be active and admitted. A member the kernel has not yet
-- sent roles for (roles = '{}') is read from the capability: admin = admin, read = reader, anything else = user. A super-admin
-- holds every right (the kernel lists the admin role for them; this is the belt to that brace).
CREATE OR REPLACE FUNCTION kn_has_right(p_right text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 FROM members m
          JOIN kn_role_rights rr ON rr.right_key = p_right
               AND (rr.role_key = ANY (m.roles)
                    OR (cardinality(m.roles) = 0 AND rr.role_key = CASE m.capability WHEN 'admin' THEN 'admin' WHEN 'read' THEN 'reader' ELSE 'user' END)
                    OR (m.member_kind = 'human' AND m.business_role = 'super_admin' AND rr.role_key = 'admin'))
         WHERE m.id = app_current_member_id() AND m.status = 'active' AND m.capability IS NOT NULL);
END$$;

-- Runs Knowledge: the super-admin, or a human holding settings.manage.
CREATE OR REPLACE FUNCTION kn_is_admin() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_super_admin() OR (app_member_kind() = 'human' AND kn_has_right('settings.manage'));
END$$;

-- A guest: admitted, external, and holding no role above Reader (every default denies them; they reach only what is named).
CREATE OR REPLACE FUNCTION kn_member_is_guest(p_member_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (SELECT 1 FROM members m WHERE m.id = p_member_id AND m.status = 'active' AND m.capability IS NOT NULL
                     AND m.is_external
                     AND NOT (m.roles && ARRAY['user', 'curator', 'admin'])
                     AND NOT (cardinality(m.roles) = 0 AND m.capability IN ('write', 'admin')));
END$$;

CREATE OR REPLACE FUNCTION kn_is_guest() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN kn_member_is_guest(app_current_member_id());
END$$;

-- Someone who belongs in the business here: admitted and not a guest (a Reader inside, a Member, a Curator, an admin, an
-- agent granted a role). The `everyone` principal and an internal base reach exactly these.
CREATE OR REPLACE FUNCTION kn_is_member_here() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_active_member() AND NOT kn_is_guest();
END$$;

-- What app_roles answers, in one read.
CREATE OR REPLACE VIEW mcp_app_roles AS
SELECT r.role_key, r.name, r.description, r.capability, r.is_admin, r.sort_order,
       COALESCE((SELECT array_agg(rr.right_key ORDER BY h.sort_order) FROM kn_role_rights rr
                   JOIN kn_rights h ON h.right_key = rr.right_key WHERE rr.role_key = r.role_key), '{}') AS rights
FROM kn_roles r;

GRANT SELECT ON kn_rights, kn_roles, kn_role_rights, mcp_app_roles TO knowledge_rw, knowledge_records_ro;
GRANT EXECUTE ON FUNCTION kn_has_right(text), kn_is_admin(), kn_is_guest(), kn_member_is_guest(bigint), kn_is_member_here()
    TO knowledge_rw, knowledge_records_ro, knowledge_activity_ro;

COMMIT;
