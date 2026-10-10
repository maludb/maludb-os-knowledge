<?php
/** Slice 6 proof — the engine down (graph-explorer.md "Proof", Engine down). Stops the fake engine and starts it again. */
require __DIR__ . '/lib.php';

echo "10. Engine down\n";
$w = graph_world();
$p = $w['pumps'];
g_set($w, g_communities($w));
g_cache_clear($p);
$msg = 'The knowledge engine is not available right now.';
$internal = getenv('KN_APP') === 'apache' ? 'http://127.0.0.1:8507' : BASE;
kb_stop();
$jar = as_member(PRIYA);
$r = page($jar, "/bases/$p/graph?q=Zorblat");
$h = $r['body'];
$regions = ['size', 'communities', 'god-nodes', 'surprises', 'search'];
$have = array_filter($regions, static fn ($x) => str_contains($h, 'id="graph-' . $x . '-error"'));
ok($r['code'] === 200 && count($have) === 5 && substr_count($h, $msg) >= 5, 'with the engine stopped each region shows the module\'s sentence: "' . $msg . '" (' . implode(', ', $have) . ')');
ok(str_contains($h, 'id="graph-size-subjects">4<') && str_contains($h, 'id="graph-size-statements">3<') && str_contains($h, 'id="graph-size-documents">—<'), 'the cached counts still render (4 subjects, 3 statements)');
ok(!str_contains($h, getenv('MALUDB_KB_API_TOKEN')) && !str_contains($h, '8503') && !str_contains($h, 'curl') && !preg_match('/Traceback|Stack trace|PDOException|Fatal/', $h), 'no MaluDB message, address or token in the page');
[$c, $j] = screen($jar, "/bases/$p/graph?q=Zorblat");
ok($c === 200 && array_keys((array) $j['errors']) === ['size', 'communities', 'god_nodes', 'surprises', 'search'] && array_unique(array_values((array) $j['errors'])) === ['engine_unavailable'], 'the JSON names the failed regions: ' . json_encode($j['errors']));
$hm = page(as_member(MARCO), "/bases/$p/graph")['body'];
ok(str_contains($hm, 'id="graph-duplicates-error"') && str_contains($hm, 'id="dup-group-1"') === false, 'a curator\'s duplicates region shows it too');
$s = page($jar, "/bases/$p/subject?name=" . rawurlencode('Zorblat Pump'));
ok($s['code'] === 200 && str_contains($s['body'], 'id="graph-subject-error"') && str_contains($s['body'], $msg) && str_contains($s['body'], 'id="subject-name"'), 'subject-view says the engine is unavailable rather than 404');
$s2 = page($jar, "/bases/$p/subject?name=" . rawurlencode('Nothing At All'));
ok($s2['code'] === 200 && str_contains($s2['body'], $msg), '…even for a name it could not look up');
$cm = page($jar, "/bases/$p/community?community=11");
ok($cm['code'] === 200 && str_contains($cm['body'], 'id="graph-community-error"') && str_contains($cm['body'], $msg), 'community-view too');
$pa = page($jar, "/bases/$p/path?from=" . rawurlencode('Zorblat Pump') . '&to=' . rawurlencode('Merrow Filter'));
ok($pa['code'] === 200 && str_contains($pa['body'], 'id="graph-path-error"') && str_contains($pa['body'], $msg), 'and path');
$sec = page($jar, '/sections/' . $w['zorblat']);
ok($sec['code'] === 200 && str_contains($sec['body'], 'id="graph-taught-error"') && str_contains($sec['body'], 'id="section-text"'), 'slice 2\'s section-view still reads, with the taught region in error');
$op = static function (string $name, array $args) use ($internal): array {
    [$c, $b] = bridge(['op' => $name, 'caller' => ['kind' => 'member', 'member_id' => PRIYA], 'door' => 'mcp', 'run_id' => null, 'request_id' => null, 'is_eval' => false, 'args' => $args], null, null, $internal);
    return [$c, $b];
};
[$c, $b] = $op('god_nodes', ['base' => $p]);
ok($c === 503 && $b['error']['code'] === 'engine_unavailable' && !str_contains(g_flat($b), getenv('MALUDB_KB_API_TOKEN')), 'the bridge: engine_unavailable (503)');
[$c, $b] = $op('graph_stats', ['base' => $p]);
ok($c === 200 && $b['engine'] === 'unavailable' && $b['subjects_count'] === 4 && $b['documents'] === null, 'graph_stats still answers the cached counts with engine: unavailable');
[$c, $b] = $op('subject', ['base' => $p, 'subject' => 'Zorblat Pump']);
ok($c === 503 && $b['error']['code'] === 'engine_unavailable', 'subject → engine_unavailable, not not_in_base');
kb_start();
g_cache_clear($p);
[$c, $j] = screen($jar, "/bases/$p/graph");
ok($c === 200 && (array) $j['errors'] === [] && $j['size']['documents'] === 4, 'the engine back: the page is whole again, and nothing of the failure was cached');
finish();
