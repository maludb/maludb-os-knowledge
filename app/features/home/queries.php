<?php
declare(strict_types=1);

/**
 * The home (screen `home`, design §9; docs/build-specs/home-admin.md "Home"). One query per region, every one through an mcp_* view or a read
 * function — the database decides what the caller sees. A region the caller has no right to is null (and not rendered); an empty one is a list.
 *   home_summary()    the whole page: bases, first_run, builds, recent_questions, pins_due, archivist_note, gaps, admin, may
 *   home_builds()     the builds running on the bases I curate, with kn_build_progress()'s percent and ETA (the polled region)
 *   home_gaps()       for each base I curate: the week's top three gap clusters and the thumbs-down count
 *   (admin_home() is app/features/admin/queries.php: usage, failed builds, dispatches, open proposals)
 * A person with no base sees ONE card (first_run) and nothing else.
 */
require_once dirname(__DIR__) . '/admin/queries.php';

const HOME_GAP_DAYS = 7;

function home_summary(PDO $pdo, int $memberId): array
{
    $bases = $pdo->query('SELECT * FROM mcp_bases WHERE archived_at IS NULL ORDER BY updated_at DESC, name LIMIT 50')->fetchAll();
    $may = ['create' => has_right('bases.create'), 'admin' => has_right('settings.manage'), 'costs' => has_right('costs.read')];
    $firstRun = $bases === [];
    $out = ['bases' => $bases, 'first_run' => $firstRun, 'builds' => null, 'recent_questions' => [], 'pins_due' => [], 'archivist_note' => null, 'gaps' => null, 'admin' => null, 'may' => $may];
    if ($firstRun) {
        return $out;
    }
    $curates = array_filter($bases, static fn (array $b): bool => (bool) $b['i_curate']) !== [];
    $out['builds'] = $curates ? home_builds($pdo) : null;
    $out['recent_questions'] = home_recent_questions($pdo, $memberId);
    $out['pins_due'] = home_pins_due($pdo, $memberId);
    $out['archivist_note'] = home_note($pdo);
    $out['gaps'] = $curates && has_right('questions.review') ? home_gaps($pdo, $memberId) : null;
    $out['admin'] = $may['admin'] ? admin_home($pdo, $may['costs']) : null;
    return $out;
}

/** The builds queued or running on the bases the caller curates, with the progress the build screen shows. */
function home_builds(PDO $pdo): array
{
    $rows = $pdo->query("SELECT b.build_id, b.base_id, x.name AS base_name, b.kind, b.status FROM mcp_builds b JOIN mcp_bases x ON x.base_id = b.base_id
                          WHERE b.status IN ('queued', 'running') ORDER BY b.requested_at, b.build_id LIMIT 20")->fetchAll();
    foreach ($rows as &$r) {
        $j = one_value($pdo, 'SELECT kn_build_progress(:b)', ['b' => (int) $r['build_id']]);
        $p = $j === null ? [] : (json_decode((string) $j, true) ?: []);
        $r['percent'] = (int) ($p['percent'] ?? 0);
        $r['eta_seconds'] = isset($p['eta_seconds']) ? (int) $p['eta_seconds'] : null;
        $r['items_done'] = (int) ($p['items_done'] ?? 0);
        $r['items_total'] = (int) ($p['items_total'] ?? 0);
        $r['status'] = (string) ($p['status'] ?? $r['status']);
    }
    unset($r);
    return $rows;
}

/** My five newest questions (the first line is cut for the card), with their base's name. */
function home_recent_questions(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT q.question_id, q.base_id, b.name AS base_name, q.question, q.status, q.created_at FROM mcp_questions q JOIN mcp_bases b ON b.base_id = q.base_id
                          WHERE q.asked_by_member_id = :m ORDER BY q.created_at DESC, q.question_id DESC LIMIT 5');
    $st->execute(['m' => $memberId]);
    return $st->fetchAll();
}

/** The pins due for review that are mine or on a base I curate. */
function home_pins_due(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare("SELECT p.pinned_answer_id, p.base_id, b.name AS base_name, p.title, p.review_until, p.owner_name FROM mcp_pinned_answers p JOIN mcp_bases b ON b.base_id = p.base_id
                          WHERE p.status = 'active' AND p.review_due AND (p.owner_member_id = :m OR b.i_curate) ORDER BY p.review_until, p.pinned_answer_id LIMIT 10");
    $st->execute(['m' => $memberId]);
    return $st->fetchAll();
}

/** My latest Monday note (the bell row): its title and first lines. Null when none was ever sent to me. */
function home_note(PDO $pdo): ?array
{
    $r = $pdo->query("SELECT notification_id, title, body, record_type, record_id, read_at, created_at FROM mcp_notifications WHERE kind = 'monday_note' ORDER BY created_at DESC, notification_id DESC LIMIT 1")->fetch();
    if ($r === false) {
        return null;
    }
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $r['body']) ?: []), static fn (string $l): bool => $l !== ''));
    $r['lines'] = array_map(static fn (string $l): string => mb_substr($l, 0, 160), array_slice($lines, 0, 3));
    return $r;
}

/** For each base I curate (not archived): the week's three biggest gap clusters and how many answers were rated down. [{base_id, name, clusters[], thumbs_down}] — only bases with something to say. */
function home_gaps(PDO $pdo, int $memberId): array
{
    $bases = $pdo->query('SELECT base_id, name FROM mcp_bases WHERE i_curate AND archived_at IS NULL ORDER BY updated_at DESC, name LIMIT 12')->fetchAll();
    $out = [];
    foreach ($bases as $b) {
        $st = $pdo->prepare('SELECT cluster_key, question_count, sample_question FROM kn_question_gaps(:b, now() - make_interval(days => :d)) LIMIT 3');
        $st->execute(['b' => (int) $b['base_id'], 'd' => HOME_GAP_DAYS]);
        $clusters = $st->fetchAll();
        $down = (int) one_value($pdo, "SELECT count(*) FROM mcp_question_feedback WHERE base_id = :b AND rating = 'down' AND updated_at >= now() - make_interval(days => :d)", ['b' => (int) $b['base_id'], 'd' => HOME_GAP_DAYS]);
        if ($clusters !== [] || $down > 0) {
            $out[] = ['base_id' => (int) $b['base_id'], 'name' => $b['name'], 'clusters' => $clusters, 'thumbs_down' => $down];
        }
    }
    return $out;
}
