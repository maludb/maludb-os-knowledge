<?php
/** Slice 2 proof — pasted text (sources-sections.md "Proof", bullet 3). */
require __DIR__ . '/lib.php';

echo "3. Paste\n";
$bid = world()['base'];
$priya = as_member(PRIYA);
$md = "# Equipment\n\n" . PLANT . ' ' . fx_para(31, 300) . "\n\n## Ladders\n\n" . fx_para(32, 300) . "\n\n## Gloves\n\n" . fx_para(33, 300) . "\n";
[$c, $b] = act($priya, '/sources/add.php', ['base' => $bid, 'kind' => 'text', 'title' => 'SMOKE Equipment notes', 'text' => $md]);
$mdId = (int) ($b['record_id'] ?? 0);
ok($c === 200 && ($b['location'] ?? '') === '/sources/' . $mdId, 'a titled Markdown paste is added (location /sources/{id})');
$plain = fx_para(34, 9500);
[$c, $b] = act($priya, '/sources/add.php', ['base' => $bid, 'kind' => 'text', 'title' => 'SMOKE Plain pages', 'text' => $plain]);
$plainId = (int) ($b['record_id'] ?? 0);
ok($c === 200 && one('SELECT extractor FROM source_text WHERE source_id = :s', ['s' => $plainId]) === 'paste', 'a plain paste is added with its raw text (extractor paste)');
$adds = activity('source.add');
$a = json_decode(end($adds)['after'], true);
ok(($a['characters'] ?? 0) === mb_strlen($plain) && !isset($a['text']), 'source.add carries the characters, never the text');
worker();
$secs = sections_of($mdId);
ok(source_of($mdId)['status'] === 'ready' && array_map(static fn ($x) => $x['heading_path'], $secs) === ['SMOKE Equipment notes › Equipment', 'SMOKE Equipment notes › Equipment › Ladders', 'SMOKE Equipment notes › Equipment › Gloves'],
    'the Markdown paste is cut by its headings: ' . implode(' | ', array_column($secs, 'heading_path')));
ok(source_of($mdId)['title'] === 'SMOKE Equipment notes', 'a typed title is kept');
$ps = sections_of($plainId);
$sizes = array_map(static fn ($x) => mb_strlen($x['text']), $ps);
ok(count($ps) >= 2 && max($sizes) <= 6000 && min($sizes) >= 1000, 'a plain paste is cut in parts of about 4,000 (' . implode(', ', $sizes) . ')');
[$c, $b] = act($priya, '/sources/add.php', ['base' => $bid, 'kind' => 'text', 'title' => 'SMOKE Empty', 'text' => "   \n "]);
ok($c === 422 && isset(fields($b)['text']), 'an empty text → its field');
[$c, $b] = act($priya, '/sources/add.php', ['base' => $bid, 'kind' => 'text', 'title' => '', 'text' => 'Words.']);
ok($c === 422 && isset(fields($b)['title']), 'no title → its field');
[$c, $b] = act($priya, '/sources/add.php', ['base' => $bid, 'kind' => 'text', 'title' => 'SMOKE Huge', 'text' => str_repeat('a', 2097153)]);
ok($c === 422 && str_contains((string) (fields($b)['text'] ?? ''), 'at most 2 MB'), '2 MB + 1 is refused (' . (fields($b)['text'] ?? '') . ')');
ok(!plant_in_log(), 'the planted phrase is in no activity_log row');
finish();
