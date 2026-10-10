<?php
/** Slice 5 proof — feedback (ask.md "Proof", Feedback and gaps — the feedback half). */
require __DIR__ . '/lib.php';

echo "7. Feedback\n";
$w = ask_world();
$hb = $w['handbook'];
kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [carry_script($w)]]);
[, $b] = ask_as(PRIYA, ['base' => $hb, 'question' => 'How does carry-over work? (feedback)']);
$qid = (int) $b['question_id'];
$c0 = told(CARA, 'feedback');
$out0 = (int) one("SELECT count(*) FROM notification_outbox WHERE dedupe_key LIKE :k", ['k' => 'feedback:' . $qid . ':%']);
$since = last_activity_id();
[$c, $r] = act(as_member(PRIYA), '/questions/feedback.php', ['question' => $qid, 'rating' => 'down', 'comment' => 'It missed the March rule ' . SENTINEL]);
$fb = q('SELECT * FROM question_feedback WHERE question_id = :q', ['q' => $qid]);
ok($c === 200 && count($fb) === 1 && $fb[0]['rating'] === 'down' && str_contains((string) $fb[0]['comment'], 'March rule'), 'Priya thumbs down with a comment: one row');
ok(told(CARA, 'feedback') === $c0 + 1 && str_starts_with((string) one("SELECT title FROM notifications WHERE member_id = :m AND kind = 'feedback' ORDER BY id DESC LIMIT 1", ['m' => CARA]), 'Thumbs down on "How does carry-over work?'), 'Cara (curator) told once in-app');
ok((int) one("SELECT count(*) FROM notification_outbox WHERE dedupe_key LIKE :k", ['k' => 'feedback:' . $qid . ':%']) >= $out0, 'an outbox email per her preferences (the kit queues it)');
$log = activity('question.feedback', $since);
ok(count($log) === 1 && json_decode($log[0]['after'], true) == ['rating' => 'down', 'has_comment' => true, 'changed' => false] && !in_log(SENTINEL) && !in_log('March rule'), 'question.feedback logged (rating, has_comment, changed) — the comment in no row');
act(as_member(PRIYA), '/questions/feedback.php', ['question' => $qid, 'rating' => 'down', 'skip' => '1']);
ok(told(CARA, 'feedback') === $c0 + 1 && count(q('SELECT 1 FROM question_feedback WHERE question_id = :q', ['q' => $qid])) === 1, 'down again changes nothing for Cara (one row, one notice)');
[$c, $r] = act(as_member(PRIYA), '/questions/feedback.php', ['question' => $qid, 'rating' => 'up']);
ok($c === 200 && ($r['changed'] ?? null) === true && told(CARA, 'feedback') === $c0 + 1 && one('SELECT rating FROM question_feedback WHERE question_id = :q', ['q' => $qid]) === 'up', 'up → changed, nobody told');
[$c, $r] = act(as_member(PRIYA), '/questions/feedback.php', ['question' => $qid, 'rating' => 'sideways']);
ok($c === 422 && isset(fields($r)['rating']), 'a rating other than up or down → 422');
[, $gb] = ask_as(GUS, ['base' => $hb, 'question' => 'How does carry-over work? (Gus)']);
[$c] = act(as_member(GUS), '/questions/feedback.php', ['question' => (int) $gb['question_id'], 'rating' => 'up']);
ok($c === 200, 'Gus (a guest) rates his own question');
[$c, $r] = act(as_member(GUS), '/questions/feedback.php', ['question' => $qid, 'rating' => 'down']);
ok($c === 404 && msg($r) === 'Question not found.', 'Gus cannot rate Priya\'s (404)');
$h = act_html(as_member(PRIYA), '/questions/feedback.php', ['question' => $qid, 'rating' => 'down', 'partial' => 'turn'], ['HX-Request: true']);
ok($h['code'] === 200 && str_contains($h['body'], 'id="q-' . $qid . '"') && preg_match('/id="thumbs-down-' . $qid . '" aria-pressed="true"/', $h['body']) === 1, 'from the window: the turn comes back with thumbs down pressed');
finish();
