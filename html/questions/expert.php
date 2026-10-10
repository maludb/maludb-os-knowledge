<?php
declare(strict_types=1);
/**
 * Action `question_expert` (people only; bases.read; the question mine or a base I curate; evidence_only; an expert named): one dispatch to
 * the expert through the kernel's chat endpoint (a live one is returned, not doubled). Logged `agent.dispatch`, then `agent.reply` or
 * `agent.fail` when the kernel's first answer is final (the poll logs it otherwise). The window gets the turn back with the thinking row.
 */
require_once dirname(__DIR__, 2) . '/app/features/ask/handler.php';
kn_handler_begin();
require_human();
require_right('bases.read');
$pdo = db();
[$q, $b] = question_from_request($pdo);
$qid = (int) $q['question_id'];
if (expert_member_id($pdo) === null) {
    refuse(422, KN_EXPERT_NOT_NAMED);
}
if ($q['status'] !== 'evidence_only') {
    refuse(422, 'The expert is asked about passages without an answer only.');
}
$me = (int) current_member_id();
$r = kn_guard($pdo, static fn (): array => ask_expert_start($pdo, $qid, $me));
$did = (int) $r['dispatch_id'];
if (!$r['existing']) {
    log_activity($pdo, 'agent.dispatch', 'agent_dispatch', $did, ['base_id' => (int) $q['base_id'], 'after' => ['dispatch_id' => $did, 'agent_member_id' => expert_member_id($pdo), 'kind' => 'ask', 'question_id' => $qid]]);
}
expert_log_finish($pdo, $q, $did, $r);
$words = match ($r['status']) { 'sent' => 'The expert is reading the passages', 'answered' => 'The expert answered', 'awaiting_approval' => 'The expert\'s turn waits for approval', default => (string) $r['detail'] };
ask_turn_or_done($pdo, $qid, $words, $did, kn_land('/questions/' . $qid, null, 'expert-' . $qid), 'questionChanged', ['dispatch_status' => $r['status']]);
