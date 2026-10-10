<?php
declare(strict_types=1);

/** Asking — the reads (slice 5). Every read goes through a view or a read function: what the caller sees is the database's answer. */

/** The bases I may ask (mcp_bases, not archived, by name), with their status, section count and my level. */
function ask_bases(PDO $pdo): array
{
    return $pdo->query("SELECT base_id, key, name, status, section_count, my_level, i_curate,
                               (SELECT count(*) FROM mcp_sections x WHERE x.base_id = b.base_id AND x.status IN ('extracted', 'stale')) AS built_count
                          FROM mcp_bases b WHERE archived_at IS NULL ORDER BY lower(name), base_id")->fetchAll(PDO::FETCH_ASSOC);
}

function ask_areas(PDO $pdo, int $baseId): array
{
    $st = $pdo->prepare("SELECT focus_area_id, name, slug, description, example_questions, maludb_project_id FROM mcp_focus_areas WHERE base_id = :b AND status = 'active' ORDER BY position, lower(name), focus_area_id");
    $st->execute(['b' => $baseId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** The area's example questions first (the chosen area's), then the base's pinned answers' questions, newest first; de-duplicated. */
function suggested_questions(PDO $pdo, int $baseId, ?int $areaId, int $limit = 6): array
{
    $out = [];
    $seen = [];
    $add = static function (string $q, string $from, array $extra) use (&$out, &$seen, $limit): void {
        $k = mb_strtolower(trim($q));
        if ($k === '' || isset($seen[$k]) || count($out) >= $limit) {
            return;
        }
        $seen[$k] = true;
        $out[] = ['question' => trim($q), 'from' => $from] + $extra;
    };
    $areas = ask_areas($pdo, $baseId);
    usort($areas, static fn ($a, $b) => ((int) ($b['focus_area_id'] === $areaId)) <=> ((int) ($a['focus_area_id'] === $areaId)));
    foreach ($areas as $a) {
        foreach (kn_pg_literal_list($a['example_questions']) ?? [] as $q) {
            $add((string) $q, 'area', ['focus_area_id' => (int) $a['focus_area_id']]);
        }
    }
    $st = $pdo->prepare("SELECT pinned_answer_id, question_text FROM mcp_pinned_answers WHERE base_id = :b AND status = 'active' ORDER BY created_at DESC, pinned_answer_id DESC LIMIT 20");
    $st->execute(['b' => $baseId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $add((string) $p['question_text'], 'pin', ['pinned_answer_id' => (int) $p['pinned_answer_id']]);
    }
    return $out;
}

/** A conversation's turns, mine only, in turn order. */
function conversation_turns(PDO $pdo, string $conversationId, int $memberId): array
{
    $st = $pdo->prepare("SELECT * FROM mcp_questions WHERE conversation_id = :c AND asked_by_member_id = :m AND door IN ('web', 'mcp', 'agent') ORDER BY turn_no, question_id");
    $st->execute(['c' => $conversationId, 'm' => $memberId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** My conversations: the base, the first question, the turns, when last asked. */
function my_conversations(PDO $pdo, int $memberId, ?int $baseId, int $limit = 30): array
{
    $st = $pdo->prepare("SELECT q.conversation_id, q.base_id, b.name AS base_name, count(*) AS turns, max(q.created_at) AS last_at,
                                (array_agg(q.question ORDER BY q.turn_no))[1] AS first_question
                           FROM mcp_questions q JOIN mcp_bases b ON b.base_id = q.base_id
                          WHERE q.asked_by_member_id = :m AND q.door IN ('web', 'mcp', 'agent') AND q.conversation_id IS NOT NULL AND (CAST(:b AS bigint) IS NULL OR q.base_id = :b)
                          GROUP BY q.conversation_id, q.base_id, b.name ORDER BY max(q.created_at) DESC LIMIT " . max(1, min($limit, 200)));
    $st->execute(['m' => $memberId, 'b' => $baseId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** A question as the caller may see it (mcp_questions), or null. */
function find_question(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_questions WHERE question_id = :id');
    $st->execute(['id' => $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** A question's citations with their source's kind. */
function question_citations(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT c.* FROM mcp_citations c WHERE c.question_id = :id ORDER BY c.rank');
    $st->execute(['id' => $id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function question_feedback_rows(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_question_feedback WHERE question_id = :id ORDER BY updated_at DESC, feedback_id DESC');
    $st->execute(['id' => $id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function my_rating(PDO $pdo, int $questionId): ?string
{
    $v = one_value($pdo, 'SELECT rating FROM mcp_question_feedback WHERE question_id = :q AND member_id = :m', ['q' => $questionId, 'm' => (int) current_member_id()]);
    return $v === null ? null : (string) $v;
}

/** The base's log (kn_questions()): ['rows', 'total' (of this page, the function pages), 'page']. $f: status, door, key, area, asker, since, q. */
function base_questions(PDO $pdo, int $baseId, array $f, int $page): array
{
    $per = 50;
    $st = $pdo->prepare('SELECT * FROM kn_questions(:b, :status, :since, :asker, :door, :key, :area, :q, :lim, :off)');
    $st->execute(['b' => $baseId, 'status' => $f['status'] ?: null, 'since' => $f['since'] ?: null, 'asker' => $f['asker'] ?: null, 'door' => $f['door'] ?: null,
                  'key' => $f['key'] ?: null, 'area' => $f['area'] ?: null, 'q' => $f['q'] ?: null, 'lim' => $per + 1, 'off' => ($page - 1) * $per]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return ['rows' => array_slice($rows, 0, $per), 'more' => count($rows) > $per, 'page' => $page];
}

/** What the base could not answer, clustered (kn_question_gaps()). $since: 7, 30 or 90 (days). */
function base_gaps(PDO $pdo, int $baseId, string $since): array
{
    $st = $pdo->prepare("SELECT * FROM kn_question_gaps(:b, now() - make_interval(days => :d))");
    $st->execute(['b' => $baseId, 'd' => (int) $since]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** The thumbs-down answers of the base since then, with their questions and comments. */
function thumbs_down(PDO $pdo, int $baseId, string $since): array
{
    $st = $pdo->prepare("SELECT f.*, q.question, q.status FROM mcp_question_feedback f JOIN mcp_questions q ON q.question_id = f.question_id
                          WHERE f.base_id = :b AND f.rating = 'down' AND f.updated_at >= now() - make_interval(days => :d) ORDER BY f.updated_at DESC LIMIT 100");
    $st->execute(['b' => $baseId, 'd' => (int) $since]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** The pinned answers across the bases I read (mcp_pinned_answers, active), with their base's name and my reach on it. */
function find_pins(PDO $pdo, ?int $baseId, bool $dueOnly): array
{
    $st = $pdo->prepare("SELECT p.*, b.name AS base_name, b.i_curate FROM mcp_pinned_answers p JOIN mcp_bases b ON b.base_id = p.base_id
                          WHERE p.status = 'active' AND (CAST(:b AS bigint) IS NULL OR p.base_id = :b) AND (NOT :due OR p.review_due)
                          ORDER BY p.review_due DESC, p.review_until, p.pinned_answer_id DESC LIMIT 200");
    $st->execute(['b' => $baseId, 'due' => $dueOnly ? 'true' : 'false']);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function find_pin(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT p.*, b.name AS base_name, b.i_curate FROM mcp_pinned_answers p JOIN mcp_bases b ON b.base_id = p.base_id WHERE p.pinned_answer_id = :id');
    $st->execute(['id' => $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The latest dispatch to the expert about a question, as the caller may see it. */
function question_dispatch(PDO $pdo, int $questionId): ?array
{
    $st = $pdo->prepare("SELECT * FROM mcp_agent_dispatches WHERE record_type = 'question' AND record_id = :q AND kind = 'ask' ORDER BY dispatch_id DESC LIMIT 1");
    $st->execute(['q' => $questionId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
