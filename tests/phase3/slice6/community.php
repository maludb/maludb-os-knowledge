<?php
/** Slice 6 proof — communities (graph-explorer.md "Proof", Communities). */
require __DIR__ . '/lib.php';

echo "4. Communities\n";
$w = graph_world();
$p = $w['pumps'];
$v = $w['valves'];
g_set($w, g_communities($w));
g_cache_clear($p);
g_cache_clear($v);
$since = last_activity_id();
[$c, $g, $raw] = gscreen(PRIYA, "/bases/$p/graph");
ok($c === 200 && array_column($g['communities'], 'label') === ['Pump and filter', 'Legacy'] && array_column($g['communities'], 'size') === [2, 1] && $g['connected'] === [], 'the proof wrote two communities for kb:Pumps → cards with labels and sizes, and no connected-subjects stand-in');
$html = page(as_member(PRIYA), "/bases/$p/graph")['body'];
ok(!str_contains($html, 'id="graph-communities-empty"') && str_contains($html, 'id="graph-community-11"') && str_contains($html, '/bases/' . $p . '/community?community=11') && !str_contains($html, 'Valves group'), 'the cards link to community-view; Valves\' community is not among them');
[$c, $d, $raw] = gscreen(PRIYA, "/bases/$p/community?community=11");
ok($c === 200 && $d['community'] === ['community_id' => 11, 'label' => 'Pump and filter', 'size' => 2], 'community-view: the label and the size');
ok(array_column($d['members'], 'name') === ['Zorblat Pump', 'Merrow Filter', 'Zorblat Pump'] && array_column($d['members'], 'kind') === ['subject', 'subject', 'section'] && $d['members'][2]['id'] === $w['zorblat'],
    'the members, confined: Zorblat Pump, Merrow Filter and document 501 as its section — Quillon Valve and document 601 are not shown (' . implode(', ', array_column($d['members'], 'name')) . ')');
ok(!str_contains(g_flat($d), 'Quillon') && !str_contains(g_flat($d), '601') && $d['members_more'] === false, 'nothing of Valves in the JSON');
ok($d['ask_url'] === '/ask?base=' . $p . '&q=What%20does%20this%20knowledge%20base%20say%20about%20Pump%20and%20filter%3F', 'Ask about this: ' . $d['ask_url']);
$cp = page(as_member(PRIYA), "/bases/$p/community?community=11")['body'];
foreach (['community-head', 'community-label', 'community-size', 'community-ask', 'community-members', 'community-member-1', 'community-member-3'] as $id) {
    if (!str_contains($cp, 'id="' . $id . '"')) { ok(false, "the page has #$id"); }
}
ok(str_contains($cp, 'href="/bases/' . $p . '/subject?name=Zorblat%20Pump&amp;back=') && str_contains($cp, 'href="/sections/' . $w['zorblat']), 'members link to subject-view and section-view');
$m2 = '';
foreach ([[PRIYA, $p, 21], [PRIYA, $p, 999], [PRIYA, $p, 0]] as [$who, $b, $cid]) {
    $r = req('GET', "/bases/$b/community?community=$cid", ['jar' => as_member($who), 'headers' => JSONH]);
    $m2 .= $r['code'] . ' ' . (json_decode($r['body'], true)['error']['message'] ?? '') . ' / ';
}
ok($m2 === '404 Community not found. / 404 Community not found. / 404 Community not found. / ', 'a community of Valves asked from Pumps, a made-up id and 0 → 404 "Community not found."');
$r = req('GET', "/bases/$p/community", ['jar' => as_member(PRIYA), 'headers' => JSONH]);
ok($r['code'] === 404, 'no community named → 404');
[$c, $d12] = gscreen(PRIYA, "/bases/$p/community?community=12");
ok($c === 200 && array_column($d12['members'], 'name') === ['Zorblat Pumps'], 'the second community holds Zorblat Pumps');
[$c, $dv] = gscreen(MARCO, "/bases/$v/community?community=21");
ok($c === 200 && array_column($dv['members'], 'name') === ['Quillon Valve', 'Quillon Valve'] && array_column($dv['members'], 'kind') === ['subject', 'section'], 'Marco in Valves: its own community lists Quillon Valve and its section');
[$c] = gscreen(MARCO, "/bases/$v/community?community=11");
ok($c === 404, '…and Pumps\' community asked from Valves is a 404');
$sv = array_values(array_filter(activity('screen.view', $since), static fn ($r) => (json_decode($r['after'], true)['community_id'] ?? null) === 11));
ok(count($sv) >= 1 && (int) $sv[0]['base_id'] === $p && !str_contains($sv[0]['after'], 'Pump and filter'), 'screen.view logged with community_id and no label');
g_set($w);
g_cache_clear($p);
finish();
