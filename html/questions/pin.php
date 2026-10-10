<?php
declare(strict_types=1);
/**
 * Action `answer_pin` (answers.pin on a curated base): kn_pin_answer() writes the note, the `note` source (sectioning — the worker cuts it, a
 * build learns it) and the pin with its review date; its refusals are its own words ("This answer is already pinned", "Only an answered
 * question is pinned"). Logged `question.pin` (pinned_answer_id, source_id, note_id, review_until). Lands on /pinned#pin-{id}.
 */
require_once dirname(__DIR__, 2) . '/app/features/ask/handler.php';
kn_handler_begin();
require_right('answers.pin');
$pdo = db();
[$q, $b] = question_from_request($pdo);
$qid = (int) $q['question_id'];
if (!may_pin($pdo, (int) $q['base_id'])) {
    refuse(403, 'Only a curator of the base pins an answer.');
}
$title = req_val('title');
if ($title !== null && mb_strlen($title) > 300) {
    kn_refuse_fields(['title' => 'A title is at most 300 characters.']);
}
$me = (int) current_member_id();
$p = kn_guard($pdo, static function () use ($pdo, $q, $qid, $me, $title): array {
    $pdo->beginTransaction();
    $pid = pin_question($pdo, $qid, $me, $title === '' ? null : $title);
    $st = $pdo->prepare('SELECT id, source_id, note_id, review_until FROM pinned_answers WHERE id = :p');
    $st->execute(['p' => $pid]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    question_log($pdo, 'question.pin', $q, ['pinned_answer_id' => $pid, 'question_id' => $qid, 'source_id' => (int) $p['source_id'], 'note_id' => (int) $p['note_id'], 'review_until' => $p['review_until']], 'pinned_answer', $pid);
    $pdo->commit();
    return $p;
});
ask_turn_or_done($pdo, $qid, 'Pinned — it becomes a source at the next build', (int) $p['id'], kn_land('/pinned', 'pinned', 'pin-' . (int) $p['id']), 'questionChanged, sourceChanged',
    ['source_id' => (int) $p['source_id'], 'review_until' => $p['review_until']]);
