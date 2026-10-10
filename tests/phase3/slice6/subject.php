<?php
/** Slice 6 proof — one subject (graph-explorer.md "Proof", Subject). */
require __DIR__ . '/lib.php';

echo "2. Subject\n";
$w = graph_world();
$p = $w['pumps'];
g_set($w);
g_cache_clear($p);
$name = rawurlencode('Zorblat Pump');
$since = last_activity_id();
[$c, $d, $raw] = gscreen(PRIYA, "/bases/$p/subject?name=$name");
ok($c === 200 && $d['subject']['name'] === 'Zorblat Pump' && $d['subject']['type'] === 'product' && $d['subject']['description'] === 'A centrifugal pump.' && is_int($d['subject']['subject_id']), 'Zorblat Pump: the engine\'s name, type and description');
$verbs = array_column($d['statements'], 'verb');
$texts = [];
foreach ($d['statements'] as $g) { foreach ($g['statements'] as $x) { $texts[] = $x['text']; } }
ok($verbs === ['rated', 'uses'] && $texts === ['Zorblat Pump is rated at forty bar', 'Zorblat Pump uses the Merrow Filter'], 'statements grouped by verb (rated, uses) — the Valves statement about the pump is not here (' . implode(' | ', $texts) . ')');
ok($d['statements'][0]['statements'][0]['section']['section_id'] === $w['zorblat'] && $d['statements'][1]['statements'][0]['section']['section_id'] === $w['merrow'], 'each statement points at its section');
ok(array_column($d['neighbours'], 'name') === ['Merrow Filter'] && $d['neighbours'][0]['verb'] === 'uses' && $d['neighbours'][0]['documents'] === 1, 'neighbours: Merrow Filter (not Quillon Valve), with the verb and its document count');
$secs = $d['sections'];
ok(array_column($secs, 'section_id') === [$w['zorblat'], $w['merrow']] && $secs[0]['heading_path'] === 'Pump manual › Pumps › Zorblat Pump' && $secs[0]['page_no'] === 1 && $secs[1]['page_no'] === 2 && $secs[0]['source']['title'] === 'Pump manual'
    && $secs[0]['url'] === '/sections/' . $w['zorblat'], 'the sections behind it: 501 and 502 with heading paths, source and pages, linking to section-view');
ok(!str_contains(g_flat($d), 'Quillon') && !str_contains(g_flat($d), '601') && !str_contains(g_flat($d), 'closes at twelve'), 'nothing of Valves in the JSON');
$ask = '/ask?base=' . $p . '&q=What%20does%20this%20knowledge%20base%20say%20about%20Zorblat%20Pump%3F';
ok($d['ask_url'] === $ask, 'Ask about this: ' . $d['ask_url']);
$n0 = questions_count();
$ap = page(as_member(PRIYA), $ask);
ok($ap['code'] === 200 && str_contains($ap['body'], 'What does this knowledge base say about Zorblat Pump?') && questions_count() === $n0, '…opens slice 5\'s ask prefilled and sends nothing');

$html = page(as_member(PRIYA), "/bases/$p/subject?name=$name")['body'];
foreach (['subject-head', 'subject-statements', 'subject-neighbours', 'subject-sections', 'subject-svg', 'subject-ask', 'subject-path-form', 'subject-name'] as $id) {
    if (!str_contains($html, 'id="' . $id . '"')) { ok(false, "the page has #$id"); }
}
ok(str_contains($html, 'id="subject-ask"') && str_contains($html, 'name="from" value="Zorblat Pump"') && str_contains($html, 'Path to…'), 'the page has its regions, Ask about this and the Path to… form');
preg_match('/<svg id="subject-svg"[^>]*>/', $html, $m);
ok(str_contains($m[0] ?? '', 'viewBox="0 0 640 420"') && str_contains($m[0], 'role="img"') && str_contains($m[0], 'd-none d-lg-block') && str_contains($m[0], 'aria-label="Neighbourhood of Zorblat Pump: 5 neighbours"'), 'the drawing: a 640 x 420 viewBox, role img, hidden under 992 px, labelled: ' . ($m[0] ?? ''));
preg_match_all('/<a href="([^"]+)"[^>]*class="graph-svg-node" data-kind="(\w+)"><title>([^<]*)<\/title>/', $html, $nodes, PREG_SET_ORDER);
ok(count($nodes) === 5 && count(array_filter($nodes, static fn ($n) => str_starts_with($n[1], '/'))) === 5, 'the drawing has 5 nodes (2 sections, Merrow Filter, 2 statements), each an <a> to a screen');
$names = array_map(static fn ($n) => html_entity_decode($n[3]), $nodes);
ok(count(array_unique(array_column($nodes, 2))) === 3, 'subjects, sections and statements are each drawn as their own kind');

// the drawing alone: the cap, the rings, the order and the labels
$many = [];
for ($i = 1; $i <= 30; $i++) { $many[] = ['kind' => $i % 3 ? 'subject' : 'section', 'name' => 'Node ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . ' with a very long name that is cut', 'title' => 'Full ' . $i, 'url' => '/x/' . $i, 'verb' => $i % 4 ? 'verbs' . $i : null]; }
$shuffled = array_slice($many, 0, 24);
shuffle($shuffled);
$shuffled = array_merge($shuffled, array_slice($many, 24));
$out = json_decode(php_g('echo json_encode([graph_svg(["name" => "The centre"], $arg[0]), graph_svg(["name" => "The centre"], $arg[1])]);', PRIYA, [$many, $shuffled]), true);
[$svg1, $svg2] = $out;
ok($svg1 === $svg2 && substr_count($svg1, 'class="graph-svg-node"') === 24, 'the layout is deterministic (angles by sorted name) and holds 24 nodes at most');
ok(substr_count($svg1, '<title>Full ') === 24 && !preg_match('/>Node \d\d with a very long name that is cut</', $svg1) && str_contains($svg1, '…'), 'labels are cut at 24 characters, the full name in <title>');
preg_match_all('/<circle cx="([\d.]+)" cy="([\d.]+)" r="15"/', $svg1, $cs, PREG_SET_ORDER);
$radii = array_unique(array_map(static fn ($c) => round(hypot((float) $c[1] - 320, (float) $c[2] - 205) / 5), $cs));
ok(count($cs) === 24 && count($radii) >= 2, 'past 12 the nodes sit on two rings');

// exploring does not nest the way back: a link carries this page's address without its own ?back=
$deep = page(as_member(PRIYA), "/bases/$p/subject?name=" . rawurlencode('Merrow Filter') . '&back=' . rawurlencode("/bases/$p/graph?q=Zorblat"))['body'];
ok(str_contains($deep, 'back=%2Fbases%2F' . $p . '%2Fsubject%3Fname%3DMerrow%2520Filter') && !str_contains($deep, 'back%3D') && str_contains($deep, 'id="subject-view-back"') && str_contains($deep, 'href="/bases/' . $p . '/graph?q=Zorblat"'),
    'the links of a subject carry its own address as the way back, never a back inside a back; and the page offers the way back it was given');

// a subject the base does not hold
[$c, $q, $raw] = gscreen(PRIYA, "/bases/$p/subject?name=" . rawurlencode('Quillon Valve'));
$m1 = json_decode($raw['body'], true)['error']['message'] ?? '';
[$c2, , $raw2] = gscreen(PRIYA, "/bases/$p/subject?name=" . rawurlencode('Nothing At All'));
$m2 = json_decode($raw2['body'], true)['error']['message'] ?? '';
ok($c === 404 && $c2 === 404 && $m1 === 'Subject not found in this base.' && $m1 === $m2, '"Quillon Valve" from Pumps and a name the engine does not know → the same 404: ' . $m1);
$pg = req('GET', "/bases/$p/subject?name=" . rawurlencode('Quillon Valve'), ['jar' => as_member(PRIYA)]);
ok($pg['code'] === 404 && !str_contains($pg['body'], 'closes at twelve'), 'in a browser too');
[$c] = gscreen(PRIYA, "/bases/$p/subject");
ok($c === 422, 'no name → 422');
[$c, , $r3] = gscreen(DEE, "/bases/" . $w['valves'] . "/subject?name=" . rawurlencode('Quillon Valve'));
ok($c === 404, 'Valves\' own subject from Bea (no share) → 404 on the base');
[$c, $qv] = gscreen(MARCO, "/bases/" . $w['valves'] . "/subject?name=" . rawurlencode('Quillon Valve'));
ok($c === 200 && $qv['subject']['name'] === 'Quillon Valve' && array_column($qv['neighbours'], 'name') === [], 'Marco opens Quillon Valve in Valves: its neighbour Zorblat Pump is not Valves\' subject');

// aliases: the vocabulary is the curator's; the description carries a Reader's
[$c, $dm] = gscreen(MARCO, "/bases/$p/subject?name=$name");
ok($c === 200 && in_array('ZP-9', $dm['aliases'], true) && !in_array('ZP-9', $d['aliases'], true) && $d['aliases'] === [], 'Marco sees ZP-9 among the aliases, Priya does not');
$zp = rawurlencode('ZP-9');
[, $z9] = gscreen(MARCO, "/bases/$p/subject?name=$zp");
ok(in_array('Zorblat Pump', $z9['aliases'], true), 'and from ZP-9 the term\'s name, for the curator');
g_set($w, ['subjects' => ['1' => ['label' => 'Zorblat Pump', 'type' => 'product', 'description' => "A centrifugal pump.\nAlso called: Zorbie; Z-Pump"]] + g_graph($w)['subjects']]);
g_cache_clear($p);
[, $dd] = gscreen(PRIYA, "/bases/$p/subject?name=$name");
ok($dd['aliases'] === ['Zorbie', 'Z-Pump'] && $dd['subject']['description'] === 'A centrifugal pump.', 'a Reader sees the names the description carries (and the description without that line)');
g_set($w);
g_cache_clear($p);

// logging
$sv = array_values(array_filter(activity('screen.view', $since), static fn ($r) => (json_decode($r['after'], true)['subject_id'] ?? null) !== null));
ok($sv !== [] && !str_contains($sv[0]['after'], 'Zorblat') && (int) $sv[0]['base_id'] === $p && is_int(json_decode($sv[0]['after'], true)['subject_id']), 'screen.view logged with the subject id and never the name');
// an engine failure on the page, not a 404 (the engine_down proof stops the engine; here the engine answers 500)
g_set($w, ['fail' => true]);
g_cache_clear($p);
[$c, $e] = gscreen(PRIYA, "/bases/$p/subject?name=$name");
ok($c === 200 && ($e['errors']['engine'] ?? '') === 'engine_error' && $e['subject']['subject_id'] === null, 'an engine that fails: the page says so (200), not a 404');
g_set($w);
finish();
