<?php
/** Slice 3 proof — JSON mode and agents (guidelines-areas.md "Proof", bullet 8). */
require __DIR__ . '/lib.php';

echo "10. JSON mode and agents\n";
$w = world();
$bid = $w['base'];
$person = ['X-Action-Token: ' . person_token(PRIYA)];
$shape = static fn (array $b): bool => ($b['ok'] ?? false) === true && isset($b['did'], $b['record_id'], $b['location']) && array_key_exists('refresh', $b);
[$c, $b] = act_token('/guidelines/save.php', ['base' => $bid, 'kind' => 'audience', 'title' => 'SMOKE Token', 'body' => 'Engineers in the field.'], $person);
$gid = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $shape($b), 'guideline_save under an action token: {ok, did, record_id, location, refresh}');
[$c, $b] = act_token('/guidelines/save.php', ['guideline' => $gid, '_partial' => '1', 'title' => 'SMOKE Token Renamed'], $person);
$g = q('SELECT * FROM guidelines WHERE id = :g', ['g' => $gid])[0];
ok($c === 200 && $g['title'] === 'SMOKE Token Renamed' && $g['body'] === 'Engineers in the field.' && $g['kind'] === 'audience', '_partial=1 keeps the untouched fields of a guideline');
[$c, $b] = act_token('/vocabulary/save.php', ['base' => $bid, 'canonical_name' => 'SMOKE Gasket', 'aliases' => 'seal ring', 'subject_type' => 'part', 'description' => 'A rubber ring.'], $person);
$tid = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $shape($b), 'vocabulary_save answers the shape');
[$c, $b] = act_token('/vocabulary/save.php', ['term' => $tid, '_partial' => '1', 'description' => 'A rubber ring, renamed.'], $person);
$t = q('SELECT * FROM vocabulary_terms WHERE id = :t', ['t' => $tid])[0];
ok($c === 200 && $t['subject_type'] === 'part' && kn_compile_pg_array((string) $t['aliases']) === ['seal ring'] && $t['description'] === 'A rubber ring, renamed.', '_partial=1 keeps the untouched fields of a term');
[$c, $b] = act_token('/areas/save.php', ['base' => $bid, 'name' => 'SMOKE Token Area', 'description' => 'By token.', 'seed_terms' => 'SMOKE Gasket'], $person);
$aid = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $shape($b), 'focus_area_save answers the shape');
[$c, $b] = act_token('/areas/save.php', ['focus_area' => $aid, '_partial' => '1', 'position' => 5], $person);
$a = q('SELECT * FROM focus_areas WHERE id = :a', ['a' => $aid])[0];
ok($c === 200 && $a['description'] === 'By token.' && kn_compile_pg_array((string) $a['seed_terms']) === ['SMOKE Gasket'] && (int) $a['position'] === 5, '_partial=1 keeps the untouched fields of an area');
[$c, $b] = act_token('/guidelines/save.php', ['base' => $bid, 'kind' => 'audience', 'body' => ''], $person);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($b['error']['fields']['body']), '422 {error: {code: invalid, fields}}');
foreach ([['/areas/assign.php', ['focus_area' => $aid, 'source' => $w['source'], 'assigned' => 'yes']], ['/sources/areas.php', ['source' => $w['source'], 'areas' => [$aid]]],
          ['/areas/link.php', ['focus_area' => $aid]], ['/vocabulary/delete.php', ['term' => $tid]], ['/guidelines/delete.php', ['guideline' => $gid]], ['/areas/delete.php', ['focus_area' => $aid]]] as [$path, $form]) {
    [$c, $b] = act_token($path, $form, $person);
    ok($c === 200 && $shape($b), "$path answers the shape");
}
[$c, $b] = act_token('/vocabulary/import.php', ['base' => $bid, 'step' => 'apply', 'csv' => "term\nSMOKE Bolt\n"], $person);
ok($c === 200 && $shape($b) && ($b['added'] ?? 0) === 1, 'vocabulary_import answers the shape');
[$c, $b] = act_token('/vocabulary/import.php', ['base' => $bid, 'step' => 'check', 'csv' => "term\nSMOKE Nut\n"], $person);
ok($c === 200 && ($b['data']['new'] ?? 0) === 1 && (int) one("SELECT count(*) FROM vocabulary_terms WHERE canonical_name = 'SMOKE Nut'") === 0, 'a check answers JSON and writes nothing');

echo "   The expert\n";
$tok = run_token(SEAMUS, 7401);
$since = last_activity_id();
[$c, $b] = act_token('/guidelines/save.php', ['base' => $bid, 'kind' => 'exclusion', 'title' => 'SMOKE Agent', 'body' => 'Nothing about prices.'], as_agent($tok));
$ag = (int) ($b['record_id'] ?? 0);
ok($c === 200 && one('SELECT origin FROM guidelines WHERE id = :g', ['g' => $ag]) === 'agent', 'the expert (run token + relay, curate share, Curator role) saves a guideline: origin agent');
[$c, $b] = act_token('/areas/propose.php', ['base' => $bid, 'name' => 'SMOKE Agent Area', 'proposal_note' => 'Subjects about prices.'], as_agent($tok));
ok($c === 200 && one('SELECT status FROM focus_areas WHERE id = :a', ['a' => (int) ($b['record_id'] ?? 0)]) === 'proposed', 'and proposes an area');
$rows = array_merge(activity('guideline.save', $since), activity('focus_area.propose', $since));
ok(count($rows) === 2 && array_unique(array_column($rows, 'source')) === ['agent'], 'both logged with source agent');
$reg = json_decode(file_get_contents(APP_ROOT . '/mcp/action_registry.json'), true);
ok(($reg['actions']['guideline_delete']['approval'] ?? null) === 'deletion' && ($reg['actions']['vocabulary_delete']['approval'] ?? null) === 'deletion' && ($reg['actions']['focus_area_delete']['approval'] ?? null) === 'deletion',
    'guideline_delete, vocabulary_delete and focus_area_delete are listed deletion in the registry');
$acts = ['guideline_save', 'guideline_delete', 'vocabulary_save', 'vocabulary_delete', 'vocabulary_import', 'focus_area_save', 'focus_area_propose', 'focus_area_accept', 'focus_area_dismiss', 'focus_area_link', 'focus_area_delete', 'focus_area_assign', 'source_areas_set'];
$screens = ['guidelines', 'guideline-add', 'guideline-edit', 'guideline-view', 'guidelines-compiled', 'vocabulary', 'term-add', 'term-edit', 'vocabulary-import', 'areas', 'area-add', 'area-edit', 'area-view'];
$ba = count(array_filter($acts, static fn ($x) => !empty($reg['actions'][$x]['built'])));
$bs = count(array_filter($screens, static fn ($x) => !empty($reg['screens'][$x]['built'])));
ok($ba === 13 && $bs === 13, "the registry reads 13 screens and 13 actions of this slice built ($bs, $ba)");
finish();
