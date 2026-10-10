<?php
/** Slice 5 proof — the gaps and the base's log as the curators see them (ask.md "Proof", Feedback and gaps — the gaps half). */
require __DIR__ . '/lib.php';

echo "8. Gaps\n";
$w = ask_world();
$hb = $w['handbook'];
kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [['match' => 'kangaroo', 'answer' => m1('no_evidence', [], [], null, 0, 0)],
    ['match' => 'wombat', 'answer' => m1('unverified', [ev(1, 501, 'Up to five days.', ['base' => $hb, 'section' => $w['carry']])], [], 'draft')], carry_script($w)]]);
[, $g1] = ask_as(PRIYA, ['base' => $hb, 'question' => 'What is the kangaroo quota?']);
[, $g2] = ask_as(DEE, ['base' => $hb, 'question' => 'Is the kangaroo quota taxed?']);
[, $g3] = ask_as(PRIYA, ['base' => $hb, 'question' => 'Do wombats get leave?']);
[, $ok] = ask_as(PRIYA, ['base' => $hb, 'question' => 'How does carry-over work? (gaps)']);
act(as_member(DEE), '/questions/feedback.php', ['question' => (int) $ok['question_id'], 'rating' => 'up']);
act(as_member(PRIYA), '/questions/feedback.php', ['question' => (int) $ok['question_id'], 'rating' => 'down', 'comment' => 'Too short for me']);
$cara = as_member(CARA);
[$c, $d] = screen($cara, '/bases/' . $hb . '/gaps');
$clusters = array_column($d['gaps'] ?? [], null, 'cluster_key');
ok($c === 200 && isset($clusters['kangaroo']) && $clusters['kangaroo']['question_count'] === 2 && in_array((int) $g1['question_id'], $clusters['kangaroo']['question_ids'], true), 'Cara\'s gaps: the two kangaroo questions clustered ("kangaroo", 2)');
ok(in_array((int) $g3['question_id'], array_merge(...array_map(static fn ($g) => $g['question_ids'], $d['gaps'])), true), 'the unverified question is a gap too');
ok(count($d['thumbs_down']) >= 1 && in_array('Too short for me', array_column($d['thumbs_down'], 'comment'), true), 'the thumbs-down list with the comment');
$h = page($cara, '/bases/' . $hb . '/gaps')['body'];
ok(str_contains($h, 'id="gap-kangaroo"') && str_contains($h, 'id="gap-add-kangaroo"') && str_contains($h, 'id="gap-ask-kangaroo"') && str_contains($h, 'Too short for me'), 'the page: a card per cluster with Add a source and Ask again; the comment below');
[$c, $d] = screen($cara, '/bases/' . $hb . '/gaps?since=7');
ok($c === 200 && $d['since_days'] === 7, 'the since filter (7 days)');
pdo()->exec('UPDATE questions SET created_at = created_at - interval \'20 days\' WHERE id = ' . (int) $g1['question_id']);
[, $d7] = screen($cara, '/bases/' . $hb . '/gaps?since=7');
$ids7 = array_merge([], ...array_map(static fn ($g) => $g['question_ids'], $d7['gaps']));
ok(!in_array((int) $g1['question_id'], $ids7, true) && in_array((int) $g2['question_id'], $ids7, true), 'a question asked 20 days ago leaves the 7-day view; the recent one stays');
$p = page(as_member(PRIYA), '/bases/' . $hb . '/gaps');
ok($p['code'] === 403 && str_contains($p['body'], 'curators'), 'Priya → 403 in words');

echo "   The base's log\n";
[$c, $d] = screen($cara, '/bases/' . $hb . '/questions');
ok($c === 200 && count($d['questions']) >= 4 && in_array((int) $g2['question_id'], array_column($d['questions'], 'question_id'), true), 'Cara\'s log lists every question of the base, Dee\'s too');
[, $f] = screen($cara, '/bases/' . $hb . '/questions?status=no_evidence');
ok($f['questions'] !== [] && array_unique(array_column($f['questions'], 'status')) === ['no_evidence'], 'the status filter');
$h = page($cara, '/bases/' . $hb . '/questions')['body'];
ok(str_contains($h, 'id="questions-table"') && str_contains($h, 'id="question-row-' . (int) $g2['question_id'] . '"') && str_contains($h, 'id="question-card-' . (int) $g2['question_id'] . '"'), 'a table (1280) and cards (375)');
ok(page(as_member(PRIYA), '/bases/' . $hb . '/questions')['code'] === 403, 'Priya → 403');
finish();
