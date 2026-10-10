<?php
/** Slice 9 proof — JSON mode and the registry (home-admin.md "Proof": json.php). */
require __DIR__ . '/lib.php';

$w = home_world();
$hb = $w['handbook'];
model_world();
$shape = static fn (array $b): bool => ($b['ok'] ?? false) === true && isset($b['did'], $b['record_id'], $b['location'], $b['refresh']);
$admin = ['X-Action-Token: ' . person_token(OWNER)];
$cara = ['X-Action-Token: ' . person_token(CARA)];
$priya = ['X-Action-Token: ' . person_token(PRIYA)];
$agent = as_agent(run_token(SEAMUS, 9301));
set_setting('question_retention_days', '30');
pdo()->exec('DELETE FROM questions');
old_question($hb, PRIYA, 'SMOKE json old', 60 * 86400);

echo "1. The admin's actions under a person's token\n";
[$c, $b] = act_token('/admin/purge.php', ['days' => 30, 'confirm' => 'yes'], $admin);
ok($c === 200 && $shape($b) && $b['record_id'] === 1 && $b['location'] === '/admin/purge?days=30&purged=1' && $b['refresh'] === 'settingsChanged' && $b['purged'] === 1, 'question_purge: {ok, did, record_id, location, refresh} (+ purged)');
[$c, $b] = act_token('/admin/purge.php', ['days' => 30], $admin);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($b['error']['fields']['confirm']) && is_array($b['error']['errors'] ?? null), 'question_purge without confirm → 422 {error: {code: invalid, message, errors, fields: {confirm}}}');
[$c, $b] = act_token('/admin/purge.php', ['days' => 'many', 'confirm' => 'yes'], $admin);
ok($c === 422 && isset($b['error']['fields']['days']), 'days that are not a number → 422 on days');
[$c, $b] = act_token('/admin/model.php', ['model_alias' => 'fake-model-json'], $admin);
ok($c === 200 && $shape($b) && $b['record_id'] === 1 && $b['location'] === '/admin/model' && $b['bases_sent'] >= 1 && isset($b['bases_failed'], $b['rebuild_marked']), 'model_config_save: the shape (+ bases_sent, bases_failed, rebuild_marked)');
[$c, $b] = act_token('/admin/model.php', ['embedding_model' => 'fake-embed-json'], $admin);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($b['error']['fields']['rebuild_confirmed']), 'a new embedding model without rebuild_confirmed → 422 {fields: {rebuild_confirmed}}');
[$c, $b] = act_token('/admin/agents.php', ['expert' => SEAMUS, 'archivist' => ARCHIVIST], $admin);
ok($c === 200 && $shape($b) && $b['record_id'] === 1 && $b['location'] === '/admin/agents' && $b['expert_member_id'] === SEAMUS, 'agents_settings_save: the shape');
[$c, $b] = act_token('/admin/agents.php', ['expert' => PRIYA], $admin);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($b['error']['fields']['expert']), 'a human as the expert → 422 {fields: {expert}}');
[$c, $b] = act_token('/keys/revoke.php', ['api_key' => mint(CARA, $hb, 'SMOKE json key')['id'], 'return_to' => '/admin/keys'], $admin);
ok($c === 200 && $shape($b) && str_starts_with($b['location'], '/admin/keys'), 'key_revoke from the admin\'s list: the shape, landing on /admin/keys');
foreach (['/admin/purge.php' => ['days' => 30, 'confirm' => 'yes'], '/admin/model.php' => ['model_alias' => 'x'], '/admin/agents.php' => ['expert' => SEAMUS]] as $path => $form) {
    [$c1] = act_token($path, $form, $cara);
    [$c2] = act_token($path, $form, $agent);
    ok($c1 === 403 && $c2 === 403, "$path: a Curator's token and a Member agent's run token → 403");
}

echo "2. The kit's actions made whole\n";
[$c, $b] = act_token('/settings/prefs.php', ['email_enabled' => 'yes', 'kinds' => 'build_done,shared'], $priya);
ok($c === 200 && $shape($b) && $b['record_id'] === PRIYA && $b['refresh'] === 'prefsChanged', 'prefs_save: the shape');
[$c, $b] = act_token('/settings/prefs.php', ['kinds' => 'nonsense'], $priya);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid', 'an unknown kind → 422');
[$c, $b] = act_token('/settings/tokens/mint.php', ['label' => 'SMOKE json token'], $priya);
$tid = (int) ($b['record_id'] ?? 0);
ok($c === 200 && ($b['ok'] ?? false) === true && $tid > 0 && str_starts_with((string) $b['token'], 'mcp_') && isset($b['location']), 'token_mint: the token once, the record id, the location');
[$c, $b] = act_token('/settings/tokens/mint.php', ['label' => 'x'], $agent);
ok($c === 403, '…people only (an agent\'s run token → 403)');
[$c, $b] = act_token('/settings/tokens/revoke.php', ['token' => $tid], $priya);
ok($c === 200 && $shape($b) && $b['record_id'] === $tid, 'token_revoke: the shape');
$nid = (int) one("SELECT kn_notify(:m, 'shared', 'SMOKE json n', 'b', 'base', :b)", ['m' => PRIYA, 'b' => $hb]);
[$c, $b] = act_token('/settings/notifications/read.php', ['notification' => $nid], $priya);
ok($c === 200 && $shape($b) && $b['record_id'] === $nid && $b['count'] === 1, 'notification_read: the shape');

echo "3. The screens as JSON, and the registry\n";
$screens = ['/' => ['bases', 'first_run', 'may'], '/admin/' => ['pages'], '/admin/purge' => ['would_purge'], '/admin/model' => ['connection', 'health', 'recorded'], '/admin/usage' => ['bases', 'totals', 'months'],
            '/admin/bases' => ['bases'], '/admin/keys' => ['keys'], '/admin/agents' => ['declared', 'agents', 'expert_member_id']];
$bad = [];
foreach ($screens as $path => $keys) {
    [$c, $d] = screen(as_member(OWNER), $path);
    if ($c !== 200 || array_diff($keys, array_keys($d)) !== []) { $bad[] = $path; }
}
ok($bad === [], 'each screen answers JSON with its keys' . ($bad ? ' — not: ' . implode(', ', $bad) : ''));
foreach (['/settings/', '/notifications', '/settings/tokens/'] as $path) { [$c] = screen(as_member(PRIYA), $path); if ($c !== 200) { $bad[] = $path; } }
ok($bad === [], 'and the person\'s screens: settings, notifications, tokens');
$reg = json_decode(file_get_contents(APP_ROOT . '/mcp/action_registry.json'), true);
$unbuiltS = array_keys(array_filter($reg['screens'], static fn ($s) => empty($s['built'])));
$unbuiltA = array_keys(array_filter($reg['actions'], static fn ($a) => empty($a['built'])));
ok($unbuiltS === [] && $unbuiltA === [] && count($reg['screens']) === 61 && count($reg['actions']) === 62, 'the registry reads every screen (61) and every action (62) of docs/knowledge-action-manifest.md built');
$mine = ['question_purge' => 'deletion', 'model_config_save' => 'other', 'agents_settings_save' => 'other'];
$okReg = true;
foreach ($mine as $a => $cat) { $okReg = $okReg && ($reg['actions'][$a]['approval'] ?? '') === $cat && !empty($reg['actions'][$a]['agent']) && !empty($reg['actions'][$a]['built']); }
ok($okReg && ($reg['actions']['token_mint']['agent'] ?? true) === false, 'the three actions: question_purge deletion, the other two other, agents allowed; token_mint people only');
set_setting('question_retention_days', '365');
finish();
