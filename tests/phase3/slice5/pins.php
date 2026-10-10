<?php
/** Slice 5 proof — pinned answers (ask.md "Proof", Pins). */
require __DIR__ . '/lib.php';

echo "9. Pins\n";
$w = ask_world();
$hb = $w['handbook'];
kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [carry_script($w), ['match' => 'emu', 'answer' => m1('no_evidence', [], [], null, 0, 0)]]]);
[, $a] = ask_as(PRIYA, ['base' => $hb, 'question' => 'How does carry-over work? (pin)']);
$qid = (int) $a['question_id'];
$cara = as_member(CARA);
$h = page($cara, '/questions/' . $qid)['body'];
ok(str_contains($h, 'id="pin-btn-' . $qid . '"'), 'Cara sees Pin on the answered question');
$since = last_activity_id();
[$c, $b] = act($cara, '/questions/pin.php', ['question' => $qid, 'title' => 'SMOKE Carry-over rule']);
$pin = q('SELECT * FROM pinned_answers WHERE question_id = :q', ['q' => $qid])[0] ?? [];
$src = q('SELECT * FROM sources WHERE id = :s', ['s' => (int) ($pin['source_id'] ?? 0)])[0] ?? [];
$text = (string) one('SELECT text FROM source_text WHERE source_id = :s', ['s' => (int) ($pin['source_id'] ?? 0)]);
ok($c === 200 && $pin['status'] === 'active' && $pin['title'] === 'SMOKE Carry-over rule' && ($b['location'] ?? '') === '/pinned#pin-' . (int) $pin['id'], 'Cara pins: the pin, landing on /pinned#pin-{id}');
ok($src['kind'] === 'note' && $src['status'] === 'sectioning' && str_contains($text, 'How does carry-over work? (pin)') && str_contains($text, 'Up to five days carry over') && $pin['note_id'] !== null, 'a note on the base and a note source in sectioning with the question and the answer as its text');
ok($pin['review_until'] === (string) one("SELECT (current_date + interval '6 months')::date"), 'review_until = today + 6 months');
$log = activity('question.pin', $since);
ok(count($log) === 1 && $log[0]['entity_type'] === 'pinned_answer' && json_decode($log[0]['after'], true)['source_id'] === (int) $pin['source_id'], 'question.pin logged (pinned_answer_id, source_id, note_id, review_until)');
[$c, $b] = act($cara, '/questions/pin.php', ['question' => $qid]);
ok($c === 422 && msg($b) === 'This answer is already pinned', 'pinning again → "This answer is already pinned" (the database\'s words)');
[, $ne] = ask_as(PRIYA, ['base' => $hb, 'question' => 'emu leave?']);
[$c, $b] = act($cara, '/questions/pin.php', ['question' => (int) $ne['question_id']]);
ok($c === 422 && msg($b) === 'Only an answered question is pinned', 'a no_evidence question → "Only an answered question is pinned"');
$eo = evidence_only_question($w, PRIYA, 'carry-over pin passages');
[$c, $b] = act($cara, '/questions/pin.php', ['question' => $eo]);
ok($c === 422 && msg($b) === 'Only an answered question is pinned' && !str_contains(page($cara, '/questions/' . $eo)['body'], 'id="pin-btn-' . $eo . '"'), 'an evidence_only one → the same words, and its card shows no Pin');
[, $a2] = ask_as(PRIYA, ['base' => $hb, 'question' => 'How does carry-over work? (pin two)']);
[$c, $b] = act(as_member(PRIYA), '/questions/pin.php', ['question' => (int) $a2['question_id']]);
ok($c === 403, 'Priya (Member) → 403');
pdo()->exec("UPDATE pinned_answers SET review_until = current_date - 1 WHERE id = " . (int) $pin['id']);
[, $pd] = screen(as_member(PRIYA), '/pinned');
$mine = array_column($pd['pinned'], null, 'pinned_answer_id')[(int) $pin['id']] ?? [];
ok(($mine['review_due'] ?? false) === true && str_contains(page($cara, '/pinned?due=1')['body'], 'id="pin-due-' . (int) $pin['id'] . '"'), 'the pinned page shows the review-due chip (a pin back-dated)');
ok(!str_contains(page(as_member(PRIYA), '/pinned')['body'], 'id="pin-review-' . (int) $pin['id'] . '"') && str_contains(page($cara, '/pinned')['body'], 'id="pin-review-' . (int) $pin['id'] . '"'), 'Verify again and Unpin are the curator\'s');
[$c, $b] = act($cara, '/pinned/review.php', ['pinned_answer' => (int) $pin['id']]);
ok($c === 200 && one('SELECT review_until FROM pinned_answers WHERE id = :p', ['p' => (int) $pin['id']]) === (string) one("SELECT (current_date + interval '6 months')::date"), 'Verify again moves review_until');
[, $ad] = screen(as_member(PRIYA), '/ask?base=' . $hb);
$sq = $ad['suggestions'] ?? [];
ok(array_column($sq, 'question')[0] === 'How does carry-over work?' && array_column($sq, 'from')[0] === 'area' && in_array('How does carry-over work? (pin)', array_column($sq, 'question'), true)
    && array_search('pin', array_column($sq, 'from'), true) > array_search('area', array_column($sq, 'from'), true), 'suggested_questions: the area\'s examples, then the pin\'s question');
$since = last_activity_id();
[$c, $b] = act($cara, '/pinned/unpin.php', ['pinned_answer' => (int) $pin['id']]);
ok($c === 200 && one('SELECT status FROM pinned_answers WHERE id = :p', ['p' => (int) $pin['id']]) === 'retired' && one('SELECT status FROM sources WHERE id = :s', ['s' => (int) $pin['source_id']]) === 'forgotten'
    && count(activity('question.unpin', $since)) === 1, 'Unpin → retired, the note source forgotten, question.unpin logged');
finish();
