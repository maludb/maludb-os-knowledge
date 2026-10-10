<?php
/** Slice 5 proof — who sees what (ask.md "Proof", Who sees). */
require __DIR__ . '/lib.php';

echo "11. Who sees\n";
$w = ask_world();
$hb = $w['handbook'];
kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [carry_script($w), ['match' => 'plan', 'answer' => m1('answered',
    [ev(1, 601, 'The private plan for next year.', ['base' => $w['private'], 'section' => $w['private_section'], 'page' => 1])], [cite(1, 'The private plan', 601)], 'The plan [1].')]]]);
[, $pq] = ask_as(PRIYA, ['base' => $hb, 'question' => 'How does carry-over work? (who)']);
[, $dq] = ask_as(DEE, ['base' => $hb, 'question' => 'How does carry-over work? (Dee)']);
[, $cq] = ask_as(CARA, ['base' => $w['private'], 'question' => 'What is the plan?']);
$p = (int) $pq['question_id']; $d = (int) $dq['question_id']; $c = (int) $cq['question_id'];
ok($cq['status'] === 'answered', 'Cara asks her private base');
ok(page(as_member(PRIYA), '/questions/' . $p)['code'] === 200 && page(as_member(PRIYA), '/questions/' . $d)['code'] === 404, 'Priya sees her question; Dee\'s → 404');
ok(page(as_member(CARA), '/questions/' . $p)['code'] === 200 && page(as_member(CARA), '/questions/' . $d)['code'] === 200 && page(as_member(CARA), '/questions/' . $c)['code'] === 200, 'Cara sees every Handbook question and her private base\'s');
ok(page(as_member(DEE), '/questions/' . $c)['code'] === 404 && page(as_member(DEE), '/ask?base=' . $w['private'])['code'] === 404, 'Dee sees no question of the private base and cannot pick it');
[, $dd] = screen(as_member(DEE), '/ask');
ok(!in_array($w['private'], array_column($dd['bases'], 'base_id'), true) && in_array($hb, array_column($dd['bases'], 'base_id'), true), 'Dee\'s picker: the Handbook, never the private base');
[, $gd] = screen(as_member(GUS), '/ask');
ok(array_column($gd['bases'], 'base_id') === [$hb], 'Gus (a guest) sees the Handbook only');
ok(page(as_member(OWNER), '/questions/' . $p)['code'] === 200 && page(as_member(OWNER), '/questions/' . $c)['code'] === 200, 'the admin sees all');
$url = parse_url($cq['citations'][0]['url']);
ok(page(as_member(DEE), $url['path'] . '?' . $url['query'])['code'] === 404, 'the citation link of a question Dee cannot see opens nothing (the section is not hers: 404)');
$hurl = parse_url($pq['citations'][0]['url']);
$hp = page(as_member(DEE), $hurl['path'] . '?' . $hurl['query'])['body'];
ok(str_contains($hp, '<mark id="quote">'), 'a Handbook citation opens the section with the quote marked (the section is every Reader\'s)');
finish();
