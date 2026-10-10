<?php
/** Slice 4 proof — JSON mode and agents (builds.md "Proof", bullet 10). */
require __DIR__ . '/lib.php';

echo "17. JSON mode and agents\n";
world4();
$jb = curated_base('SMOKE Json Builds', 'smoke-json-builds');
act(as_member(PRIYA), '/bases/members/add.php', ['base' => $jb, 'member' => SEAMUS, 'level' => 'curate']);
add_text($jb, 'SMOKE Json Source', "# One\n\nThe Zorblat Pump hums.");
worker();
$person = ['X-Action-Token: ' . person_token(PRIYA)];
$shape = static fn (array $b): bool => ($b['ok'] ?? false) === true && isset($b['did'], $b['record_id'], $b['location']) && array_key_exists('refresh', $b);
[$c, $b] = act_token('/builds/start.php', ['base' => $jb], $person);
$id = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $shape($b) && $b['location'] === "/builds/$id", 'build_start under an action token: {ok, did, record_id, location, refresh}');
[$c, $b] = act_token('/builds/cancel.php', ['build' => $id], $person);
ok($c === 200 && $shape($b) && (int) $b['record_id'] === $id, 'build_cancel answers the shape');
[$c, $b] = act_token('/builds/rebuild-all.php', ['base' => $jb], $person);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($b['error']['fields']['confirm']), 'build_rebuild_all without confirm: 422 {error: {code: invalid, fields: {confirm}}}');
[$c, $b] = act_token('/builds/rebuild-all.php', ['base' => $jb, 'confirm' => '1'], $person);
ok($c === 200 && $shape($b), 'with confirm: the shape');
cancel_live($jb);

echo "   The expert\n";
$since = last_activity_id();
[$c, $b] = act_token('/builds/start.php', ['base' => $jb], as_agent(run_token(SEAMUS, 7461)));
$aid = (int) ($b['record_id'] ?? 0);
ok($c === 200 && (int) build_row($aid)['started_by'] === SEAMUS, 'the expert (curate share, Curator role) starts a build within the limit');
$st = activity('build.start', $since);
ok(count($st) === 1 && $st[0]['source'] === 'agent', 'logged with source agent');
run_build($aid);
ok(build_row($aid)['status'] === 'done', 'and it builds');
[$c, $d] = screen(as_member(PRIYA), "/builds/$aid");
ok($c === 200 && ($d['build']['status'] ?? '') === 'done' && ($d['build']['started_by'] ?? '') !== '' && str_starts_with((string) ($d['log'][0] ?? ''), 'prompt sha256='), 'build-view as JSON: the build, the progress, the log');
$reg = json_decode(file_get_contents(APP_ROOT . '/mcp/action_registry.json'), true);
$ba = count(array_filter(['build_start', 'build_rebuild_all', 'build_cancel'], static fn ($x) => !empty($reg['actions'][$x]['built'])));
$bs = count(array_filter(['builds', 'build-view'], static fn ($x) => !empty($reg['screens'][$x]['built'])));
ok($ba === 3 && $bs === 2, "the registry reads 2 screens and 3 actions of this slice built ($bs, $ba)");
finish();
