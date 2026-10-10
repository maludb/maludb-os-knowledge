<?php
/** Slice 6 proof — the graph page (graph-explorer.md "Proof", Overview). */
require __DIR__ . '/lib.php';

echo "1. Overview\n";
$w = graph_world();
$p = $w['pumps'];
g_set($w);
g_cache_clear($p);
g_cache_clear($w['valves']);
kb_clear();
$since = last_activity_id();
[$c, $d, $raw] = gscreen(PRIYA, "/bases/$p/graph");
$calls = kb_calls();
ok($c === 200 && $d['size']['documents'] === 4 && $d['size']['subjects'] === 4 && $d['size']['statements'] === 3 && $d['size']['communities'] === 0 && $d['size']['last_built_at'] !== null,
    'Priya opens Pumps\' graph: the size is Pumps\' 4 documents from the engine and the cached counts (4 subjects, 3 statements, 0 communities)');
$html = page(as_member(PRIYA), "/bases/$p/graph")['body'];
ok(!str_contains($html . $raw['body'], '98765') && !str_contains($html, '98,765') && !str_contains($html . $raw['body'], '54321') && !str_contains($html, '54,321'), 'the tenant-wide edges and nodes the engine counts appear nowhere (the page, the JSON)');
ok($d['communities'] === [] && str_contains($html, 'id="graph-communities-empty"') && str_contains($html, 'MaluDB has not grouped this base\'s subjects into communities yet; it will when community detection is added. The most connected subjects are shown instead.'),
    'the communities region says plainly there are none yet');
ok(array_column($d['connected'], 'name') === ['Zorblat Pump', 'Merrow Filter', 'Zorblat Pumps', 'ZP-9'], 'the connected subjects: Zorblat Pump, Merrow Filter, Zorblat Pumps, ZP-9 (' . implode(', ', array_column($d['connected'], 'name')) . ')');
$flat = g_flat($d);
ok(!str_contains($flat, 'Quillon') && !str_contains($flat, '601') && !str_contains($flat, 'Valves') && !str_contains($html, 'Quillon'), 'never Quillon Valve, never document 601, never the other base (the page, the JSON)');
ok(array_column($d['god_nodes'], 'kind') === ['subject', 'subject', 'section', 'section', 'subject', 'subject'] && array_column($d['god_nodes'], 'name') === ['Zorblat Pump', 'Merrow Filter', 'Merrow Filter', 'Zorblat Pump', 'Zorblat Pumps', 'ZP-9']
    && $d['god_nodes'][2]['id'] === $w['merrow'] && $d['god_nodes'][3]['id'] === $w['zorblat'], 'most connected: subjects and the base\'s two documents as their sections — Valves\' document and subject excluded');
ok($d['surprises'] === [] && str_contains($html, 'No surprising links yet — they appear once MaluDB has communities to compare.'), 'surprises empty with its sentence');
ok(!array_key_exists('duplicates', $d) && !str_contains($html, 'id="graph-duplicates"') && $d['may']['curate'] === false, 'Priya never sees the duplicates region and its JSON key is absent');
ok(str_contains($html, 'id="base-tab-graph"') && str_contains($html, 'aria-current="page"'), 'the Graph tab is lit on the base (it lit itself by the controller existing)');
$sv = activity('screen.view', $since);
$mine = array_values(array_filter($sv, static fn ($r) => (json_decode($r['after'], true)['base_id'] ?? 0) === $p));
$a0 = json_decode($mine[0]['after'] ?? '{}', true);
ok($a0['base_id'] === $p && $a0['searched'] === false && (int) $mine[0]['base_id'] === $p, 'screen.view logged with base_id and searched false');

[$c, $s, $raw] = gscreen(PRIYA, "/bases/$p/graph?q=Zorblat");
ok($c === 200 && array_column($s['search']['items'], 'name') === ['Zorblat Pump', 'Zorblat Pumps', 'Merrow Filter'] && $s['search']['items'][0]['documents'] === 2 && $s['search']['items'][0]['seed'] === true && $s['search']['items'][2]['seed'] === false,
    'search "Zorblat" → the confined neighbourhood: the seeds first with their document counts, then Merrow Filter (' . implode(', ', array_column($s['search']['items'] ?? [], 'name')) . ')');
ok(!str_contains(g_flat($s), 'Quillon') && !str_contains(g_flat($s), '601'), 'the search shows nothing of Valves');
$since2 = last_activity_id();
$h = req('GET', "/bases/$p/graph?q=Zorblat", ['jar' => as_member(PRIYA)]);
$sv = array_values(array_filter(activity('screen.view', $since2), static fn ($r) => (json_decode($r['after'], true)['searched'] ?? null) === true));
ok($h['code'] === 200 && count($sv) >= 1 && !str_contains($sv[0]['after'] . ($sv[0]['before'] ?? ''), 'Zorblat'), 'screen.view logged with searched true and no search words');
$h = req('GET', "/bases/$p/graph?q=" . rawurlencode('!!'), ['jar' => as_member(PRIYA), 'headers' => JSONH]);
$j = json_decode($h['body'], true)['data'];
ok($h['code'] === 200 && isset($j['errors']['search']) && $j['errors']['search'] === 'invalid_argument', 'a search with no searchable word → the region says so (200), the other regions stand');
$page = page(as_member(PRIYA), "/bases/$p/graph?q=" . rawurlencode('!!'))['body'];
ok(str_contains($page, 'Search with at least one word of two letters or more.') && str_contains($page, 'id="graph-size"'), '…in words, and the page still renders');

[$c, $d, $raw] = gscreen(MARCO, "/bases/$p/graph");
ok($c === 200 && isset($d['duplicates']) && $d['may']['curate'] === true, 'Marco (a curator) gets the duplicates region');
[$c] = gscreen(DEE, "/bases/" . $w['valves'] . "/graph");
$c2 = page(as_member(DEE), "/bases/" . $w['valves'] . "/graph")['code'];
ok($c === 404 && $c2 === 404 && page(as_member(DEE), "/bases/999999/graph")['code'] === 404, 'a base the person may not read (Valves for Bea) and a made-up id → 404');
[$c, $g, $raw] = gscreen(ANN, "/bases/$p/graph");
ok($c === 200 && $g['size']['documents'] === 4, 'Ann (a guest, Reader on Pumps) reads the graph');
$ro = page(as_member(REID), "/bases/$p/graph")['code'];
ok($ro === 200, 'Reid (a Reader) reads the graph');
finish();
