<?php
/** Slice 5 proof — the log (ask.md "Proof", The log): one ask, two rows; never the answer, a quote, a passage, the expanded question or more than 120 characters. */
require __DIR__ . '/lib.php';

echo "10. The log\n";
$w = ask_world();
$hb = $w['handbook'];
$PASS = 'NUMBAT-PASSAGE-2207';
$QUOTE = 'WALLABY-QUOTE-9083';
kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [
    ['match' => 'logcheck', 'answer' => m1('answered', [ev(1, 501, 'Up to five days ' . $QUOTE . ' carry over ' . $PASS . '.', ['base' => $hb, 'section' => $w['carry'], 'page' => 3])],
        [cite(1, 'Up to five days ' . $QUOTE, 501)], 'Five days [1]. ' . SENTINEL)],
    ['match' => 'nothinghere', 'answer' => m1('no_evidence', [], [], null, 0, 0)],
    ['match' => 'draftonly', 'answer' => m1('unverified', [ev(1, 501, 'Up to five days ' . $PASS . '.', ['base' => $hb, 'section' => $w['carry']])], [], 'Draft ' . SENTINEL)]]]);
$outcome = ['answered' => 'question.answer', 'no_evidence' => 'question.no_evidence', 'unverified' => 'question.unverified', 'refused' => 'question.refuse'];
$cases = [['answered', $hb, 'logcheck: how much paid time off carries over?'], ['no_evidence', $hb, 'nothinghere about koalas'], ['unverified', $hb, 'draftonly please'], ['refused', $w['empty'], 'logcheck in the empty base']];
foreach ($cases as [$want, $base, $words]) {
    $since = last_activity_id();
    [, $b] = ask_as($want === 'refused' ? CARA : PRIYA, ['base' => $base, 'question' => $words]);
    $rows = q("SELECT * FROM activity_log WHERE id > :s AND action NOT IN ('member.sign_on', 'screen.view') ORDER BY id", ['s' => $since]);
    $acts = array_column($rows, 'action');
    $ok = $b['status'] === $want && $acts === ['question.ask', $outcome[$want]];
    foreach ($rows as $r) {
        $ok = $ok && (int) $r['base_id'] === $base && (int) $r['entity_id'] === (int) $b['question_id'] && $r['entity_type'] === 'question' && $r['source'] === 'web';
    }
    $after = json_decode($rows[0]['after'] ?? '{}', true);
    ok($ok && $after["status"] === $want && array_key_exists("question_excerpt", $after) && $after["door"] === "web", "$want: question.ask + exactly one {$outcome[$want]}, base_id, entity, source web" . ($ok ? "" : " — got " . $b["status"] . " " . implode(",", $acts)));
}
$long = 'logcheck ' . str_repeat('carry over words ', 7) . str_repeat('more words ', 130) . ' ' . SENTINEL . '-TAIL';
[, $b] = ask_as(PRIYA, ['base' => $hb, 'question' => mb_substr($long, 0, 1500)]);
$a = json_decode(q("SELECT after FROM activity_log WHERE action = 'question.ask' ORDER BY id DESC LIMIT 1")[0]['after'], true);
ok(mb_strlen($a['question_excerpt']) === 120 && str_starts_with(mb_substr($long, 0, 1500), $a['question_excerpt']), 'question_excerpt is the first 120 characters of a 1,500-character question');
ok(!in_log(SENTINEL) && !in_log($QUOTE) && !in_log($PASS) && !in_log('(PTO)') && !in_log('-TAIL'), 'no row carries the answer, a quote, a passage, the expanded question or characters 121+ of a question');
$out = (string) one("SELECT string_agg(after::text, ' ') FROM activity_log WHERE action LIKE 'question.%'");
ok(!str_contains($out, '"answer_md"') && !str_contains($out, '"evidence"'), 'no answer or evidence field in any question row');

echo "   kn_search\n";
$n = questions_count();
kb_state(['recall' => [['chunk_id' => 81, 'document_id' => 501, 'statement_id' => 1, 'source_text' => 'Up to five days ' . $PASS, 'similarity' => 0.7, 'subject_name' => 'Leave', 'verb_name' => 'covers']],
          'titles' => ['501' => 'Carry-over']]);
$since = last_activity_id();
$r = php_kn('kn_search', ['base_id' => $hb, 'query' => 'carry-over search', 'door' => 'web'], PRIYA);
ok($r['ok'] && $r['schema'] === 'os.knowledge-evidence/1' && $r['status'] === 'evidence_only' && count($r['evidence']) === 1 && $r['evidence'][0]['section']['id'] === $w['carry'], 'kn_search answers os.knowledge-evidence/1, the passage mapped to its section');
$r = php_kn('kn_search', ['base_id' => $hb, 'query' => 'carry-over search', 'door' => 'mcp'], PRIYA);
ok(questions_count() === $n && q('SELECT 1 FROM activity_log WHERE id > :s', ['s' => $since]) === [], 'a search alone writes no question; on door web or mcp it logs nothing');
$key = make_key(CARA, $hb, null, 'SMOKE search key');
$r = php_kn('kn_search', ['base_id' => $hb, 'query' => 'carry-over search through the key ' . str_repeat('x', 200), 'door' => 'api', 'token_id' => $key, 'limit' => 5], CARA);
$s = activity('question.search', $since);
$sa = json_decode($s[0]['after'] ?? '{}', true);
ok($r['ok'] && count($s) === 1 && (int) $s[0]['token_id'] === $key && $s[0]['source'] === 'api' && $sa['result_count'] === 1 && mb_strlen($sa['query_excerpt']) === 120 && !in_log($PASS),
    'on door api: question.search with the key, the count and a 120-character excerpt only');
finish();
