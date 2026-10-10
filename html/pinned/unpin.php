<?php
declare(strict_types=1);
/** Action `answer_unpin` (people only; answers.pin and a curator of the pin's base): an active pin retired — the trigger forgets its note source. Logged `question.unpin`. */
require_once dirname(__DIR__, 2) . '/app/features/ask/handler.php';
kn_handler_begin();
require_human();
require_right('answers.pin');
$pdo = db();
$p = find_pin($pdo, request_integer('pinned_answer') ?? request_integer('id') ?? 0) ?? refuse(404, 'Pinned answer not found.');
$pid = (int) $p['pinned_answer_id'];
if (!may_pin($pdo, (int) $p['base_id'])) {
    refuse(403, 'Only a curator of the base unpins an answer.');
}
if ($p['status'] !== 'active') {
    refuse(422, 'This answer is no longer pinned.');
}
$me = (int) current_member_id();
kn_guard($pdo, static function () use ($pdo, $p, $pid, $me): void {
    $pdo->beginTransaction();
    unpin($pdo, $pid, $me);
    log_activity($pdo, 'question.unpin', 'pinned_answer', $pid, ['base_id' => (int) $p['base_id'], 'after' => ['pinned_answer_id' => $pid, 'source_id' => $p['source_id'] === null ? null : (int) $p['source_id']]]);
    $pdo->commit();
});
kn_done('Unpinned "' . mb_substr((string) $p['title'], 0, 80) . '"', $pid, kn_land('/pinned', 'unpinned'), 'questionChanged, sourceChanged');
