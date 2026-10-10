<?php
/** Slice 5 proof — JSON mode and agents (ask.md "Proof", JSON mode). */
require __DIR__ . '/lib.php';

echo "12. JSON mode\n";
$w = ask_world();
$hb = $w['handbook'];
kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [carry_script($w)]]);
$shape = static fn (array $b): bool => ($b['ok'] ?? false) === true && isset($b['did'], $b['record_id'], $b['location']);
$priya = ['X-Action-Token: ' . person_token(PRIYA)];
$cara = ['X-Action-Token: ' . person_token(CARA)];
[$c, $b] = act_token('/ask/send.php', ['base' => $hb, 'question' => 'How does carry-over work? (token)'], $priya);
$qid = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $shape($b) && $b['schema'] === 'os.knowledge-answer/1' && $b['location'] === '/questions/' . $qid && $b['question_id'] === $qid, 'question_ask under an action token: the document with {ok, did, record_id, location}');
[$c, $b] = act_token('/questions/feedback.php', ['question' => $qid, 'rating' => 'up'], $priya);
ok($c === 200 && $shape($b), 'question_feedback answers the shape');
[$c, $b] = act_token('/questions/pin.php', ['question' => $qid], $cara);
$pid = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $shape($b) && $b['location'] === '/pinned#pin-' . $pid, 'answer_pin answers the shape');
[$c, $b] = act_token('/pinned/review.php', ['pinned_answer' => $pid], $cara);
ok($c === 200 && $shape($b), 'answer_pin_review answers the shape');
[$c, $b] = act_token('/ask/send.php', ['base' => $hb, 'question' => ''], $priya);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($b['error']['fields']['question']), '422 {error: {code: invalid, fields}}');
$agent = as_agent(run_token(EXPERT, 7501));
[$c, $b] = act_token('/pinned/unpin.php', ['pinned_answer' => $pid], $agent);
ok($c === 403, 'answer_unpin refused to an agent token (people only): "' . ($b['error']['message'] ?? '') . '"');
$eo = evidence_only_question($w, PRIYA, 'carry-over json expert');
[$c, $b] = act_token('/questions/expert.php', ['question' => $eo], $agent);
ok($c === 403, 'question_expert refused to an agent token (people only)');
[$c, $b] = act_token('/ask/send.php', ['base' => $hb, 'question' => 'How does carry-over work? (agent)'], $agent);
ok($c === 200 && question_row((int) $b['question_id'])['door'] === 'agent' && (int) question_row((int) $b['question_id'])['asked_by_member_id'] === EXPERT, 'the expert asks through the window\'s handler: door agent');
$reg = json_decode(file_get_contents(APP_ROOT . '/mcp/action_registry.json'), true);
$acts = ['question_ask', 'question_feedback', 'answer_pin', 'answer_unpin', 'answer_pin_review', 'question_expert'];
$screens = ['ask', 'conversations', 'question-view', 'questions', 'gaps', 'pinned'];
$ba = count(array_filter($acts, static fn ($x) => !empty($reg['actions'][$x]['built'])));
$bs = count(array_filter($screens, static fn ($x) => !empty($reg['screens'][$x]['built'])));
ok($ba === 6 && $bs === 6, "the registry reads 6 screens and 6 actions of this slice built ($bs, $ba)");
ok(empty($reg['actions']['question_ask']['approval']) && empty($reg['actions']['answer_pin']['approval']) && empty($reg['actions']['question_feedback']['approval']), 'asking, rating and pinning are free for an agent');
finish();
