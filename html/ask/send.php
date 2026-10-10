<?php
declare(strict_types=1);
/**
 * Action `question_ask` (bases.read and the base's reach): kn_ask() at the web door (an agent's run token: the agent door). A gate refusal
 * is the field's 422 or the 404 sentence and writes nothing; every status is a success. HTMX gets the turn (and the conversation id out of
 * band for the next turn), a browser lands on /ask?base=&c=#q-{id}, JSON gets the os.knowledge-answer/1 document with {ok, did, record_id,
 * location}. Logging is kn_ask()'s.
 */
require_once dirname(__DIR__, 2) . '/app/features/ask/handler.php';
kn_handler_begin();
require_right('bases.read');
$pdo = db();
$area = req_val('area');
$r = kn_ask($pdo, ['base_id' => request_integer('base') ?? 0, 'question' => (string) ($_POST['question'] ?? ''), 'focus_area_id' => $area === null || $area === '' ? null : $area,
    'conversation_id' => req_val('c'), 'door' => current_agent_run_id() !== null ? 'agent' : 'web']);
if (!$r['ok']) {
    if ($r['http'] === 422) {
        kn_refuse_fields($r['fields']);
    }
    refuse($r['http'], $r['error']['message']);
}
$qid = $r['question_id'];
$did = $r['status'] === 'answered' ? 'Answered with ' . count($r['citations']) . ' citation' . (count($r['citations']) === 1 ? '' : 's') : (string) $r['message'];
emit_action_status(true, ['did' => $did, 'record_id' => $qid, 'question_id' => $qid, 'status' => $r['status'], 'conversation_id' => $r['conversation_id']]);
$land = '/ask?base=' . (int) $_POST['base'] . ($area ? '&area=' . rawurlencode((string) $area) : '') . '&c=' . rawurlencode($r['conversation_id']) . '#q-' . $qid;
if (wants_json()) {
    unset($r['ok'], $r['http'], $r['fields']);
    respond_saved(['did' => $did, 'record_id' => $qid, 'location' => '/questions/' . $qid, 'refresh' => 'questionChanged'] + $r);
}
if (is_htmx_request()) {
    hx_trigger('questionChanged');
    header('Vary: HX-Request');
    echo ask_turn_html($pdo, (int) $qid, ['in_window' => true]);
    echo '<input type="hidden" name="c" value="' . e($r['conversation_id']) . '" id="ask-c" hx-swap-oob="true">';
    exit;
}
header('Location: ' . $land, true, 303);
exit;
