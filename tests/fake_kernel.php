<?php
/**
 * A FAKE Business OS kernel for the proofs — `php -S 127.0.0.1:8502 tests/fake_kernel.php` — answering the few internal
 * endpoints Knowledge calls, from the JSON state file $FAKE_KERNEL_STATE (the proofs rewrite it between steps):
 *   GET  /api/v1/directory/changes.php  → state.feed (os.directory-changes/1); ?since= answers state.incremental when set, else an empty change
 *   POST /api/v1/runs/facts.php {token} → state.facts[<run id>] (the run token's third part), else {valid: false}
 *   POST /api/v1/agents/chat.php        → state.chat (a canned reply) or the refusal state.chat_status names, echoing the acting member it saw;
 *                                         state.chat_http (202) answers state.chat with that status; GET ?run= answers state.chat_run (else state.chat),
 *                                         or state.chat_run_http; every call is appended to "<state file>.chat"
 *   POST /api/v1/apps/read.php          → K7 (slice 8): state.reads["<provider>.<tool>"] — a result (answered as {result, provider, tool}), {by: {"<first argument value>": result}}
 *                                         for a tool read once per record (page_markdown), or {status, code, message?} for the kernel's refusal; no entry = 403 no_connection;
 *                                         every request ({provider, tool, arguments}) is appended to "<state file>.reads"
 *   GET  /api/v1/ledger/periods.php     → A5, from state.ledger: no ?period= answers os.ledger-periods/1 (state.ledger.periods — the months and their status); ?period=YYYY-MM answers
 *                                         state.ledger.docs[<period>] (an os.ledger-period/1 document, as the kernel sends it), a 422 for a month it has none of; state.ledger.mode = down answers 503;
 *                                         every request is appended to "<state file>.ledger" (the period asked, or "list")
 *   POST /api/v1/notify/sms.php         → K6, as state.sms says (mode = ok | no_sender | not_held | no_verified_phone | opted_out | rate_limited | server_error);
 *                                         every request body is appended to "<state file>.sms" (one JSON line each)
 * Every call needs Authorization: Bearer $OS_APPLICATION_TOKEN (else 401), like the real one. Never a real kernel.
 */
$state = json_decode((string) @file_get_contents((string) getenv('FAKE_KERNEL_STATE')), true) ?: [];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$out = static function (array $body, int $status = 200): never { http_response_code($status); header('Content-Type: application/json'); echo json_encode($body); exit; };
if ($auth !== 'Bearer ' . getenv('OS_APPLICATION_TOKEN')) { $out(['error' => ['code' => 'unauthorized', 'message' => 'A valid application token is required.']], 401); }
$input = json_decode((string) file_get_contents('php://input'), true) ?: [];
switch ($path) {
    case '/api/v1/ledger/periods.php':
        $ledger = $state['ledger'] ?? ['periods' => [], 'docs' => []];
        file_put_contents((string) getenv('FAKE_KERNEL_STATE') . '.ledger', ($_GET['period'] ?? 'list') . "\n", FILE_APPEND);
        if (($ledger['mode'] ?? '') === 'down') { $out(['error' => ['code' => 'unavailable', 'message' => 'The ledger is down.']], 503); }
        if (!isset($_GET['period'])) { $out(['schema' => 'os.ledger-periods/1', 'periods' => $ledger['periods'] ?? []]); }
        if (!isset($ledger['docs'][$_GET['period']])) { $out(['error' => ['code' => 'invalid', 'message' => 'period is written YYYY-MM.']], 422); }
        $out($ledger['docs'][$_GET['period']]);
    case '/api/v1/notify/sms.php':
        $sms = $state['sms'] ?? ['mode' => 'ok'];
        $log = (string) getenv('FAKE_KERNEL_STATE') . '.sms';
        $member = (int) ($input['member_id'] ?? 0);
        $mode = $sms['members'][(string) $member] ?? ($sms['mode'] ?? 'ok');
        if (($input['text'] ?? '') === '' || mb_strlen((string) ($input['text'] ?? '')) > 480) { $mode = 'invalid'; }
        file_put_contents($log, json_encode($input + ['accepted' => $mode === 'ok', 'mode' => $mode]) . "\n", FILE_APPEND);
        $refuse = static fn (string $code, int $status) => $out(['error' => ['code' => $code, 'message' => 'Refused: ' . $code]], $status);
        switch ($mode) {
            case 'ok':           $out(['notification' => ['id' => 9000 + substr_count((string) @file_get_contents($log), "\n"), 'status' => 'queued']], 202);
            case 'no_sender':    $refuse('no_sender', 503);
            case 'server_error': $refuse('server_error', 500);
            default:             $refuse($mode, 422);
        }
    case '/api/v1/directory/changes.php':
        if (isset($_GET['since']) && isset($state['incremental'])) { $out($state['incremental']); }
        if (isset($_GET['since'])) { $out(['schema' => 'os.directory-changes/1', 'since' => $_GET['since'], 'next' => $state['feed']['next'] ?? $_GET['since'], 'full' => false,
                                          'members' => [], 'departments' => [], 'memberships' => [], 'deleted_departments' => [], 'scopes' => [], 'access' => []]); }
        $out($state['feed'] ?? []);
    case '/api/v1/apps/read.php':
        $key = (string) ($input['provider'] ?? '') . '.' . (string) ($input['tool'] ?? '');
        file_put_contents((string) getenv('FAKE_KERNEL_STATE') . '.reads', json_encode(['provider' => $input['provider'] ?? null, 'tool' => $input['tool'] ?? null, 'arguments' => $input['arguments'] ?? null]) . "\n", FILE_APPEND);
        $r = $state['reads'][$key] ?? null;
        if ($r === null) { $out(['error' => ['code' => 'no_connection', 'message' => 'No approved connection to that tool — a super-admin approves it in the operating system.']], 403); }
        if (isset($r['status'], $r['code'])) { $out(['error' => ['code' => $r['code'], 'message' => $r['message'] ?? ('Refused: ' . $r['code'])]], (int) $r['status']); }
        if (isset($r['by']) && is_array($r['by'])) { $first = (string) (array_values((array) ($input['arguments'] ?? []))[0] ?? ''); $r = $r['by'][$first] ?? null; if ($r === null) { $out(['error' => ['code' => 'invalid', 'message' => 'No such record.']], 422); } }
        $out(['result' => $r, 'provider' => $input['provider'] ?? null, 'tool' => $input['tool'] ?? null]);
    case '/api/v1/runs/facts.php':
        $parts = explode('.', (string) ($input['token'] ?? ''));
        $run = $parts[2] ?? '';
        $out($state['facts'][$run] ?? ['valid' => false]);
    case '/api/v1/agents/chat.php':
        file_put_contents((string) getenv('FAKE_KERNEL_STATE') . '.chat', json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'agent' => $_GET['agent'] ?? null, 'run' => $_GET['run'] ?? null,
            'acting' => $_SERVER['HTTP_X_ACTING_MEMBER'] ?? null, 'utterance' => $input['utterance'] ?? null, 'context' => $input['context'] ?? null,
            'wait' => $input['wait'] ?? null, 'conversation_id' => $input['conversation_id'] ?? null, 'screen' => $input['screen'] ?? null]) . "\n", FILE_APPEND);
        if (isset($_GET['run'])) {                         // A6's GET ?run=: state.chat_run (a run still going answers finished false), else state.chat
            if (($state['chat_run_http'] ?? 200) !== 200) { $out(['error' => ['code' => 'gone', 'message' => 'No such run.']], (int) $state['chat_run_http']); }
            $out($state['chat_run'] ?? ($state['chat'] ?? ['run_id' => (int) $_GET['run'], 'status' => 'succeeded', 'finished' => true, 'reply' => 'Hello from the fake expert']));
        }
        if (isset($state['chat_http'])) {                  // 202: the run is still going after `wait`
            $out(($state['chat'] ?? []) + ['seen_acting_member' => $_SERVER['HTTP_X_ACTING_MEMBER'] ?? null], (int) $state['chat_http']);
        }
        if (isset($state['chat_status'])) {
            $messages = [403 => 'This person may not use the expert.', 404 => 'No expert for this application.', 409 => 'The expert is busy with another turn.'];
            $out(['error' => ['code' => 'refused', 'message' => $state['chat_message'] ?? ($messages[(int) $state['chat_status']] ?? 'Refused.')]], (int) $state['chat_status']);
        }
        $out(($state['chat'] ?? ['run_id' => 1, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Hello from the fake expert', 'actions' => []])
            + ['seen_acting_member' => $_SERVER['HTTP_X_ACTING_MEMBER'] ?? null, 'seen_agent' => $_GET['agent'] ?? null, 'seen_utterance' => $input['utterance'] ?? null]);
}
$out(['error' => ['code' => 'not_found', 'message' => 'No such internal endpoint.']], 404);
