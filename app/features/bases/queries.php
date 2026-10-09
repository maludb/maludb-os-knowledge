<?php
declare(strict_types=1);

/**
 * Bases — the reads of slice 1 (docs/build-specs/bases-core.md "Query functions"). Through the mcp_* views and the read functions
 * only (kn_visible_base_ids() once per statement); base_reach_without_admin() and the footprint read the base tables because they
 * answer a question about the record itself, not about who may see it.
 */

const BASE_STATUSES = ['empty' => 'secondary', 'building' => 'info', 'ready' => 'success', 'needs_rebuild' => 'warning'];
const BASE_LEVELS = ['own' => 'primary', 'curate' => 'info', 'read' => 'light'];
const BASE_SENSITIVITIES = [
    'standard'     => 'Standard — the usual care',
    'confidential' => 'Confidential — for the people it is shared with',
    'restricted'   => 'Restricted — the most careful handling the engine offers',
];
const BASE_VISIBILITIES = [
    'private'  => 'Private — only the people, departments, agents and applications named on it',
    'internal' => 'Internal — everyone inside the business may read; guests never',
];

/** The bases I may read, as cards. $f: q (name), status, mine (own or curate), archived (include archived ones). Newest change first. */
function find_bases(PDO $pdo, array $f, int $limit = 100): array
{
    $where = [];
    $args = ['me' => (int) current_member_id()];
    if (($f['q'] ?? '') !== '') {
        $where[] = 'b.name ILIKE :q';
        $args['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $f['q']) . '%';
    }
    if (isset(BASE_STATUSES[$f['status'] ?? ''])) {
        $where[] = 'b.status = :status';
        $args['status'] = $f['status'];
    }
    if (!empty($f['mine'])) {
        $where[] = "b.my_level IN ('own', 'curate')";
    }
    if (empty($f['archived'])) {
        $where[] = 'b.archived_at IS NULL';
    }
    // i_belong: the admin's card of a private base they are not on carries "admin view" (the log is on opening, not here).
    $sql = "SELECT b.*, (b.owner_member_id = :me OR EXISTS (SELECT 1 FROM base_members bm WHERE bm.base_id = b.base_id
                    AND ((bm.principal_kind IN ('member', 'agent') AND bm.principal_id = :me)
                      OR (bm.principal_kind = 'department' AND bm.principal_id IN (SELECT dm.department_id FROM department_members dm WHERE dm.member_id = :me AND dm.left_at IS NULL))))) AS i_belong
              FROM mcp_bases b" . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY b.updated_at DESC, b.name LIMIT ' . max(1, min($limit, 500));
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function find_base(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_bases WHERE base_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** kn_base_overview() decoded: the base at a glance, its counts, areas, last build, curators; a curator's extras. Null when unseen. */
function base_overview(PDO $pdo, int $id): ?array
{
    $j = one_value($pdo, 'SELECT kn_base_overview(:id)', ['id' => $id]);
    return $j === null ? null : json_decode((string) $j, true);
}

/** Who may read or curate a base (mcp_base_members — a curator's view): the everyone row first, then by kind and name. */
function base_members_list(PDO $pdo, int $baseId): array
{
    $st = $pdo->prepare("SELECT bm.*, g.display_name AS granted_by_name, (bm.principal_kind = 'member' AND COALESCE(m.is_guest, false)) AS is_guest
                           FROM mcp_base_members bm
                           LEFT JOIN mcp_members g ON g.member_id = bm.granted_by
                           LEFT JOIN mcp_members m ON m.member_id = bm.principal_id AND bm.principal_kind = 'member'
                          WHERE bm.base_id = :b
                          ORDER BY bm.principal_kind <> 'everyone', array_position(ARRAY['member', 'agent', 'department', 'application'], bm.principal_kind), lower(bm.principal_name)");
    $st->execute(['b' => $baseId]);
    return $st->fetchAll();
}

/** A base's history from the log (screen views left out), newest first, each row with its sentence. */
function base_timeline(PDO $pdo, int $baseId, int $limit = 50): array
{
    require_once APP_ROOT . '/app/features/activity/queries.php';
    $st = $pdo->prepare("SELECT * FROM mcp_activity_log WHERE base_id = :b AND action <> 'screen.view' ORDER BY occurred_at DESC, activity_id DESC LIMIT " . max(1, min($limit, 200)));
    $st->execute(['b' => $baseId]);
    return $st->fetchAll();
}

function find_templates(PDO $pdo, bool $activeOnly = true): array
{
    $rows = $pdo->query('SELECT * FROM mcp_templates' . ($activeOnly ? ' WHERE is_active' : '') . ' ORDER BY sort_order, name')->fetchAll();
    return array_map('template_decode', $rows);
}

function find_template(PDO $pdo, string $key): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_templates WHERE template_key = :k');
    $st->execute(['k' => $key]);
    $r = $st->fetch();
    return $r === false ? null : template_decode($r);
}

/** A template row with its guideline set decoded and its subject types as a list. */
function template_decode(array $t): array
{
    $t['guidelines'] = json_decode((string) $t['guidelines'], true) ?: [];
    $t['subject_types'] = pg_text_array((string) ($t['subject_types'] ?? '{}'));
    return $t;
}

/** The guests a base can be shared with: externals holding Reader alone (mcp_members.is_guest). */
function guests_for_pick(PDO $pdo): array
{
    return $pdo->query('SELECT member_id, display_name, member_kind, job_title FROM mcp_members WHERE is_guest ORDER BY display_name')->fetchAll();
}

/** The people and agents a base can be shared with under "A person or an agent": members_for_pick() minus guests. */
function people_and_agents_for_pick(PDO $pdo): array
{
    return $pdo->query('SELECT member_id, display_name, member_kind, job_title FROM mcp_members WHERE NOT is_guest ORDER BY member_kind, display_name')->fetchAll();
}

/** One page of either list for the record picker: [rows, total, page, page_size, pages]. $guests: the guests; else people and agents. */
function search_share_principals(PDO $pdo, string $q, bool $guests, int $page, int $pageSize = 25): array
{
    $where = $guests ? 'is_guest' : 'NOT is_guest';
    $args = [];
    if ($q !== '') {
        $where .= ' AND (display_name ILIKE :q OR job_title ILIKE :q)';
        $args['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    }
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_members WHERE $where", $args);
    $pages = max(1, (int) ceil($total / $pageSize));
    $page = max(1, min($page, $pages));
    $st = $pdo->prepare("SELECT member_id, display_name, member_kind, job_title FROM mcp_members WHERE $where ORDER BY display_name, member_id LIMIT $pageSize OFFSET " . (($page - 1) * $pageSize));
    $st->execute($args);
    $rows = array_map(static fn (array $r): array => [
        'id' => (int) $r['member_id'], 'label' => $r['display_name'], 'detail' => (string) ($r['job_title'] ?? ''),
        'badge' => $guests ? 'guest' : ($r['member_kind'] === 'agent' ? 'agent' : 'person'),
        'color' => $guests ? 'warning' : ($r['member_kind'] === 'agent' ? 'info' : 'secondary'),
    ], $st->fetchAll());
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'page_size' => $pageSize, 'pages' => $pages];
}

/** The name of a member the caller may see, for the picker's chosen label. $guests: only a guest; false: only a non-guest. */
function share_principal_label(PDO $pdo, int $id, bool $guests): ?string
{
    $v = one_value($pdo, 'SELECT display_name FROM mcp_members WHERE member_id = :id AND is_guest = :g', ['id' => $id, 'g' => $guests ? 'true' : 'false']);
    return $v === null ? null : (string) $v;
}

/** Does the member reach the base without being the admin: its owner, or a row naming them, an agent they are, or a department of theirs. */
function base_reach_without_admin(PDO $pdo, int $baseId, int $memberId): bool
{
    return db_bool($pdo, "SELECT EXISTS (SELECT 1 FROM knowledge_bases b WHERE b.id = :b AND b.owner_member_id = :m)
                              OR EXISTS (SELECT 1 FROM base_members bm WHERE bm.base_id = :b
                                            AND ((bm.principal_kind IN ('member', 'agent') AND bm.principal_id = :m)
                                              OR (bm.principal_kind = 'department' AND bm.principal_id IN
                                                    (SELECT dm.department_id FROM department_members dm WHERE dm.member_id = :m AND dm.left_at IS NULL))))",
        ['b' => $baseId, 'm' => $memberId]);
}

/** A live (not forgotten) source: the base is not empty, and its template no longer switches. */
function base_has_live_source(PDO $pdo, int $baseId): bool
{
    return db_bool($pdo, "SELECT EXISTS (SELECT 1 FROM sources WHERE base_id = :b AND status <> 'forgotten')", ['b' => $baseId]);
}

/** What of a base the engine holds: sections with a document id, areas with a project. */
function base_engine_footprint(PDO $pdo, int $baseId): array
{
    $st = $pdo->prepare('SELECT (SELECT count(*) FROM sections WHERE base_id = :b AND maludb_document_id IS NOT NULL) AS documents,
                                (SELECT count(*) FROM focus_areas WHERE base_id = :b AND maludb_project_id IS NOT NULL) AS projects');
    $st->execute(['b' => $baseId]);
    $r = $st->fetch();
    return ['documents' => (int) $r['documents'], 'projects' => (int) $r['projects']];
}

/** The base's live build, if any: [build_id, kind, status] or null (one per base, db/009). */
function base_live_build(PDO $pdo, int $baseId): ?array
{
    $st = $pdo->prepare("SELECT id AS build_id, kind, status FROM builds WHERE base_id = :b AND status IN ('queued', 'running') LIMIT 1");
    $st->execute(['b' => $baseId]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}
