<?php
/** Slice 6 proof — confinement: the base is the wall (graph-explorer.md "Proof", Confinement). */
require __DIR__ . '/lib.php';

echo "3. Confinement\n";
$w = graph_world();
$p = $w['pumps'];
g_set($w, g_communities($w) + g_surprises($w));
g_cache_clear($p);
g_cache_clear($w['valves']);
$mine = array_map('intval', array_column(q('SELECT id FROM sections WHERE base_id = :b', ['b' => $p]), 'id'));
$allowed = ['Zorblat Pump', 'Merrow Filter', 'Zorblat Pumps', 'ZP-9', 'Pump and filter', 'Legacy', 'Zorblat Pump is rated at forty bar', 'Zorblat Pump uses the Merrow Filter', 'Model ZP-9', 'Legacy Pumps', 'Pumps'];
$seen = [];
$walk = static function ($v, string $k = '') use (&$walk, &$seen): void {
    if (is_array($v)) { foreach ($v as $kk => $vv) { $walk($vv, (string) $kk); } return; }
    $seen[] = [$k, $v];
};
$screens = ["/bases/$p/graph?q=Zorblat", "/bases/$p/graph", "/bases/$p/subject?name=" . rawurlencode('Zorblat Pump'), "/bases/$p/subject?name=" . rawurlencode('Merrow Filter'), "/bases/$p/subject?name=" . rawurlencode('ZP-9'),
    "/bases/$p/community?community=11", "/bases/$p/community?community=12", "/bases/$p/path?from=" . rawurlencode('Zorblat Pump') . '&to=' . rawurlencode('Merrow Filter')];                                  // (the withheld path is path.php's: the form echoes the name a person typed, which is no node)
$bad = [];
$sectionIds = [];
foreach ([PRIYA, MARCO] as $who) {
    foreach ($screens as $sc) {
        [$code, $data, $raw] = gscreen($who, $sc);
        if ($code !== 200) { $bad[] = "$who $sc → $code"; continue; }
        $flat = g_flat($data);
        foreach (['Quillon', 'Valves', 'closes at twelve', 'fits a Quillon', '601', 'Document 5', 'Cap '] as $needle) {
            if (str_contains($flat . $raw['body'], $needle)) { $bad[] = "$who $sc contains $needle"; }
        }
        $seen = [];
                $walk($data);
        foreach ($seen as [$k, $v]) {
            if ($k === 'section_id') { $sectionIds[] = (int) $v; }
            if (is_string($v) && preg_match('#^/sections/(\d+)#', $v, $m)) { $sectionIds[] = (int) $m[1]; }
            if (in_array($k, ['name', 'text', 'label'], true) && is_string($v) && !in_array($v, $allowed, true) && !str_starts_with($v, 'SMOKE') && !str_starts_with($v, 'Pump manual')) { $bad[] = "$sc: unexpected $k '$v'"; }
        }
    }
}
ok($bad === [], 'every JSON answer of the four screens for Pumps (graph, subject, community, path — Priya and Marco) holds nothing of Valves: ' . implode('; ', array_slice($bad, 0, 4)));
ok($sectionIds !== [] && array_diff($sectionIds, $mine) === [], 'every section id in them is one of Pumps\' (' . count(array_unique($sectionIds)) . ' distinct)');
$html = '';
foreach ($screens as $sc) { $r = page(as_member(PRIYA), $sc); $html .= $r['body']; }
ok(!str_contains($html, 'Quillon') && !str_contains($html, 'Valves') && !str_contains($html, 'closes at twelve') && !str_contains($html, 'Cap '), 'nor do the pages (Priya)');

// the surprises: Pumps\' two stay, the one that ends in Quillon Valve and the one that starts at Valves\' document go
[, $g] = gscreen(PRIYA, "/bases/$p/graph");
$pairs = array_map(static fn ($s) => $s['from']['name'] . ' > ' . $s['to']['name'], $g['surprises']);
ok($pairs === ['Zorblat Pump > Merrow Filter', 'Zorblat Pump > Merrow Filter'], 'surprises: the two links wholly inside Pumps stay (' . implode(' | ', $pairs) . ')');
ok($g['surprises'][1]['from']['kind'] === 'section' && $g['surprises'][1]['from']['id'] === $w['zorblat'] && $g['surprises'][1]['to']['kind'] === 'subject', 'a document end of a surprise is shown as its section');
$gh = page(as_member(PRIYA), "/bases/$p/graph")['body'];
ok(!str_contains($gh, 'No surprising links yet') && str_contains($gh, 'id="graph-surprise-2"'), 'the surprises region lists them');

// the 40-subject cap
$hub = g_hub($w, 45, 2);
g_set($w, ['subjects' => $hub['subjects'], 'neighbors' => $hub['neighbors']]);
g_cache_clear($p);
kb_clear();
[$c, $h, $raw] = gscreen(PRIYA, "/bases/$p/subject?name=" . rawurlencode('Hub Pump'));
$nbCalls = kb_calls('/v1/graph/neighbors');
$names = array_column($h['neighbours'], 'name');
ok($c === 200 && count($names) === 38 && $h['neighbours_more'] === true && !preg_grep('/^Cap V/', $names), '45 neighbours: only the first 40 are checked — the two that touch only Valves are dropped, 38 stay, and the JSON says there is more');
ok($nbCalls <= 43, "at most 40 subjects were asked of the engine ($nbCalls neighbour calls in all, hub and sections included)");
$html = page(as_member(PRIYA), "/bases/$p/subject?name=" . rawurlencode('Hub Pump'))['body'];
ok(str_contains($html, 'id="subject-neighbours-more"') && str_contains($html, 'and more'), 'the region says "and more"');
$many = [];
for ($i = 0; $i < 45; $i++) { $many[] = ['id' => 100 + $i, 'name' => 'Cap ' . $i]; }
$r = json_decode(php_g('echo json_encode(graph_confine_subjects("kb:" . ' . $p . ', $arg));', PRIYA, $many), true);
ok($r['ok'] === true && $r['more'] === true && count($r['subjects']) === 38 && $r['subjects'][0]['documents'] === 1, 'graph_confine_subjects: 45 given, 40 checked, 38 survive, "more" says the rest were dropped');
$r1 = json_decode(php_g('echo json_encode([graph_subject_in_base("kb:" . ' . $p . ', ["id" => 1, "name" => "Zorblat Pump"]), graph_subject_in_base("kb:" . ' . $p . ', ["id" => 3, "name" => "Quillon Valve"]), graph_subject_in_base("kb:" . ' . $w['valves'] . ', ["id" => 3, "name" => "Quillon Valve"])]);'), true);
ok($r1 === [2, null, 1], 'graph_subject_in_base: Zorblat Pump touches 2 of Pumps\' documents; Quillon Valve touches none of them (null) and 1 of Valves\'');
g_set($w);
g_cache_clear($p);
finish();
