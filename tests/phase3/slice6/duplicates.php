<?php
/** Slice 6 proof — duplicates (graph-explorer.md "Proof", Duplicates). */
require __DIR__ . '/lib.php';

echo "6. Duplicates\n";
$w = graph_world();
$p = $w['pumps'];
g_set($w);
pdo()->exec("UPDATE vocabulary_terms SET maludb_subject_id = NULL WHERE base_id = $p");
g_cache_clear($p);
[$c, $d, $raw] = gscreen(MARCO, "/bases/$p/graph");
$dup = $d['duplicates'] ?? [];
$groups = $dup['groups'] ?? [];
$names = array_map(static fn ($g) => array_column($g['subjects'], 'name'), $groups);
ok($c === 200 && count($groups) === 2 && $names[0] === ['Zorblat Pump', 'Zorblat Pumps'] && $groups[0]['suggested'] === 'Zorblat Pump' && $groups[0]['reason'] === 'alike' && $dup['documents_read'] === 4 && $dup['documents_total'] === 4,
    'Marco sees the group {Zorblat Pump, Zorblat Pumps} with the suggestion "Zorblat Pump" (4 of 4 documents read)');
ok($names[1] === ['Zorblat Pump', 'ZP-9'] && $groups[1]['reason'] === 'vocabulary' && $groups[1]['vocabulary_term_id'] > 0, 'and, while the term is not seeded, {Zorblat Pump, ZP-9}: the vocabulary makes ZP-9 an alias yet both are still subjects');
ok($groups[0]['subjects'][0]['documents'] === 2 && $groups[0]['subjects'][1]['documents'] === 1, 'each name with how many of the base\'s documents touch it');
ok($groups[0]['merge_url'] === "/vocabulary/new?base=$p&canonical_name=Zorblat%20Pump&aliases=Zorblat%20Pumps", 'Merge: ' . $groups[0]['merge_url']);
$html = page(as_member(MARCO), "/bases/$p/graph")['body'];
foreach (['graph-duplicates', 'dup-group-1', 'dup-group-2', 'dup-suggested-1', 'dup-merge-1', 'graph-duplicates-read'] as $id) {
    if (!str_contains($html, 'id="' . $id . '"')) { ok(false, "the page has #$id"); }
}
ok(str_contains($html, 'Saving the term marks the built sections for a rebuild'), 'the page says a merge marks the built sections for a rebuild');
ok(!str_contains(g_flat($d), 'Quillon') && !str_contains($html, 'Quillon'), 'nothing of Valves among the duplicates');

// the Merge button opens slice 3's vocabulary form
$f = page(as_member(MARCO), $groups[0]['merge_url']);
ok($f['code'] === 200 && str_contains($f['body'], 'id="term-edit-header"') && preg_match('/<textarea[^>]*name="aliases"[^>]*>([^<]*)</', $f['body'], $m) && str_contains($m[1], 'ZP-9') && str_contains($m[1], 'Zorblat Pumps')
    && str_contains($f['body'], 'name="term" value="'), 'the term "Zorblat Pump" exists: the form changes it, its aliases (ZP-9) kept and Zorblat Pumps added — not yet saved');
$before = (int) one('SELECT count(*) FROM vocabulary_terms WHERE base_id = :b', ['b' => $p]);
$f2 = page(as_member(MARCO), "/vocabulary/new?base=$p&canonical_name=" . rawurlencode('Merrow Filter') . '&aliases=' . rawurlencode("Merrow Filters\nMF-1"));
ok($f2['code'] === 200 && str_contains($f2['body'], 'id="term-add-header"') && str_contains($f2['body'], 'value="Merrow Filter"') && preg_match('/<textarea[^>]*name="aliases"[^>]*>([^<]*)</', $f2['body'], $m2) && trim($m2[1]) === "Merrow Filters\nMF-1"
    && (int) one('SELECT count(*) FROM vocabulary_terms WHERE base_id = :b', ['b' => $p]) === $before, 'a new term: the form is prefilled with the suggestion and the others as aliases, and opening it writes nothing');
ok(page(as_member(PRIYA), "/vocabulary/new?base=$p&canonical_name=x")['code'] === 403, 'and a Reader cannot open it (the vocabulary is the curators\')');

// seeded: both names fold into the term → left out; the other group stays
pdo()->exec("UPDATE vocabulary_terms SET maludb_subject_id = '1' WHERE base_id = $p AND canonical_name = 'Zorblat Pump'");
[, $d2] = gscreen(MARCO, "/bases/$p/graph?fresh=1");
$n2 = array_map(static fn ($g) => array_column($g['subjects'], 'name'), $d2['duplicates']['groups']);
ok($n2 === [['Zorblat Pump', 'Zorblat Pumps']], 'once the term is seeded (maludb_subject_id set) ZP-9 is not a group — both names fold into it (' . json_encode($n2) . ')');
pdo()->exec("UPDATE vocabulary_terms SET aliases = '{\"ZP-9\",\"Zorblat Pumps\"}' WHERE base_id = $p AND canonical_name = 'Zorblat Pump'");
[, $d3] = gscreen(MARCO, "/bases/$p/graph?fresh=1");
ok($d3['duplicates']['groups'] === [], 'and when the seeded term takes Zorblat Pumps as an alias too, the first group is folded as well');
pdo()->exec("UPDATE vocabulary_terms SET aliases = '{\"ZP-9\"}', maludb_subject_id = NULL WHERE base_id = $p AND canonical_name = 'Zorblat Pump'");

// Priya: no region, no key
[, $dp] = gscreen(PRIYA, "/bases/$p/graph?fresh=1");
ok(!array_key_exists('duplicates', $dp) && !str_contains(page(as_member(PRIYA), "/bases/$p/graph")['body'], 'dup-group'), 'Priya never sees the region; its JSON key is absent (?fresh=1 is ignored for her)');

// the pure parts
$norm = json_decode(php_g('echo json_encode(array_map("graph_normal_name", $arg));', PRIYA, ['The Zorblat Pumps', '  ZP-9 ', 'Crème Brûlée', 'An Apple', 'Glass', 'Bus', 'Zorblat-Pump', "O'Brien Valves", 'Les Pompes']), true);
ok($norm === ['zorblat pump', 'zp 9', 'creme brulee', 'apple', 'glas', 'bus', 'zorblat pump', 'o brien valve', 'les pompe'], 'graph_normal_name: lower case, unaccented, punctuation and the/a/an dropped, a trailing s dropped from words over four letters (' . json_encode($norm) . ')');
$subs = [['subject_id' => 1, 'name' => 'Alpha Pump', 'type' => null, 'documents' => 1], ['subject_id' => 2, 'name' => 'alpha pumps', 'type' => null, 'documents' => 3], ['subject_id' => 3, 'name' => 'Beta', 'type' => null, 'documents' => 1], ['subject_id' => 4, 'name' => 'B-1', 'type' => null, 'documents' => 1]];
$terms = [['term_id' => 7, 'canonical' => 'Beta', 'aliases' => ['B-1'], 'seeded' => false]];
$g = json_decode(php_g('echo json_encode(graph_duplicate_groups($arg[0], $arg[1]));', PRIYA, [$subs, $terms]), true);
ok(count($g) === 2 && $g[0]['suggested'] === 'alpha pumps' && $g[1]['suggested'] === 'Beta' && $g[1]['vocabulary_term_id'] === 7, 'groups by name and by alias; the suggestion is the most connected (or the vocabulary term itself)');
$terms[0]['seeded'] = true;
$g = json_decode(php_g('echo json_encode(graph_duplicate_groups($arg[0], $arg[1]));', PRIYA, [$subs, $terms]), true);
ok(count($g) === 1 && $g[0]['normalised'] === 'alpha pump', 'a seeded term folds its group away');
g_cache_clear($p);
finish();
