<?php
declare(strict_types=1);
/** Action `answer_pin_review` (answers.pin and a curator): verified again — verified_at now, review_until moved on by pin_review_months. Logged `question.pin` with review true. */
require_once dirname(__DIR__, 2) . '/app/features/ask/handler.php';
kn_handler_begin();
require_right('answers.pin');
$pdo = db();
$p = find_pin($pdo, request_integer('pinned_answer') ?? request_integer('id') ?? 0) ?? refuse(404, 'Pinned answer not found.');
$pid = (int) $p['pinned_answer_id'];
if (!may_pin($pdo, (int) $p['base_id'])) {
    refuse(403, 'Only a curator of the base verifies a pinned answer.');
}
if ($p['status'] !== 'active') {
    refuse(422, 'This answer is no longer pinned.');
}
$me = (int) current_member_id();
$until = kn_guard($pdo, static function () use ($pdo, $p, $pid, $me): string {
    $pdo->beginTransaction();
    review_pin($pdo, $pid, $me);
    $until = (string) one_value($pdo, 'SELECT review_until FROM pinned_answers WHERE id = :p', ['p' => $pid]);
    log_activity($pdo, 'question.pin', 'pinned_answer', $pid, ['base_id' => (int) $p['base_id'], 'after' => ['pinned_answer_id' => $pid, 'review' => true, 'review_until' => $until]]);
    $pdo->commit();
    return $until;
});
kn_done('Verified again — review by ' . date('M j, Y', strtotime($until)), $pid, kn_land('/pinned', 'reviewed', 'pin-' . $pid), 'questionChanged', ['review_until' => $until]);
