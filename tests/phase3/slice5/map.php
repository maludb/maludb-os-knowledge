<?php
/** Slice 5 proof — mapping the engine's evidence and citations to sections (ask.md "Proof", Mapping). */
require __DIR__ . '/lib.php';

echo "4. Mapping\n";
$w = ask_world();
$hb = $w['handbook'];
$PRIV = 'PLATYPUS-PRIVATE-8812';
$forgotten = (int) one("INSERT INTO sections (base_id, source_id, position, heading, heading_path, text, char_from, char_to, maludb_document_id, status)
                        VALUES (:b, :s, 900, 'Old', 'Staff handbook › Old', 'Old words.', 0, 10, '598', 'queued') RETURNING id", ['b' => $hb, 's' => $w['source']]);
pdo()->exec("UPDATE sections SET status = 'forgotten' WHERE id = $forgotten");
$evid = [
    ev(1, 501, 'Up to five days carry over.', ['base' => $hb, 'section' => $w['carry'], 'page' => 3]),
    ev(2, 502, 'Ten paid sick days.', []),                                                // no metadata: by maludb_document_id
    ev(3, 601, 'The private plan ' . $PRIV . '.', ['base' => $w['private'], 'section' => $w['private_section'], 'page' => 1]),
    ev(4, 598, 'A forgotten passage.', []),
    ev(5, 503, 'Pay is monthly.', ['base' => $hb, 'section' => $w['pay'], 'page' => 5]),
];
$cites = [cite(1, 'Up to five days carry over', 501), cite(2, 'Ten paid sick days', 502), cite(3, 'The private plan', 601), cite(9, 'Pay is monthly', 503)];
kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [['match' => 'mapping', 'answer' => m1('answered', $evid, $cites, 'Carry [1], sick [2], private [3], pay [9].')]]]);
[$c, $b] = ask_as(PRIYA, ['base' => $hb, 'question' => 'mapping test']);
$qid = (int) $b['question_id'];
$rows = citations_of($qid);
$stored = json_decode(question_row($qid)['evidence'], true);
ok($c === 200 && $b['status'] === 'answered', 'a reply with five passages and four citations is answered');
ok((int) $stored[0]['section_id'] === $w['carry'] && (int) $stored[1]['section_id'] === $w['sick'], 'a passage with metadata → its section; one without → the section holding its document (502 → Sick days)');
ok(count($stored) === 3 && !str_contains(json_encode($b), $PRIV) && !str_contains((string) question_row($qid)['evidence'], $PRIV) && !in_log($PRIV), 'the passage of another base is dropped: its words in no response, no row, no log');
ok(!in_array($forgotten, array_map('intval', array_column($stored, 'section_id')), true), 'a passage of a forgotten section is dropped');
ok(count($rows) === 3 && array_map('intval', array_column($rows, 'section_id')) === [$w['carry'], $w['sick'], $w['pay']] && array_map('intval', array_column($rows, 'rank')) === [1, 2, 3],
    'the citation on a dropped passage is not written; the one whose n names no passage joins by its document (9 → 503 → Pay); ranks 1..3');
ok($b['dropped'] === 3 && (json_decode(question_row($qid)['usage'], true)['dropped'] ?? null) === 3, 'dropped counts the two passages and the citation (3), in the document and usage.dropped');
ok(str_contains((string) question_row($qid)['answer_md'], 'pay [3]') && !str_contains((string) question_row($qid)['answer_md'], 'private [') && str_contains((string) question_row($qid)['answer_md'], 'Carry [1]'),
    'the answer\'s markers follow the ranks (pay [9] → [3]); the dropped one\'s marker is removed');
$many = [];
for ($i = 1; $i <= 15; $i++) { $many[] = ev($i, 501, str_repeat('Carry-over words. ', 130), ['base' => $hb, 'section' => $w['carry'], 'page' => 3]); }
kb_state(['scripts' => [['match' => 'many', 'answer' => m1('answered', $many, [cite(1, 'Carry-over words.', 501)], 'Carry [1].')]]]);
[, $b] = ask_as(PRIYA, ['base' => $hb, 'question' => 'many passages']);
$st = json_decode(question_row((int) $b['question_id'])['evidence'], true);
ok(count($st) === 12 && max(array_map(static fn ($e) => mb_strlen($e['text']), $st)) === 1500, 'at most 12 passages of at most 1,500 characters are kept');
kb_state(['scripts' => [['match' => 'unmapped', 'answer' => m1('answered', [ev(1, 601, 'Private.', ['base' => $w['private'], 'section' => $w['private_section']])], [cite(1, 'Private.', 601)], 'Private [1]. ' . SENTINEL)]]]);
[$c, $b] = ask_as(PRIYA, ['base' => $hb, 'question' => 'unmapped please']);
$r = question_row((int) $b['question_id']);
ok($b['status'] === 'unverified' && $b['error_code'] === 'citations_unmapped' && $r['answer_md'] === null && $b['answer_md'] === null && citations_of((int) $b['question_id']) === [],
    'an answered reply none of whose citations map → stored unverified with citations_unmapped and no answer');
try {
    pdo()->prepare('INSERT INTO citations (question_id, rank, section_id, quote) VALUES (:q, 99, :s, :t)')->execute(['q' => $qid, 's' => $w['private_section'], 't' => 'forged']);
    $refused = false;
} catch (PDOException $e) { $refused = str_contains($e->getMessage(), "A citation names a section of the question's base"); }
ok($refused, 'the citation trigger refuses a forged row naming the private base\'s section');
finish();
