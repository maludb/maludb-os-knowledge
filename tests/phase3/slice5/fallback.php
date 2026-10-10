<?php
/** Slice 5 proof — the statuses and the fallback (ask.md "Proof", Statuses and the fallback). */
require __DIR__ . '/lib.php';

echo "5. Statuses and the fallback\n";
$w = ask_world();
$hb = $w['handbook'];
kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [['match' => 'nothing', 'answer' => m1('no_evidence', [], [], null, 0, 0)],
    ['match' => 'drafted', 'answer' => m1('unverified', [ev(1, 501, 'Up to five days carry over.', ['base' => $hb, 'section' => $w['carry']])], [], 'A drafted answer ' . SENTINEL)], carry_script($w)]]);
$u0 = usage_answer($hb);
[$c, $b] = ask_as(PRIYA, ['base' => $hb, 'question' => 'Is there nothing on unicorns?']);
ok($b['status'] === 'no_evidence' && $b['message'] === 'This knowledge base holds nothing on this. It has been noted so a curator can add a source.' && usage_answer($hb) == $u0, 'no_evidence: the status and its words, no usage added (no model was called)');
[$c, $b] = ask_as(PRIYA, ['base' => $hb, 'question' => 'A drafted one please']);
ok($b['status'] === 'unverified' && $b['answer_md'] === null && question_row((int) $b['question_id'])['answer_md'] === null && count($b['evidence']) === 1, 'unverified: no answer in the row or the reply though the engine sent one; the evidence is');
$u1 = usage_answer($hb);
[$c, $b] = ask_as(PRIYA, ['base' => $hb, 'question' => 'How does carry-over work?']);
$u2 = usage_answer($hb);
ok((int) $u2['calls'] === (int) $u1['calls'] + 1 && (int) $u2['input_tokens'] === (int) $u1['input_tokens'] + 120 && (int) $u2['output_tokens'] === (int) $u1['output_tokens'] + 40
    && (int) $u2['chars'] === (int) $u1['chars'] + mb_strlen('How does carry-over work?'), 'an answered call is metered once: calls, tokens and the question\'s characters (kind answer)');

$recall = [['chunk_id' => 81, 'document_id' => 501, 'statement_id' => 1, 'source_text' => 'Up to five days of unused leave carry over.', 'similarity' => 0.77, 'subject_name' => 'Leave', 'verb_name' => 'covers'],
           ['chunk_id' => 82, 'document_id' => 502, 'statement_id' => 2, 'source_text' => 'Ten paid sick days a year.', 'similarity' => 0.64, 'subject_name' => 'Sick days', 'verb_name' => 'grants'],
           ['chunk_id' => 83, 'document_id' => 601, 'statement_id' => 3, 'source_text' => 'The private plan.', 'similarity' => 0.6, 'subject_name' => 'Plan', 'verb_name' => 'is']];
kb_state(['answer_route' => false, 'recall' => $recall, 'titles' => ['501' => 'Carry-over', '502' => 'Sick days']]);
[$c, $b] = ask_as(PRIYA, ['base' => $hb, 'question' => 'How does carry-over work?']);
$row = question_row((int) $b['question_id']);
$rows = citations_of((int) $b['question_id']);
ok($b['status'] === 'evidence_only' && $row['error'] === 'answer_unavailable' && $b['error_code'] === 'answer_unavailable' && $row['answer_md'] === null && $b['answer_md'] === null, 'no answer route → evidence_only from the search, error answer_unavailable, no answer');
ok(count($rows) === 2 && array_map('intval', array_column($rows, 'rank')) === [1, 2] && !$rows[0]['verified'] && !$rows[1]['verified'] && (int) $rows[0]['section_id'] === $w['carry'],
    'the passages are its citations: rank = n, not verified (the private one mapped by document is not this base\'s — dropped)');
ok($b['message'] === 'This installation cannot compose answers yet, so here are the passages the base holds on your question.' && kb_count('/v1/memory/recall') >= 1, 'its words; the engine\'s recall was used');
kb_state(['answer_route' => true, 'answer_mode' => 'model_502']);
[$c, $b] = ask_as(PRIYA, ['base' => $hb, 'question' => 'How does carry-over work?']);
ok($b['status'] === 'evidence_only' && question_row((int) $b['question_id'])['error'] === 'model_failed' && $b['message'] === 'The model could not be reached, so here are the passages the base holds.', 'model_502 → evidence_only with error model_failed');
kb_state(['answer_route' => false, 'answer_mode' => 'ok', 'recall' => []]);
[$c, $b] = ask_as(PRIYA, ['base' => $hb, 'question' => 'How does carry-over work?']);
ok($b['status'] === 'no_evidence' && question_row((int) $b['question_id'])['error'] === 'answer_unavailable', 'the fallback with nothing recalled → no_evidence');
kb_state(['answer_route' => true]);
kb_stop();
[$c, $b] = ask_as(PRIYA, ['base' => $hb, 'question' => 'How does carry-over work?']);
$row = question_row((int) ($b['question_id'] ?? 0));
ok($c === 200 && $b['status'] === 'failed' && $row['status'] === 'failed' && $row['error'] === 'engine_unavailable', 'the engine stopped → failed engine_unavailable, the row written');
ok(str_starts_with((string) $b['message'], 'The knowledge base could not be asked just now — '), 'the card\'s sentence: "' . $b['message'] . '"');
kb_start();
kb_state(['answer_mode' => 'slow']);
$t = microtime(true);
[$c, $b] = ask_as(PRIYA, ['base' => $hb, 'question' => 'How does carry-over work?']);
ok($b['status'] === 'failed' && question_row((int) $b['question_id'])['error'] === 'engine_timeout' && microtime(true) - $t < 5, 'slow → failed engine_timeout (MALUDB_KB_API_TIMEOUT=3)');
kb_state(['answer_mode' => 'ok']);
finish();
