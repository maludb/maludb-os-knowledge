<?php
declare(strict_types=1);
/**
 * Action `question_feedback` (answers.rate; the question visible): up or down, an optional comment (≤ 2,000; Skip sends none). One rating per
 * person per question. A thumbs-down tells the base's curators once each; a change to up tells nobody. Logged `question.feedback` (rating,
 * has_comment, changed — never the comment). The window gets its turn back; a browser lands on the question.
 */
require_once dirname(__DIR__, 2) . '/app/features/ask/handler.php';
kn_handler_begin();
require_right('answers.rate');
$pdo = db();
[$q, $b] = question_from_request($pdo);
$qid = (int) $q['question_id'];
$rating = (string) (req_val('rating') ?? '');
if (!in_array($rating, ['up', 'down'], true)) {
    kn_refuse_fields(['rating' => 'A rating is up or down.']);
}
$comment = req_has('skip') ? null : (req_val('comment') ?: null);
if ($comment !== null && mb_strlen($comment) > 2000) {
    kn_refuse_fields(['comment' => 'A comment is at most 2,000 characters.']);
}
$me = (int) current_member_id();
$r = kn_guard($pdo, static function () use ($pdo, $q, $qid, $me, $rating, $comment, $b): array {
    $pdo->beginTransaction();
    $r = rate_question($pdo, $qid, $me, $rating, $comment);
    question_log($pdo, 'question.feedback', $q, ['rating' => $rating, 'has_comment' => $comment !== null, 'changed' => $r['changed']]);
    if ($rating === 'down') {
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
    $pdo->commit();
    return $r;
});
ask_turn_or_done($pdo, $qid, $rating === 'up' ? 'Thumbs up' : 'Thumbs down' . ($comment !== null ? ', with a comment' : ''), $qid, kn_land('/questions/' . $qid, 'rated'), 'questionChanged', ['rating' => $rating, 'changed' => $r['changed']]);
