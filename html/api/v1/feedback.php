<?php
declare(strict_types=1);
/**
 * POST /api/v1/questions/{id}/feedback {rating: up|down, comment?} — rate an answer: the same visibility as the question's read (a key: its own or its
 * predecessor's questions; a session caller: answers.rate and mcp_questions). Stored through rate_question() — a key's rating is its minter's
 * (question_feedback is one row per member and question; the row names the key in token_id) and replaces the minter's own. A thumbs-down tells the
 * base's curators once. Logged `question.feedback` (rating, has_comment, changed — never the comment), with the key's token_id. 200 {question_id,
 * rating, comment, updated_at}.
 */
require_once dirname(__DIR__, 3) . '/app/api/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/keys/handler.php';
api_cors();
api_require_method('POST');
api_authenticate();
api_rate_limit();
api_session_csrf();
$pdo = db();
$in = api_body();
$q = api_question_for_caller($pdo, (int) ($_GET['id'] ?? 0));
$key = api_key();
if ($key === null && !has_right('answers.rate')) {
    api_error('forbidden', 'You may not rate answers.', 403);
}
$rating = (string) ($in['rating'] ?? '');
$comment = isset($in['comment']) && is_string($in['comment']) && trim($in['comment']) !== '' ? trim($in['comment']) : null;
$fields = [];
if (!in_array($rating, ['up', 'down'], true)) {
    $fields['rating'] = 'A rating is up or down.';
}
if ($comment !== null && mb_strlen($comment) > 2000) {
    $fields['comment'] = 'A comment is at most 2,000 characters.';
}
if ($fields !== []) {
    api_error('invalid', (string) reset($fields), 422, ['fields' => (object) $fields]);
}
$qid = (int) $q['question_id'];
$b = find_base($pdo, (int) $q['base_id']);
$me = (int) current_member_id();
try {
    $pdo->beginTransaction();
    $r = rate_question($pdo, $qid, $me, $rating, $comment, $key['token_id'] ?? null);
    log_activity($pdo, 'question.feedback', 'question', $qid, ['base_id' => (int) $q['base_id'], 'after' => ['rating' => $rating, 'has_comment' => $comment !== null, 'changed' => $r['changed']]]);
    if ($rating === 'down') {
        feedback_tell_curators($pdo, $q, $b);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof PDOException && db_is_raise($e)) {
        api_error('invalid', db_message($e, 'That could not be saved.'), 422);
    }
    throw $e;
}
$row = $pdo->prepare('SELECT comment, updated_at FROM question_feedback WHERE question_id = :q AND member_id = :m');
$row->execute(['q' => $qid, 'm' => $me]);
$f = $row->fetch(PDO::FETCH_ASSOC);
api_json(['question_id' => $qid, 'rating' => $rating, 'comment' => $f['comment'] ?? null, 'updated_at' => json_ts((string) ($f['updated_at'] ?? ''))]);
