<?php
/** Slice 6 proof — what a section / a source taught the graph, on slice 2's screens (graph-explorer.md "Proof", Taught). */
require __DIR__ . '/lib.php';

echo "8. Taught\n";
$w = graph_world();
$p = $w['pumps'];
g_set($w);
g_cache_clear($p);
g_cache_clear($w['valves']);
$jar = as_member(PRIYA);
$h = page($jar, '/sections/' . $w['zorblat'])['body'];
ok(str_contains($h, 'id="taught"') && str_contains($h, 'id="taught-subject-1"') && str_contains($h, 'Zorblat Pump') && str_contains($h, 'id="taught-statements-count"') && str_contains($h, '1 statement') && str_contains($h, 'Zorblat Pump is rated at forty bar'),
    'section-view for the Zorblat Pump section (document 501) shows the subject and the statement it taught');
$subjectLinks = preg_match_all('#href="/bases/' . $p . '/subject\?name=([^"&]+)&amp;back=#', substr($h, (int) strpos($h, 'id="taught"')), $m);
ok($subjectLinks >= 1 && str_contains($h, '/bases/' . $p . '/subject?name=Zorblat%20Pump'), 'each subject links to subject-view');
$h2 = page($jar, '/sections/' . $w['merrow'])['body'];
$tail = substr($h2, (int) strpos($h2, 'id="taught"'));
ok(str_contains($tail, 'Merrow Filter') && str_contains($tail, 'Zorblat Pump') && !str_contains($tail, 'Quillon') && !str_contains($tail, 'closes at twelve'), 'the Merrow Filter section (document 502) taught Zorblat Pump and Merrow Filter — nothing of Valves');
$h3 = page($jar, '/sections/' . $w['top'])['body'];
ok(str_contains($h3, 'id="taught-not-built"') && str_contains($h3, 'Not in the graph yet.'), 'an unbuilt section says "Not in the graph yet."');
[$c, $j] = screen($jar, '/sections/' . $w['zorblat']);
ok($c === 200 && !array_key_exists('taught', $j) && isset($j['section']), 'section-view\'s JSON is unchanged');
kb_clear();
page($jar, '/sections/' . $w['zorblat']);
ok(kb_calls() === 0, 'a second open of the section asks the engine nothing (cached)');
$src = page($jar, '/sources/' . $w['source'])['body'];
$tail = substr($src, (int) strpos($src, 'id="taught"'));
ok(str_contains($src, 'id="taught"') && str_contains($tail, '4 of 5 sections read.') && str_contains($tail, 'Zorblat Pumps') && str_contains($tail, 'ZP-9') && str_contains($tail, 'Merrow Filter') && !str_contains($tail, 'Quillon'),
    'source-view shows the union over its built sections (4 of 5 read): Zorblat Pump, Merrow Filter, Zorblat Pumps, ZP-9');
preg_match('/id="taught-subject-1"[^>]*>.*?<\/a>/s', $src, $first);
ok(str_contains($first[0] ?? '', 'Zorblat Pump') && str_contains($first[0], '· 2'), 'the subject on most sections comes first (Zorblat Pump on two)');
[$c, $sj] = screen($jar, '/sources/' . $w['source']);
ok($c === 200 && !array_key_exists('taught', $sj), 'source-view\'s JSON is unchanged');
$vs = page(as_member(MARCO), '/sources/' . $w['valve_source'])['body'];
ok(str_contains($vs, 'Quillon Valve') && str_contains(substr($vs, (int) strpos($vs, 'id="taught"')), 'Quillon Valve'), 'Marco\'s Valves source shows Quillon Valve');
ok(page(as_member(DEE), '/sources/' . $w['valve_source'])['code'] === 404 && page(as_member(DEE), '/sections/' . $w['quillon'])['code'] === 404, 'and Bea cannot open them at all');
$gone = make_base(MARCO, 'SMOKE Taught Gone', ['key' => 'smoke-taught-gone']);
$gs = mksource($gone, 'SMOKE gone source', MARCO);
$gx = mksection($gs, '777');
pdo()->exec("UPDATE sections SET status = 'forgotten' WHERE id = $gx");
$hf = page(as_member(MARCO), '/sections/' . $gx)['body'];
ok(!str_contains($hf, 'id="taught"') && str_contains($hf, 'id="section-forgotten"'), 'a forgotten section has no such region');
$o = json_decode(php_g('echo json_encode([section_taught($pdo, ' . $w['zorblat'] . '), section_taught($pdo, ' . $w['top'] . '), source_taught($pdo, ' . $w['source'] . ')]);'), true);
ok($o[0]['built'] === true && array_column($o[0]['subjects'], 'name') === ['Zorblat Pump'] && $o[1]['built'] === false && $o[2]['sections_read'] === 4 && $o[2]['sections_total'] === 5, 'section_taught / source_taught answer the shapes the spec names');
g_cache_clear($p);
finish();
