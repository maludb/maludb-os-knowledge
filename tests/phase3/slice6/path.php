<?php
/** Slice 6 proof — the path (graph-explorer.md "Proof", Path). */
require __DIR__ . '/lib.php';

echo "5. Path\n";
$w = graph_world();
$p = $w['pumps'];
g_set($w);
g_cache_clear($p);
$u = static fn (string $a, string $b): string => "/bases/$p/path?from=" . rawurlencode($a) . '&to=' . rawurlencode($b);
$since = last_activity_id();
[$c, $d, $raw] = gscreen(PRIYA, $u('Zorblat Pump', 'Merrow Filter'));
ok($c === 200 && $d['found'] === true && $d['withheld'] === false && array_column($d['steps'], 'kind') === ['subject', 'section', 'subject'] && array_column($d['steps'], 'name') === ['Zorblat Pump', 'Merrow Filter', 'Merrow Filter'],
    'Zorblat Pump → Merrow Filter: the chain subject, section, subject (' . implode(' > ', array_column($d['steps'], 'name')) . ')');
ok($d['steps'][1]['id'] === $w['merrow'] && $d['steps'][1]['section']['section_id'] === $w['merrow'] && $d['steps'][1]['url'] === '/sections/' . $w['merrow'], '…through section 502 (the Merrow Filter section)');
ok(($d['steps'][0]['verb'] ?? null) === 'used_with' && ($d['steps'][1]['verb'] ?? null) === 'described_in' && !isset($d['steps'][2]['verb']), 'the links between the nodes carry the verbs the engine knows');
$calls = array_values(array_filter(kb_log('/v1/graph/path'), static fn ($r) => true));
ok(count($calls) >= 1 && (int) end($calls)['query']['max_depth'] === 6, 'the engine was asked for a path of at most 6 steps');
$html = page(as_member(PRIYA), $u('Zorblat Pump', 'Merrow Filter'))['body'];
foreach (['path-form', 'path-from', 'path-to', 'path-result', 'path-chain', 'path-step-1', 'path-step-2', 'path-step-3', 'path-link-1', 'path-link-2'] as $id) {
    if (!str_contains($html, 'id="' . $id . '"')) { ok(false, "the page has #$id"); }
}
ok(str_contains($html, 'href="/sections/' . $w['merrow'] . '?back=') && str_contains($html, 'href="/bases/' . $p . '/subject?name=Merrow%20Filter&amp;back='), 'each subject links to subject-view and each section to section-view');
preg_match('/<datalist id="path-suggestions">(.*?)<\/datalist>/s', $html, $m);
ok(str_contains($m[1] ?? '', 'Zorblat Pump') && str_contains($m[1], 'Merrow Filter') && !str_contains($m[1], 'Quillon'), 'the fields carry suggestions: this base\'s most connected subjects, never Valves\'');

// Quillon Valve: not Pumps' subject → the same words as a path that runs outside; no node of any path in the response
kb_clear();
[$c, $q, $raw] = gscreen(PRIYA, $u('Zorblat Pump', 'Quillon Valve'));
$hq = page(as_member(PRIYA), $u('Zorblat Pump', 'Quillon Valve'))['body'];
$flat = g_flat($q);
ok($c === 200 && $q['found'] === false && $q['withheld'] === true && $q['steps'] === [] && str_contains($hq, 'There is no path between these inside this base.') && str_contains($hq, 'id="path-withheld"'),
    'Zorblat Pump → Quillon Valve → "There is no path between these inside this base."');
ok(!str_contains($flat, 'Document 601') && !str_contains($flat, '601') && !str_contains($flat, 'closes at twelve') && !str_contains($hq, 'Document 601') && !str_contains($hq, 'id="path-chain"') && !str_contains($hq, 'closes at twelve'), 'no node of the path is in the response');
[$c, $un] = gscreen(PRIYA, $u('Zorblat Pump', 'No Such Subject'));
ok($c === 200 && $un['withheld'] === true && $un['steps'] === [], 'a name the engine does not know → the same words (nothing tells it from another base\'s subject)');

// both ends are Pumps\', but the engine's paths run through Valves
g_set($w, ['paths' => [['from' => 'subject:1', 'to' => 'subject:2', 'depth' => 2, 'path' => ['subject:1', 'document:601', 'subject:2']]]]);
g_cache_clear($p);
[$c, $o] = gscreen(PRIYA, $u('Zorblat Pump', 'Merrow Filter'));
ok($c === 200 && $o['found'] === false && $o['withheld'] === true && $o['steps'] === [], 'both ends are Pumps\' but the only path runs through Valves\' document → withheld');
g_set($w, ['paths' => [['from' => 'subject:1', 'to' => 'subject:2', 'depth' => 2, 'path' => ['subject:1', 'subject:3', 'subject:2']]]]);
g_cache_clear($p);
[, $o] = gscreen(PRIYA, $u('Zorblat Pump', 'Merrow Filter'));
ok($o['withheld'] === true && $o['steps'] === [] && !str_contains(g_flat($o), 'Quillon'), 'a path through a subject Pumps does not hold (Quillon Valve touches only Valves\') → withheld, the subject not named');
g_set($w, ['paths' => [['from' => 'subject:1', 'to' => 'subject:2', 'depth' => 2, 'path' => ['subject:1', 'document:601', 'subject:2']],
                       ['from' => 'subject:1', 'to' => 'subject:2', 'depth' => 3, 'path' => ['subject:1', 'document:501', 'subject:1', 'document:502', 'subject:2']]]]);
g_cache_clear($p);
[, $o] = gscreen(PRIYA, $u('Zorblat Pump', 'Merrow Filter'));
ok($o['found'] === true && $o['withheld'] === false && !str_contains(g_flat($o), '601') && count($o['steps']) === 5, 'the shortest path wholly inside the base wins over a shorter one that is not');
g_set($w, ['paths' => []]);
g_cache_clear($p);
[, $o] = gscreen(PRIYA, $u('Zorblat Pump', 'Merrow Filter'));
ok($o['found'] === false && $o['withheld'] === false && $o['steps'] === [] && str_contains(page(as_member(PRIYA), $u('Zorblat Pump', 'Merrow Filter'))['body'], 'There is no path between these inside this base.'), 'no path at all → the same sentence, not withheld');
g_set($w);
g_cache_clear($p);
[, $o] = gscreen(PRIYA, $u('Zorblat Pump', 'Zorblat Pump'));
ok($o['found'] === true && count($o['steps']) === 1, 'a subject to itself is a chain of one');
[$c, $e] = gscreen(PRIYA, "/bases/$p/path");
ok($c === 200 && $e['found'] === false && $e['asked'] === false && $e['steps'] === [] && page(as_member(PRIYA), "/bases/$p/path")['code'] === 200, 'no names → the form only');
[$c] = gscreen(DEE, "/bases/" . $w['valves'] . '/path?from=a&to=b');
ok($c === 404, 'a base the person cannot read → 404');

$sv = activity('screen.view', $since);
$byFound = [];
foreach ($sv as $r) { $a = json_decode($r['after'], true); if (array_key_exists('withheld', $a ?? [])) { $byFound[] = [$a['found'], $a['withheld']]; } }
ok(in_array([true, false], $byFound, true) && in_array([false, true], $byFound, true) && !str_contains(implode('', array_column($sv, 'after')), 'Quillon'), 'screen.view logged found / withheld, never a name');
finish();
