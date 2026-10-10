<?php
/** Slice 7 proof — a key's feedback (api-keys-k7.md "Proof": the API, feedback stored as the minter's, logged with token_id). */
require __DIR__ . '/lib.php';

echo "9. Feedback through a key\n";
$w = keys_world();
$m = $w['manuals'];
kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [manual_script($w)]]);
$k = mint(CARA, $m, 'Feedback key');
$q = api('POST', '/api/v1/ask', $k['raw'], ['question' => 'What does the warranty cover? (feedback)'])['body'];
$qid = (int) $q['question_id'];
$since = last_activity_id();
$told0 = told(CARA, 'feedback');
$r = api('POST', '/api/v1/questions/' . $qid . '/feedback', $k['raw'], ['rating' => 'down', 'comment' => 'Missing the exclusions ' . KEY_SENTINEL]);
$f = q('SELECT * FROM question_feedback WHERE question_id = :q', ['q' => $qid]);
ok($r['code'] === 200 && $r['body']['question_id'] === $qid && $r['body']['rating'] === 'down' && str_starts_with($r['body']['comment'], 'Missing the exclusions') && !empty($r['body']['updated_at']), 'feedback → 200 {question_id, rating, comment, updated_at}');
ok(count($f) === 1 && (int) $f[0]['member_id'] === CARA && (int) $f[0]['token_id'] === $k['id'], 'stored as the key\'s minter\'s (Cara) with the key named in token_id');
$fl = activity('question.feedback', $since);
$fa = json_decode($fl[0]['after'] ?? '{}', true);
ok(count($fl) === 1 && (int) $fl[0]['token_id'] === $k['id'] && $fl[0]['source'] === 'api' && $fa['rating'] === 'down' && $fa['has_comment'] === true && !in_log(KEY_SENTINEL), 'question.feedback logged with the key\'s token_id, has_comment, and never the comment');
ok(told(CARA, 'feedback') === $told0 + 1, 'a thumbs-down tells the base\'s curator (Cara) once');
api('POST', '/api/v1/questions/' . $qid . '/feedback', $k['raw'], ['rating' => 'down']);
ok(told(CARA, 'feedback') === $told0 + 1, '…and not again for the same question');
// replaces the minter's own
[$c] = act(as_member(CARA), '/questions/feedback.php', ['question' => $qid, 'rating' => 'up']);
$f = q('SELECT * FROM question_feedback WHERE question_id = :q', ['q' => $qid]);
ok($c === 200 && count($f) === 1 && $f[0]['rating'] === 'up' && $f[0]['token_id'] === null, 'a key\'s rating is its minter\'s: Cara\'s own thumbs-up in the window replaces it (one row, the key cleared)');
$r = api('POST', '/api/v1/questions/' . $qid . '/feedback', $k['raw'], ['rating' => 'up']);
$f = q('SELECT * FROM question_feedback WHERE question_id = :q', ['q' => $qid]);
ok($r['code'] === 200 && count($f) === 1 && (int) $f[0]['token_id'] === $k['id'] && $r['body']['comment'] === null, 'and the key\'s rating replaces hers again (one row, the key named)');
// rotated predecessor
[, $rb] = act(as_member(CARA), '/keys/rotate.php', ['api_key' => $k['id']]);
$r = api('POST', '/api/v1/questions/' . $qid . '/feedback', (string) $rb['key'], ['rating' => 'down', 'comment' => 'From the new key']);
ok($r['code'] === 200 && (int) q('SELECT token_id FROM question_feedback WHERE question_id = :q', ['q' => $qid])[0]['token_id'] === (int) $rb['record_id'], 'the rotated key rates its predecessor\'s question (the new key is named)');
// refusals
[, $pq] = ask_as(PRIYA, ['base' => $m, 'question' => 'What does the warranty cover? (Priya\'s)']);
$r = api('POST', '/api/v1/questions/' . $pq['question_id'] . '/feedback', $k['raw'], ['rating' => 'up']);
ok($r['code'] === 404 && count(q('SELECT 1 FROM question_feedback WHERE question_id = :q', ['q' => $pq['question_id']])) === 0, 'a question not asked with this key → 404, nothing stored');
$r = api('POST', '/api/v1/questions/' . $qid . '/feedback', $k['raw'], ['rating' => 'sideways']);
ok($r['code'] === 422 && isset($r['body']['error']['fields']['rating']), 'a rating that is not up or down → 422 fields.rating');
$r = api('POST', '/api/v1/questions/' . $qid . '/feedback', $k['raw'], ['rating' => 'up', 'comment' => str_repeat('c', 2001)]);
ok($r['code'] === 422 && isset($r['body']['error']['fields']['comment']), 'a comment over 2,000 characters → 422 fields.comment');
ok(api('GET', '/api/v1/questions/' . $qid . '/feedback', $k['raw'])['code'] === 405, 'GET on the feedback route → 405');
// a session caller
$jar = as_member(PRIYA);
$tok = csrf_of(req('GET', '/', ['jar' => $jar])['body']);
$sr = req('POST', '/api/v1/questions/' . $pq['question_id'] . '/feedback', ['jar' => $jar, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $tok], 'json' => ['rating' => 'up']]);
$sf = q('SELECT * FROM question_feedback WHERE question_id = :q', ['q' => $pq['question_id']]);
ok($sr['code'] === 200 && count($sf) === 1 && (int) $sf[0]['member_id'] === PRIYA && $sf[0]['token_id'] === null, 'a session caller (Priya) rates her own question through the API: her rating, no key');
$sr = req('POST', '/api/v1/questions/' . $qid . '/feedback', ['jar' => $jar, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $tok], 'json' => ['rating' => 'up']]);
ok($sr['code'] === 404, 'Priya cannot rate Cara\'s key\'s question (404)');
// the curators see which rating is a key's
$fr = screen(as_member(CARA), '/questions/' . $qid)[1]['feedback'] ?? [];
ok(count($fr) === 1, 'the question\'s feedback lists one rating');
pdo()->exec("SELECT set_config('app.member_id', '" . CARA . "', false)");
$vw = q('SELECT key_label, member_name FROM mcp_question_feedback WHERE question_id = :q', ['q' => $qid]);
pdo()->exec("SELECT set_config('app.member_id', '', false)");
ok(count($vw) === 1 && $vw[0]['key_label'] === 'Feedback key' && $vw[0]['member_name'] !== null, 'mcp_question_feedback shows the key\'s label beside the minter, so a curator can tell them apart');
finish();
