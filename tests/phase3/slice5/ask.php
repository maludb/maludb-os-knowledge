<?php
/** Slice 5 proof — asking (ask.md "Proof", bullets Ask, Conversations and the rate guard). */
require __DIR__ . '/lib.php';
require_once APP_ROOT . '/app/knowledge/compile.php';

echo "1. Ask\n";
$w = ask_world();
$hb = $w['handbook'];
kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [carry_script($w)]]);
$n0 = questions_count();
[$c, $b] = ask_as(PRIYA, ['base' => $hb, 'question' => 'How does carry-over work?']);
$qid = (int) ($b['question_id'] ?? 0);
$row = question_row($qid);
$cit = citations_of($qid);
ok($c === 200 && ($b['schema'] ?? '') === 'os.knowledge-answer/1' && $b['status'] === 'answered' && $qid > 0 && (int) $b['record_id'] === $qid, 'Priya asks the Handbook: answered, the os.knowledge-answer/1 document');
ok(count($cit) === 2 && (int) $cit[0]['section_id'] === $w['carry'] && (int) $cit[1]['section_id'] === $w['sick'] && (int) $cit[0]['page_no'] === 3 && (int) $cit[1]['page_no'] === 4 && $cit[0]['verified'] && $cit[1]['verified'],
    'two citations to the two sections, pages 3 and 4, verified');
ok((float) $row['confidence'] === 0.81 && $row['model'] === 'fake-answer-model' && $row['answer_md'] !== null && $row['door'] === 'web' && (int) $row['asked_by_member_id'] === PRIYA, 'the confidence, the model, the answer, door web, the asker stored');
$sent = kb_last_answer();
$set = q('SELECT max_evidence, evidence_threshold FROM kn_settings WHERE id = 1')[0];
ok($sent['namespace'] === 'kb:' . $hb && (int) $sent['max_evidence'] === (int) $set['max_evidence'] && abs((float) $sent['min_similarity'] - (float) $set['evidence_threshold']) < 1e-9 && !array_key_exists('project', $sent),
    'the engine was sent namespace kb:N, max_evidence and min_similarity from kn_settings, no project');
ok(($sent['system'] ?? null) === kn_compile_answer_system(pdo(), $hb, null)['system'], 'system equals kn_compile_answer_system($pdo, base, null)[\'system\'] byte for byte');
ok($b['citations'][0]['n'] === 1 && $b['citations'][0]['section']['id'] === $w['carry'] && $b['citations'][0]['section']['page'] === 3 && str_contains($b['citations'][0]['url'], '/sections/' . $w['carry'] . '?quote=Up%20to%20five'),
    'the document\'s citation: n, section {id, heading_path, source, page}, url at the quote');
ok(count($b['evidence']) === 2 && $b['evidence'][0]['section']['id'] === $w['carry'] && $b['dropped'] === 0 && $b['message'] === null, 'its evidence mapped to sections, nothing dropped, no message');
[$c, $b] = ask_as(PRIYA, ['base' => $hb, 'question' => 'How does carry-over work in Leave?', 'area' => $w['leave']]);
$sent = kb_last_answer();
ok($c === 200 && ($sent['project'] ?? null) === 77 && str_contains((string) $sent['system'], 'Leave') && $sent['system'] === kn_compile_answer_system(pdo(), $hb, $w['leave'])['system'] && (int) question_row((int) $b['question_id'])['focus_area_id'] === $w['leave'],
    'with the area: project 77, the area\'s line in system, the row scoped to it');
[$c, $b] = ask_as(PRIYA, ['base' => $hb, 'question' => 'How much paid time off carry over?', 'area' => 'leave']);
ok(str_contains(kb_last_answer()['question'], 'paid time off (PTO)') && question_row((int) $b['question_id'])['question'] === 'How much paid time off carry over?', '"paid time off" reaches the engine as "paid time off (PTO)"; the row keeps her words (the area named by its slug)');
$calls = kb_count('/v1/memory/answer');
$n1 = questions_count();
[$c, $b] = ask_as(PRIYA, ['base' => $hb, 'question' => str_repeat('a', 2001)]);
ok($c === 422 && isset(fields($b)['question']) && questions_count() === $n1 && kb_count('/v1/memory/answer') === $calls, 'a 2,001-character question → 422 fields.question, no row, no engine call: "' . msg($b) . '"');
[$c, $b] = ask_as(PRIYA, ['base' => $hb, 'question' => '   ']);
ok($c === 422 && isset(fields($b)['question']), 'an empty question → 422');
$arch = make_base(CARA, 'SMOKE Archived Ask', ['key' => 'smoke-archived-ask']);
act(as_member(CARA), '/bases/visibility.php', ['base' => $arch, 'visibility' => 'internal']);
act(as_member(CARA), '/bases/archive.php', ['base' => $arch]);
$words = [];
foreach ([$arch, $w['private'], 999999] as $x) {
    [$c, $b] = ask_as(PRIYA, ['base' => $x, 'question' => 'carry?']);
    $words[] = $c . ' ' . msg($b);
}
ok(array_unique($words) === ['404 Knowledge base not found.'] && questions_count() === $n1, 'an archived base, the private base, a made-up id → the same 404 sentence, no row');
$other = (int) one("INSERT INTO focus_areas (base_id, name, slug, status) VALUES (:b, 'Other', 'other', 'active') RETURNING id", ['b' => $w['private']]);
$proposed = (int) one("INSERT INTO focus_areas (base_id, name, slug, status, proposed_by) VALUES (:b, 'Proposed', 'proposed', 'proposed', :m) RETURNING id", ['b' => $hb, 'm' => EXPERT]);
[$c1, $b1] = ask_as(PRIYA, ['base' => $hb, 'question' => 'carry?', 'area' => $other]);
[$c2, $b2] = ask_as(PRIYA, ['base' => $hb, 'question' => 'carry?', 'area' => $proposed]);
ok($c1 === 422 && $c2 === 422 && isset(fields($b1)['focus_area'], fields($b2)['focus_area']) && questions_count() === $n1, 'an area of another base and a proposed area → 422 fields.focus_area: "' . msg($b1) . '"');
$key = (int) one("INSERT INTO mcp_access_tokens (member_id, token_hash, label, scope, base_id, focus_area_id) VALUES (:m, :h, 'SMOKE Leave key', 'api', :b, :a) RETURNING id",
    ['m' => CARA, 'h' => hash('sha256', 'smoke-' . bin2hex(random_bytes(8))), 'b' => $hb, 'a' => $w['leave']]);
$pay = (int) one("INSERT INTO focus_areas (base_id, name, slug, status, maludb_project_id) VALUES (:b, 'Pay', 'pay', 'active', '78') RETURNING id", ['b' => $hb]);
$r = php_kn('kn_ask', ['base_id' => $hb, 'question' => 'carry?', 'focus_area_id' => $pay, 'door' => 'api', 'token_id' => $key], CARA);
ok(!$r['ok'] && $r['http'] === 403 && $r['error']['code'] === 'area_not_allowed', 'a key bound to Leave naming another area → 403 area_not_allowed');
$r = php_kn('kn_ask', ['base_id' => $hb, 'question' => 'How does carry-over work?', 'door' => 'api', 'token_id' => $key], CARA);
ok($r['ok'] && (int) question_row((int) $r['question_id'])['focus_area_id'] === $w['leave'] && (int) question_row((int) $r['question_id'])['token_id'] === $key, 'a key bound to Leave forces Leave (the row names the key and the area)');
[$c, $b] = ask_as(CARA, ['base' => $w['empty'], 'question' => 'Anything here?']);
$er = question_row((int) $b['question_id']);
ok($c === 200 && $b['status'] === 'refused' && $b['error_code'] === 'base_not_built' && $er['status'] === 'refused' && $b['message'] === 'This base has no built documents yet.', 'the empty base → refused base_not_built (row written)');
ok(count(activity('question.refuse')) >= 1, '…logged question.refuse');
$unbuilt = (int) one("INSERT INTO focus_areas (base_id, name, slug, status) VALUES (:b, 'Unbuilt', 'unbuilt', 'active') RETURNING id", ['b' => $hb]);
[$c, $b] = ask_as(PRIYA, ['base' => $hb, 'question' => 'carry?', 'area' => $unbuilt]);
ok($c === 200 && $b['status'] === 'refused' && $b['error_code'] === 'area_not_built', 'an active area with no project id → refused area_not_built');
pdo()->exec("DELETE FROM focus_areas WHERE id IN ($unbuilt, $pay, $proposed)");
$long = 'SMOKE ' . str_repeat('Answer with great care and give every detail of the policy. ', 160);
pdo()->prepare("INSERT INTO guidelines (base_id, kind, title, body, is_active) VALUES (:b, 'answering', 'SMOKE Long', :body, true)")->execute(['b' => $hb, 'body' => mb_substr($long, 0, 9000)]);
[$c, $b] = ask_as(PRIYA, ['base' => $hb, 'question' => 'carry over please']);
$u = json_decode(question_row((int) $b['question_id'])['usage'], true);
ok(!empty($u['system_truncated']) && mb_strlen((string) kb_last_answer()['system']) <= 8000, 'a compiled answer system that was cut is noted in usage.system_truncated (and stays ≤ 8,000)');
pdo()->exec("DELETE FROM guidelines WHERE base_id = $hb AND title = 'SMOKE Long'");

echo "2. Conversations\n";
kb_state(['scripts' => [carry_script($w), ['match' => 'unicorn', 'answer' => m1('no_evidence', [], [], null, 0, 0)]]]);
[, $t1] = ask_as(PRIYA, ['base' => $hb, 'question' => 'How does carry-over work?']);
$conv = $t1['conversation_id'];
[, $t2] = ask_as(PRIYA, ['base' => $hb, 'question' => 'And unicorn leave?', 'c' => $conv]);
[, $t3] = ask_as(PRIYA, ['base' => $hb, 'question' => 'Does carry-over lapse?', 'c' => $conv]);
[, $t4] = ask_as(PRIYA, ['base' => $hb, 'question' => 'Tell me about carry-over once more', 'c' => $conv]);
$h4 = kb_last_answer()['history'];
[, $t5] = ask_as(PRIYA, ['base' => $hb, 'question' => 'Last carry-over question', 'c' => $conv]);
$h = kb_last_answer()['history'];
ok(preg_match('/^c_[0-9a-f]{24}$/', $conv) === 1 && [$t1['turn_no'], $t2['turn_no'], $t3['turn_no'], $t4['turn_no'], $t5['turn_no']] === [1, 2, 3, 4, 5] && $t5['conversation_id'] === $conv, 'one conversation (c_ + 24 hex), turns 1..5');
ok(count($h4) === 6 && $h4[0]['content'] === 'How does carry-over work?' && $h4[3]['content'] === 'The knowledge base held nothing on this.', 'the fourth call\'s history: turns 1–3 (six entries), the no_evidence turn\'s assistant line the fixed sentence');
ok(count($h) === 6 && $h[0] === ['role' => 'user', 'content' => 'And unicorn leave?'] && $h[4]['content'] === 'Tell me about carry-over once more', 'the fifth call\'s: exactly the last three turns (2–4) — turn 1 has left');
[, $d] = ask_as(DEE, ['base' => $hb, 'question' => 'How does carry-over work?', 'c' => $conv]);
ok($d['conversation_id'] !== $conv && preg_match('/^c_[0-9a-f]{24}$/', $d['conversation_id']) === 1 && kb_last_answer()['history'] === [] && $d['turn_no'] === 1, 'Dee naming Priya\'s conversation → a new one; her turns never reach the engine for him');
[, $cc] = ask_as(CARA, ['base' => $hb, 'question' => 'How does carry-over work?']);
[, $x] = ask_as(CARA, ['base' => $w['empty'], 'question' => 'carry?', 'c' => $cc['conversation_id']]);
ok(isset($x['conversation_id']) && $x['conversation_id'] !== $cc['conversation_id'] && $x['turn_no'] === 1, 'another base drops the conversation (a new one, turn 1)');
require_once APP_ROOT . '/app/features/ask/queries.php';
pdo()->exec("SELECT set_config('app.member_id', '" . PRIYA . "', false)");
$mine = my_conversations(pdo(), PRIYA, null);
pdo()->exec("SELECT set_config('app.member_id', '', false)");
ok(in_array($conv, array_column($mine, 'conversation_id'), true) && !in_array($d['conversation_id'], array_column($mine, 'conversation_id'), true), 'my_conversations lists hers only');
$page = page(as_member(PRIYA), '/ask?base=' . $hb . '&c=' . $conv)['body'];
ok(substr_count($page, 'class="kn-turn ') === 5 && str_contains($page, 'id="q-' . $t5['question_id'] . '"'), 'the window shows the conversation\'s five turns');
$dpage = page(as_member(DEE), '/ask?base=' . $hb . '&c=' . $conv)['body'];
ok(substr_count($dpage, 'class="kn-turn ') === 0, 'Dee opening Priya\'s conversation sees no turn of hers');

echo "3. The rate guard\n";
$n = questions_count();
$codes = [];
for ($i = 0; $i < 40; $i++) {
    [$c] = ask_as(PRIYA, ['base' => $hb, 'question' => 'carry ' . $i]);
    $codes[$c] = ($codes[$c] ?? 0) + 1;
}
ok($codes === [200 => 40] && questions_count() === $n + 40 && (int) one('SELECT count(*) FROM key_usage') === 0, 'Priya asks 40 times in a minute through the window: 40 rows, no 429, no key_usage row');
finish();

