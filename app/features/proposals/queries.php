<?php
declare(strict_types=1);

/**
 * The Archivist's proposals — the reads of slice 8 (mcp_archivist_proposals: the curators' of a base, the admin's everywhere). The subject of a proposal
 * is named through the mcp_* views the reader may see; one the caller cannot read is shown as a word, never a link.
 */

const PROPOSAL_STATUSES = ['proposed', 'accepted', 'dismissed'];
const PROPOSAL_KINDS = ['area', 'merge', 'gap', 'stale_source', 'failed_source', 'review_pin', 'unassigned', 'unverified', 'feed_stopped', 'limit_near', 'url_changed'];
/** What each kind is about (subject types it takes; 'none' = no subject): the Archivist's contract (agents-feeds.md, "The Archivist's proposals"). */
const PROPOSAL_SUBJECTS = [
    'area' => ['none'], 'merge' => ['none'], 'gap' => ['question'], 'stale_source' => ['base'], 'failed_source' => ['source'], 'review_pin' => ['pinned_answer'],
    'unassigned' => ['focus_area', 'source'], 'unverified' => ['question'], 'feed_stopped' => ['feed'], 'limit_near' => ['base'], 'url_changed' => ['source'],
];

function proposal_decode(array $p): array
{
    $p['payload'] = is_array($p['payload']) ? $p['payload'] : (json_decode((string) $p['payload'], true) ?: []);
    return $p;
}

/**
 * The proposals of a base (or of every base the caller curates when $baseId is null), newest first. $f: status (default proposed), kind, base (an id, when
 * $baseId is null). Each with the base's name and its subject as ['label', 'href'].
 */
function find_proposals(PDO $pdo, ?int $baseId, array $f): array
{
    $where = [];
    $args = [];
    $status = in_array($f['status'] ?? 'proposed', PROPOSAL_STATUSES, true) ? ($f['status'] ?? 'proposed') : 'proposed';
    $where[] = 'p.status = :status';
    $args['status'] = $status;
    if (in_array($f['kind'] ?? '', PROPOSAL_KINDS, true)) {
        $where[] = 'p.kind = :kind';
        $args['kind'] = $f['kind'];
    }
    $base = $baseId ?? (!empty($f['base']) ? (int) $f['base'] : null);
    if ($base !== null) {
        $where[] = 'p.base_id = :base';
        $args['base'] = $base;
    }
    $st = $pdo->prepare('SELECT p.*, b.name AS base_name, d.display_name AS decided_by_name FROM mcp_archivist_proposals p JOIN mcp_bases b ON b.base_id = p.base_id
                           LEFT JOIN mcp_members d ON d.member_id = p.decided_by WHERE ' . implode(' AND ', $where)
        . ' ORDER BY ' . ($status === 'proposed' ? 'p.created_at DESC, p.proposal_id DESC' : 'p.decided_at DESC, p.proposal_id DESC') . ' LIMIT 200');
    $st->execute($args);
    $rows = array_map('proposal_decode', $st->fetchAll());
    foreach ($rows as &$r) {
        $r['subject'] = proposal_subject($pdo, $r);
    }
    return $rows;
}

function find_proposal(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT p.*, b.name AS base_name, d.display_name AS decided_by_name FROM mcp_archivist_proposals p JOIN mcp_bases b ON b.base_id = p.base_id
                           LEFT JOIN mcp_members d ON d.member_id = p.decided_by WHERE p.proposal_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r = proposal_decode($r);
    $r['subject'] = proposal_subject($pdo, $r);
    return $r;
}

/** A proposal's subject for a person: ['label' => words, 'href' => screen|null]. A subject the caller cannot see (or that is gone) has a label and no link. */
function proposal_subject(PDO $pdo, array $p): array
{
    $type = $p['subject_type'];
    $id = $p['subject_id'] === null ? null : (int) $p['subject_id'];
    if ($type === null || $id === null) {
        return ['label' => $p['kind'] === 'merge' ? 'Subjects of the graph' : ($p['kind'] === 'area' ? 'A new focus area' : 'The base'), 'href' => null];
    }
    $one = static fn (string $sql): ?string => ($v = one_value($pdo, $sql, ['id' => $id])) === null ? null : (string) $v;
    switch ($type) {
        case 'source':
            $t = $one('SELECT title FROM mcp_sources WHERE source_id = :id');
            return $t === null ? ['label' => 'A source that is gone', 'href' => null] : ['label' => $t, 'href' => '/sources/' . $id];
        case 'question':
            $t = $one('SELECT question FROM mcp_questions WHERE question_id = :id');
            return $t === null ? ['label' => 'A question', 'href' => '/questions/' . $id] : ['label' => mb_substr($t, 0, 120), 'href' => '/questions/' . $id];
        case 'pinned_answer':
            $t = $one('SELECT title FROM mcp_pinned_answers WHERE pinned_answer_id = :id');
            return $t === null ? ['label' => 'A pinned answer', 'href' => '/pinned#pin-' . $id] : ['label' => $t, 'href' => '/pinned#pin-' . $id];
        case 'focus_area':
            $t = $one('SELECT name FROM mcp_focus_areas WHERE focus_area_id = :id');
            return $t === null ? ['label' => 'An area that is gone', 'href' => null] : ['label' => $t, 'href' => '/areas/' . $id];
        case 'feed':
            $t = $one('SELECT provider || \' · \' || tool FROM mcp_feeds WHERE feed_id = :id');
            return $t === null ? ['label' => 'A feed that is gone', 'href' => null] : ['label' => $t, 'href' => '/feeds/' . $id . '/edit'];
        case 'base':
            return ['label' => (string) ($p['base_name'] ?? 'The base'), 'href' => '/bases/' . $id];
    }
    return ['label' => $type . ' ' . $id, 'href' => null];
}

/** Is the subject a record of this base? Base tables, an existence check only (the Archivist reaches the base, not every question in it). */
function proposal_subject_in_base(PDO $pdo, int $baseId, string $type, int $id): bool
{
    $sql = match ($type) {
        'source' => 'SELECT 1 FROM sources WHERE id = :id AND base_id = :b',
        'question' => 'SELECT 1 FROM questions WHERE id = :id AND base_id = :b',
        'pinned_answer' => 'SELECT 1 FROM pinned_answers WHERE id = :id AND base_id = :b',
        'focus_area' => 'SELECT 1 FROM focus_areas WHERE id = :id AND base_id = :b',
        'feed' => 'SELECT 1 FROM feeds WHERE id = :id AND base_id = :b',
        'section' => 'SELECT 1 FROM sections WHERE id = :id AND base_id = :b',
        'vocabulary_term' => 'SELECT 1 FROM vocabulary_terms WHERE id = :id AND base_id = :b',
        'base' => 'SELECT 1 FROM knowledge_bases WHERE id = :id AND id = :b',
        default => null,
    };
    return $sql !== null && one_value($pdo, $sql, ['id' => $id, 'b' => $baseId]) !== null;
}

/**
 * What a curator dismissed inside the last $days days (90 by default), so the Archivist does not propose it again: [{proposal_id, kind, subject_type,
 * subject_id, title, decided_at}], newest first. Base table; the caller (the Archivist's tool, over the bridge) has already shown the base is its to read.
 */
function recently_dismissed(PDO $pdo, int $baseId, int $days = 90): array
{
    $st = $pdo->prepare("SELECT id AS proposal_id, kind, subject_type, subject_id, title, decided_at FROM archivist_proposals
                          WHERE base_id = :b AND status = 'dismissed' AND decided_at >= now() - make_interval(days => :d) ORDER BY decided_at DESC, id DESC");
    $st->execute(['b' => $baseId, 'd' => max(1, $days)]);
    return $st->fetchAll();
}

/** The build estimate of each base that has an open stale_source proposal among $rows, for the accept form: [base_id => estimate]. */
function proposal_estimates(PDO $pdo, array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        if ($r['kind'] === 'stale_source' && $r['status'] === 'proposed' && !isset($out[(int) $r['base_id']])) {
            $out[(int) $r['base_id']] = build_estimate($pdo, (int) $r['base_id'], 'extract');
        }
    }
    return $out;
}
