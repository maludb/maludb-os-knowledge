<?php
/** Slice 5 proof — Ask the expert (ask.md "Proof", The expert). */
require __DIR__ . '/lib.php';

echo "6. Ask the expert\n";
$w = ask_world();
$priya = as_member(PRIYA);
$long = 'The expert says: up to five days of leave carry over; the rest lapses on 31 March. ' . str_repeat('More detail. ', 30) . SENTINEL;
chat_state(['chat' => ['run_id' => 5001, 'request_id' => 'req-5001', 'status' => 'succeeded', 'finished' => true, 'reply' => $long, 'actions' => [], 'cost' => 0.0123, 'currency' => 'USD']]);
$q1 = evidence_only_question($w);
$h = page($priya, '/ask?base=' . $w['handbook'] . '&c=' . rawurlencode((string) question_row($q1)['conversation_id']))['body'];
ok(str_contains($h, 'id="ask-expert-' . $q1 . '"') && !str_contains($h, 'id="pin-btn-' . $q1 . '"'), 'an evidence_only turn offers Ask the expert and no Pin');
$since = last_activity_id();
[$c, $b] = act($priya, '/questions/expert.php', ['question' => $q1]);
$d = dispatch_of($q1)[0] ?? [];
$chats = chat_log();
$chat = end($chats);
ok($c === 200 && $d['status'] === 'answered' && (int) $d['run_id'] === 5001 && mb_strlen((string) $d['reply_excerpt']) === 200 && (int) $d['agent_member_id'] === EXPERT, '200 finished → the dispatch answered, run 5001, a 200-character excerpt, the expert named');
ok($chat['agent'] === (string) EXPERT && $chat['acting'] === (string) PRIYA && str_contains((string) $chat['utterance'], 'base id ' . $w['handbook']) && str_contains((string) $chat['utterance'], 'How does carry-over work?')
    && ($chat['context']['entity'] ?? '') === 'question' && (int) ($chat['context']['record_id'] ?? 0) === $q1, 'the kernel was called ?agent=<expert>, X-Acting-Member Priya, the utterance naming the base and the question');
ok(count(activity('agent.dispatch', $since)) === 1 && count(activity('agent.reply', $since)) === 1 && !in_log(SENTINEL) && !in_log('More detail.'), 'agent.dispatch and agent.reply logged; the reply in no log row');
$v = page($priya, '/questions/' . $q1)['body'];
ok(str_contains($v, 'id="expert-reply-' . $q1 . '"') && str_contains($v, 'More detail. More detail.') && str_contains($v, 'not checked against the sources'), 'the reply re-rendered on reload from GET ?run= (the whole reply, not the excerpt)');

echo "   202, then the poll\n";
chat_state(['chat_http' => 202, 'chat' => ['run_id' => 5002, 'status' => 'running', 'finished' => false]]);
$q2 = evidence_only_question($w, PRIYA, 'How many sick days carry-over?');
[$c, $b] = act($priya, '/questions/expert.php', ['question' => $q2]);
$d = dispatch_of($q2)[0];
ok($c === 200 && $d['status'] === 'sent' && (int) $d['run_id'] === 5002, '202 → the dispatch sent, run 5002 kept');
[$c2] = act($priya, '/questions/expert.php', ['question' => $q2]);
ok($c2 === 200 && count(dispatch_of($q2)) === 1, 'a second press while one is sent returns it (one dispatch)');
$h = page($priya, '/ask?base=' . $w['handbook'] . '&c=' . rawurlencode((string) question_row($q2)['conversation_id']))['body'];
ok(str_contains($h, 'hx-get="/questions/' . $q2 . '/expert-status?dispatch=' . (int) $d['id']) && str_contains($h, 'id="expert-thinking-' . $q2 . '"'), 'the turn shows the thinking row, polling every 3 s');
chat_state(['chat_run' => ['run_id' => 5002, 'status' => 'running', 'finished' => false]]);
$p = page($priya, '/questions/' . $q2 . '/expert-status?dispatch=' . (int) $d['id'], ['headers' => ['HX-Request: true']]);
ok($p['code'] === 204, 'the poll answers 204 while the run goes on');
$s1 = last_activity_id();
chat_state(['chat_run' => ['run_id' => 5002, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Ten paid sick days; they do not carry over.']]);
$p = page($priya, '/questions/' . $q2 . '/expert-status?dispatch=' . (int) $d['id'], ['headers' => ['HX-Request: true']]);
ok($p['code'] === 200 && str_contains($p['body'], 'Ten paid sick days; they do not carry over.') && !str_contains($p['body'], 'hx-trigger') && dispatch_of($q2)[0]['status'] === 'answered', 'finished → the reply partial without the trigger; the dispatch answered');
page($priya, '/questions/' . $q2 . '/expert-status?dispatch=' . (int) $d['id'], ['headers' => ['HX-Request: true']]);
ok(count(activity('agent.reply', $s1)) === 1, 'agent.reply logged once');
$dee = page(as_member(DEE), '/questions/' . $q2 . '/expert-status?dispatch=' . (int) $d['id']);
ok($dee['code'] === 404, 'Dee polling Priya\'s dispatch → 404');

echo "   Refusals\n";
$cases = [[403, 'refused', 'This person may not use the expert.'], [409, 'failed', 'The expert is busy — try again in a minute.'], [500, 'failed', 'The kernel is not reachable right now.']];
foreach ($cases as $i => [$http, $want, $words]) {
    chat_state(['chat_status' => $http]);
    $qx = evidence_only_question($w, PRIYA, 'carry-over case ' . $i);
    [$c] = act($priya, '/questions/expert.php', ['question' => $qx]);
    $dx = dispatch_of($qx)[0];
    ok($c === 200 && $dx['status'] === $want && $dx['detail'] === $words, "$http → $want, \"{$dx['detail']}\"");
}
ok(str_contains(page($priya, '/questions/' . $qx)['body'], 'id="ask-expert-' . $qx . '"'), 'after a failure the button stays');
chat_state(['chat' => ['run_id' => 5009, 'status' => 'awaiting_approval', 'finished' => true, 'reply' => '']]);
$qa = evidence_only_question($w, PRIYA, 'carry-over approval');
act($priya, '/questions/expert.php', ['question' => $qa]);
ok(dispatch_of($qa)[0]['status'] === 'awaiting_approval' && str_contains(page($priya, '/questions/' . $qa)['body'], 'waiting for a person\'s approval'), 'awaiting_approval → shown "waiting for a person\'s approval"');
chat_state(['chat_http' => 202, 'chat' => ['run_id' => 5010, 'status' => 'running', 'finished' => false]]);
$ql = evidence_only_question($w, PRIYA, 'carry-over late');
act($priya, '/questions/expert.php', ['question' => $ql]);
$dl = dispatch_of($ql)[0];
pdo()->exec("UPDATE agent_dispatches SET created_at = now() - interval '11 minutes' WHERE id = " . (int) $dl['id']);
page($priya, '/questions/' . $ql . '/expert-status?dispatch=' . (int) $dl['id'], ['headers' => ['HX-Request: true']]);
ok(dispatch_of($ql)[0]['status'] === 'failed' && dispatch_of($ql)[0]['detail'] === 'The expert did not finish.', 'ten minutes with no finish → failed, "The expert did not finish."');

echo "   No expert, or an answer\n";
pdo()->exec('UPDATE kn_settings SET expert_member_id = NULL WHERE id = 1');
$qn = evidence_only_question($w, PRIYA, 'carry-over unnamed');
$h = page($priya, '/questions/' . $qn)['body'];
[$c, $b] = act($priya, '/questions/expert.php', ['question' => $qn]);
ok(!str_contains($h, 'id="ask-expert-' . $qn . '"') && str_contains($h, 'No expert is named yet') && $c === 422 && msg($b) === 'No expert is named yet — an admin names it under Admin › Agents.', 'no expert named: the button hidden, the handler\'s 422');
pdo()->exec('UPDATE kn_settings SET expert_member_id = ' . EXPERT . ' WHERE id = 1');
kb_state(['scripts' => [carry_script($w)]]);
[, $ab] = ask_as(PRIYA, ['base' => $w['handbook'], 'question' => 'How does carry-over work?']);
[$c, $b] = act($priya, '/questions/expert.php', ['question' => (int) $ab['question_id']]);
ok($c === 422, 'an answered question → 422: "' . msg($b) . '"');
finish();
