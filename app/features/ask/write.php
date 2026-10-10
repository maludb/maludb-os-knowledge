<?php
declare(strict_types=1);

/** Asking — the writes (slice 5): ask_log() for kn_ask(), the ratings, the pins. The database is the referee (one rating each, one active pin, only an answered question pinned). */

/** The source of an ask's log rows, by door. */
function ask_source(string $door): string
{
    return match ($door) { 'share' => 'application', 'api' => 'api', 'mcp' => 'mcp', 'agent' => 'agent', default => 'web' };
}

/**
 * question.ask then the outcome (question.answer for answered, evidence_only and failed; question.no_evidence, question.unverified,
 * question.refuse). Never the answer, a quote, an evidence passage, the expanded question or more than 120 characters of the question.
 */
function ask_log(PDO $pdo, array $req, int $baseId, ?int $questionId, ?array $area, string $conversationId, int $turnNo, string $status, ?string $errorCode,
                 array $citations, int $latency, string $question, ?string $model, array $usage): void
{
    $door = (string) $req['door'];
    $verified = count(array_filter($citations, static fn ($c) => $c['verified']));
    $opts = ['base_id' => $baseId, 'source' => ask_source($door), 'actor_member_id' => current_member_id()];
    if ($door === 'api') {
        $opts['token_id'] = (int) $req['token_id'];
    }
    $after = ['question_id' => $questionId, 'door' => $door, 'focus_area_id' => $area !== null ? (int) $area['id'] : null, 'conversation_id' => $conversationId,
              'turn_no' => $turnNo, 'status' => $status, 'citation_count' => count($citations), 'verified_count' => $verified, 'latency_ms' => $latency,
              'error_code' => $errorCode, 'question_excerpt' => mb_substr($question, 0, 120)];
    if ($door === 'api') {
        $after['key_label'] = (string) one_value($pdo, 'SELECT label FROM mcp_access_tokens WHERE id = :t', ['t' => (int) $req['token_id']]);
    }
    if ($door === 'share') {
        $after['application'] = (string) $req['application_key'];
    }
    if (!empty($req['is_eval'])) {
        $after['eval'] = true;
    }
    log_activity($pdo, 'question.ask', 'question', $questionId, $opts + ['after' => $after]);
    if (!empty($req['is_eval'])) {
        return;                                                   // an eval run changes nothing: the ask alone is logged
    }
    $outcome = match ($status) { 'no_evidence' => 'question.no_evidence', 'unverified' => 'question.unverified', 'refused' => 'question.refuse', default => 'question.answer' };
    log_activity($pdo, $outcome, 'question', $questionId, $opts + ['after' => ['status' => $status, 'citation_count' => count($citations), 'verified_count' => $verified,
        'error_code' => $errorCode, 'model' => $model, 'input_tokens' => (int) ($usage['input_tokens'] ?? 0), 'output_tokens' => (int) ($usage['output_tokens'] ?? 0)]]);
}

/**
 * One rating per person per question (an upsert): ['rating', 'changed' (it differed from the one before), 'first' (no rating before)]. $tokenId: the key a
 * rating through the token API came in on (a key's rating is its minter's: the row names both, db/015); a person's own rating clears it.
 */
function rate_question(PDO $pdo, int $questionId, int $memberId, string $rating, ?string $comment, ?int $tokenId = null): array
{
    $before = one_value($pdo, 'SELECT rating FROM question_feedback WHERE question_id = :q AND member_id = :m', ['q' => $questionId, 'm' => $memberId]);
    $pdo->prepare('INSERT INTO question_feedback (question_id, member_id, rating, comment, token_id) VALUES (:q, :m, :r, :c, :t)
                   ON CONFLICT (question_id, member_id) DO UPDATE SET rating = EXCLUDED.rating, comment = EXCLUDED.comment, token_id = EXCLUDED.token_id')
        ->execute(['q' => $questionId, 'm' => $memberId, 'r' => $rating, 'c' => $comment, 't' => $tokenId]);
    return ['rating' => $rating, 'changed' => $before !== null && $before !== $rating, 'first' => $before === null];
}

/** A thumbs-down tells the base's curators once each (kn_notify, kind feedback, one per question per curator). $q: the question row, $b: the base row. */
function feedback_tell_curators(PDO $pdo, array $q, array $b): void
{
    $qid = (int) $q['question_id'];
    $told = $pdo->prepare("SELECT 1 FROM notifications WHERE member_id = :m AND kind = 'feedback' AND record_type = 'question' AND record_id = :q");
    $cur = $pdo->prepare('SELECT kn_base_curator_ids(:b)');
    $cur->execute(['b' => (int) $q['base_id']]);
    foreach ($cur->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $told->execute(['m' => (int) $m, 'q' => $qid]);
        if ($told->fetchColumn() === false) {
            $pdo->prepare("SELECT kn_notify(:m, 'feedback', :t, :body, 'question', :q, :d)")->execute(['m' => (int) $m,
                't' => 'Thumbs down on "' . mb_substr(preg_replace('/\s+/', ' ', (string) $q['question']) ?? '', 0, 60) . '"', 'body' => (string) $b['name'], 'q' => $qid, 'd' => 'feedback:' . $qid . ':' . (int) $m]);
        }
    }
}

/** Pin through the database's own function (its words are the refusals). Answers the pin's id. */
function pin_question(PDO $pdo, int $questionId, int $by, ?string $title): int
{
    return (int) one_value($pdo, 'SELECT kn_pin_answer(:q, :m, :t)', ['q' => $questionId, 'm' => $by, 't' => $title]);
}

function unpin(PDO $pdo, int $pinId, int $by): void
{
    $pdo->prepare("UPDATE pinned_answers SET status = 'retired', retired_at = now(), retired_by = :m WHERE id = :p AND status = 'active'")->execute(['m' => $by, 'p' => $pinId]);
}

function review_pin(PDO $pdo, int $pinId, int $by): void
{
    $months = (int) one_value($pdo, 'SELECT pin_review_months FROM kn_settings WHERE id = 1');
    $pdo->prepare("UPDATE pinned_answers SET verified_at = now(), verified_by = :m, review_until = current_date + make_interval(months => :n) WHERE id = :p AND status = 'active'")
        ->execute(['m' => $by, 'n' => $months, 'p' => $pinId]);
}
