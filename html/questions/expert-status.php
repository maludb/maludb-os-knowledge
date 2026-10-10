<?php
declare(strict_types=1);
/**
 * GET /questions/{id}/expert-status?dispatch= — the expert partial's poll (not a screen of the manifest; nothing logged while it runs):
 * 204 while the run goes on (HTMX keeps polling), else the partial without the trigger; `agent.reply` / `agent.fail` logged once, when it
 * finishes. Ten minutes with no finish → failed. A dispatch not the caller's (nor of a base they curate): 404.
 */
require_once dirname(__DIR__, 2) . '/app/features/ask/handler.php';
require_right('bases.read');
$pdo = db();
$qid = request_integer('id') ?? refuse(422, 'Say which question.');
$did = request_integer('dispatch') ?? refuse(422, 'Say which dispatch.');
try {
    $p = ask_expert_poll($pdo, $did, (int) current_member_id());
} catch (DomainException) {
    refuse(404, 'Not found.');
}
if ((int) $p['dispatch']['record_id'] !== $qid) {
    refuse(404, 'Not found.');
}
header('Cache-Control: no-store');
$q = find_question($pdo, $qid) ?? refuse(404, 'Not found.');
if ($p['finished_now']) {
    expert_log_finish($pdo, $q, $did, $p);
}
if ($p['running']) {
    http_response_code(204);
    exit;
}
$st = $pdo->prepare('SELECT * FROM mcp_agent_dispatches WHERE dispatch_id = :d');
$st->execute(['d' => $did]);
$d = $st->fetch(PDO::FETCH_ASSOC);
$t = ask_turn_data($pdo, $q, ['in_window' => request_string('window') !== '']);
if (wants_json()) {
    respond_screen(['dispatch_id' => $did, 'status' => $d['status'], 'reply' => $t['expert']['reply'] ?? null, 'detail' => $d['detail']]);
}
echo view('ask/partials/expert.php', ['qid' => $qid, 'dispatch' => $d, 'expert' => $t['expert'], 'may' => $t['may']['expert'], 'expert_named' => $t['expert_named'], 'in_window' => $t['in_window']]);
