<?php
/** Slice 5 proof — the engine bridge (ask.md "Proof", The bridge). */
require __DIR__ . '/lib.php';

echo "14. The bridge\n";
$w = ask_world();
$hb = $w['handbook'];
kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [carry_script($w)]]);
$msg = static fn (array $more = []): array => $more + ['op' => 'ask', 'caller' => ['kind' => 'member', 'member_id' => PRIYA], 'door' => 'mcp', 'run_id' => null, 'request_id' => null, 'is_eval' => false,
    'args' => ['base' => $hb, 'question' => 'How does carry-over work? (bridge)']];
$internal = getenv('KN_APP') === 'apache' ? 'http://127.0.0.1:8507' : BASE;
[$c, $b] = bridge($msg(), null, null, $internal);
ok($c === 200 && ($b['schema'] ?? '') === 'os.knowledge-answer/1' && $b['status'] === 'answered' && question_row((int) $b['question_id'])['door'] === 'mcp' && (int) question_row((int) $b['question_id'])['asked_by_member_id'] === PRIYA,
    'a signed ask for Priya → the same document as the window, door mcp');
[$c, $b] = bridge($msg(['door' => 'agent', 'run_id' => 812, 'caller' => ['kind' => 'member', 'member_id' => EXPERT]]), null, null, $internal);
ok($c === 200 && question_row((int) $b['question_id'])['door'] === 'agent', 'with a run_id: door agent');
$n = questions_count();
$u = usage_answer($hb);
$since = last_activity_id();
[$c, $b] = bridge($msg(['is_eval' => true]), null, null, $internal);
$ev = activity('question.ask', $since);
ok($c === 200 && $b['question_id'] === null && questions_count() === $n && usage_answer($hb) == $u && count($ev) === 1 && json_decode($ev[0]['after'], true)['eval'] === true && count(q('SELECT 1 FROM activity_log WHERE id > :s', ['s' => $since])) === 1,
    'is_eval: the document with question_id null, no row, no usage, question.ask alone with eval true');
[$c] = bridge($msg(), time() - 120, null, $internal);
ok($c === 401, 'a stale timestamp → 401');
[$c] = bridge($msg(), null, str_repeat('ab', 32), $internal);
ok($c === 401, 'a bad HMAC → 401');
$body = json_encode($msg());
$ts = time();
$actionsSig = hash_hmac('sha256', $ts . '.' . hash('sha256', $body), need('ACTIONS_RELAY_KEY'));
[$c] = bridge($msg(), $ts, $actionsSig, $internal);
ok($c === 401, 'the relay key\'s signature without the kn-bridge: prefix → 401');
$v = php_run('require_once APP_ROOT . "/app/knowledge/bridge.php"; $b = "{}"; echo json_encode([kn_bridge_verify(["REMOTE_ADDR" => "10.0.0.7", "HTTP_X_KN_BRIDGE" => kn_bridge_sign($b)], $b), kn_bridge_verify(["REMOTE_ADDR" => "127.0.0.1", "HTTP_X_KN_BRIDGE" => kn_bridge_sign($b)], $b)]);', PRIYA);
ok(trim($v) === '[["401","unauthorized","The bridge answers a signed loopback call only."],null]', 'a non-loopback REMOTE_ADDR (the server\'s, never a header) → 401; loopback with the signature passes');
[$c, $b] = bridge($msg(['caller' => ['kind' => 'member', 'member_id' => 999999]]), null, null, $internal);
ok($c === 403 && ($b['error']['code'] ?? '') === 'unknown_member', 'an unknown member → 403 unknown_member');
[$c, $b] = bridge($msg(['caller' => ['kind' => 'application', 'application' => 'helpdesk']]), null, null, $internal);
ok($c === 403 && ($b['error']['code'] ?? '') === 'caller_not_allowed', 'an application caller on ask (not share_ask) → refused');
[$c, $b] = bridge($msg(['op' => 'preview']), null, null, $internal);
ok($c === 404 && ($b['error']['code'] ?? '') === 'unknown_op', 'an op no slice has built yet (preview) → 404');
[$c, $b] = bridge($msg(['op' => 'search', 'args' => ['base' => 'smoke-handbook', 'query' => 'carry-over']]), null, null, $internal);
ok($c === 200 && ($b['schema'] ?? '') === 'os.knowledge-evidence/1', 'search (the base by its key) → os.knowledge-evidence/1');
[$c, $b] = bridge($msg(['op' => 'suggest', 'args' => ['base' => $hb]]), null, null, $internal);
ok($c === 200 && ($b['questions'][0]['question'] ?? '') === 'How does carry-over work?', 'suggest → the suggested questions');
[$c, $b] = bridge($msg(['op' => 'ask', 'args' => ['base' => $w['private'], 'question' => 'plan?']]), null, null, $internal);
ok($c === 404 && ($b['error']['message'] ?? '') === 'Knowledge base not found.', 'Priya through the bridge cannot ask the private base (the screens\' gate)');
$since = last_activity_id();
[$c, $b] = bridge($msg(['op' => 'admin_view', 'caller' => ['kind' => 'member', 'member_id' => OWNER], 'args' => ['base' => $w['private'], 'tool' => 'get_base']]), null, null, $internal);
bridge($msg(['op' => 'admin_view', 'caller' => ['kind' => 'member', 'member_id' => OWNER], 'args' => ['base' => $w['private'], 'tool' => 'get_base']]), null, null, $internal);
ok($c === 200 && $b['admin_view'] === true && count(activity('base.admin_view', $since)) === 1, 'admin_view: the admin on a private base → true, base.admin_view logged once an hour');
if (getenv('KN_APP') === 'apache') {
    $pub = req('POST', BASE . '/internal/bridge.php', ['raw' => '{}']);
    ok($pub['code'] === 404, 'the public vhost answers /internal/ with 404');
}
finish();
