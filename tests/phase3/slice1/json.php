<?php
/** Slice 1 proof — JSON mode and agents (bases-core.md "Proof", bullet 7). */
require __DIR__ . '/lib.php';

echo "7. JSON mode and agents\n";
$person = ['X-Action-Token: ' . person_token(PRIYA)];
$shape = static fn (array $b): bool => ($b['ok'] ?? false) === true && isset($b['did'], $b['record_id'], $b['location']) && array_key_exists('refresh', $b);
[$c, $b] = act_token('/bases/save.php', ['name' => 'SMOKE By Token', 'template' => 'general'], $person);
$id = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $shape($b) && $b['location'] === '/bases/' . $id, 'base_create under a person\'s action token: {ok, did, record_id, location, refresh}');
$calls = [
    'base_update'            => ['/bases/save.php', ['base' => $id, 'description' => 'By token.']],
    'base_settings_save'     => ['/bases/settings-save.php', ['base' => $id, 'sensitivity' => 'restricted']],
    'base_visibility_set'    => ['/bases/visibility.php', ['base' => $id, 'visibility' => 'internal']],
    'base_share'             => ['/bases/members/add.php', ['base' => $id, 'department' => DESIGN, 'level' => 'read']],
    'base_share_guest'       => ['/bases/members/add-guest.php', ['base' => $id, 'guest' => ANN]],
    'base_share_application' => ['/bases/members/add-application.php', ['base' => $id, 'application' => 'spaces']],
    'base_archive'           => ['/bases/archive.php', ['base' => $id]],
    'base_restore'           => ['/bases/restore.php', ['base' => $id]],
];
foreach ($calls as $action => [$path, $form]) {
    [$c, $b] = act_token($path, $form, $person);
    ok($c === 200 && $shape($b), "$action answers the shape ($c " . ($b['did'] ?? msg($b)) . ')');
}
$row = (int) one("SELECT id FROM base_members WHERE base_id = :b AND principal_kind = 'application'", ['b' => $id]);
[$c, $b] = act_token('/bases/members/remove.php', ['base' => $id, 'base_member' => $row], $person);
ok($c === 200 && $shape($b) && (int) $b['record_id'] === $row, 'base_member_remove answers the shape, record_id the share row');
$admin = ['X-Action-Token: ' . person_token(AVERY)];
foreach (['settings_save' => ['/admin/settings/save.php', ['max_evidence' => 8]], 'limits_save' => ['/admin/settings/limits.php', ['key_rate_per_minute' => 30]],
          'retention_set' => ['/admin/settings/retention.php', ['question_retention_days' => 365]]] as $action => [$path, $form]) {
    [$c, $b] = act_token($path, $form, $admin);
    ok($c === 200 && $shape($b), "$action answers the shape");
}
$since = last_activity_id();
[$c, $b] = act_token('/bases/save.php', ['base' => $id, '_partial' => '1', 'name' => 'SMOKE By Token Renamed'], $person);
$r = base_row_of($id);
ok($c === 200 && $r['name'] === 'SMOKE By Token Renamed' && $r['description'] === 'By token.' && $r['template'] === 'general' && $r['key'] === 'kb-' . $id, '_partial=1 on base_update keeps the untouched fields');
$u = activity('base.update', $since);
ok(count($u) === 1 && array_keys(json_decode($u[0]['after'], true)) === ['name'], '…and logs only the name');
[$c, $b] = act_token('/bases/save.php', ['base' => $id, 'name' => ''], $person);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($b['error']['fields']['name']), '422 {error: {code: invalid, fields}}');
[$c, $d] = screen(as_member(PRIYA), '/bases');
ok($c === 200 && isset($d['bases'][0]['base_id'], $d['bases'][0]['my_level']) && !isset($d['bases'][0]['i_belong']), 'the list answers JSON through the presenter (no raw columns)');
[$c, $d] = screen(as_member(PRIYA), '/bases/' . $id);
ok($c === 200 && (int) $d['base_id'] === $id && isset($d['overview']['sources'], $d['may'], $d['tabs']), 'the base answers JSON: the base, its overview, may and its tabs');
[$c, $d] = screen(as_member(PRIYA), '/bases/' . $id . '/share');
ok($c === 200 && isset($d['members'][0]['principal_kind']) && $d['levels'] === ['read', 'curate', 'own'], 'the share screen answers JSON');
[$c, $d] = screen(as_member(PRIYA), '/bases/new');
ok($c === 200 && $d['templates'][0]['template_key'] === 'general', 'the form answers JSON with the templates');

echo "   An agent\n";
$tok = run_token(SEAMUS, 4242);
$since = last_activity_id();
[$c, $b] = act_token('/bases/save.php', ['name' => 'SMOKE Seamus Base'], as_agent($tok));
$sid = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $sid > 0 && base_row_of($sid)['visibility'] === 'private' && (int) base_row_of($sid)['owner_member_id'] === SEAMUS, 'Seamus (run token + relay) creates a private base of his own');
$cr = activity('base.create', $since);
ok(count($cr) === 1 && $cr[0]['source'] === 'agent' && (int) $cr[0]['agent_run_id'] === 4242, 'logged with source agent and the run id');
[$c, $b] = act_token('/bases/members/add.php', ['base' => $sid, 'department' => DESIGN, 'level' => 'read'], as_agent($tok));
ok($c === 200 && $shape($b), 'and shares it with Design (free)');
$reg = json_decode(file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$cat = static fn (string $a): ?string => $reg['actions'][$a]['approval'] ?? null;
ok($cat('base_share_guest') === 'external_send' && $cat('base_share_application') === 'external_send' && $cat('base_visibility_set') === 'external_send' && $cat('base_delete') === 'deletion'
    && $cat('base_member_remove') === 'other' && $cat('settings_save') === 'other' && $cat('limits_save') === 'other' && $cat('retention_set') === 'other',
    'the registry lists the paused actions with their categories (the kernel\'s hook pauses them for an agent — Phase 4 proves the pause)');
ok($cat('base_share') === null && $cat('base_create') === null && $cat('base_update') === null && $cat('base_settings_save') === null && $cat('base_archive') === null && $cat('base_restore') === null,
    'and the free ones carry none');
$events = [];
foreach (['base_share', 'base_share_guest', 'base_share_application', 'settings_save', 'limits_save', 'retention_set'] as $a) { $events[] = $reg['actions'][$a]['log_event']; }
ok(count(array_unique($events)) === count($events), 'every paused action has a log event no free action shares: ' . implode(', ', $events));
$built = array_filter(['base_create', 'base_update', 'base_settings_save', 'base_visibility_set', 'base_share', 'base_share_guest', 'base_share_application', 'base_member_remove',
    'base_archive', 'base_restore', 'base_delete', 'settings_save', 'limits_save', 'retention_set'], static fn (string $a): bool => !empty($reg['actions'][$a]['built']));
$screens = array_filter(['base-list', 'base-add', 'base-view', 'base-edit', 'base-share', 'base-settings', 'admin-settings', 'templates'], static fn (string $s): bool => !empty($reg['screens'][$s]['built']));
ok(count($built) === 14 && count($screens) === 8, 'the registry reads 8 screens and 14 actions of this slice built (' . count($screens) . ', ' . count($built) . ')');
$watch = run_token(WATCHER, 4243);
$before = (int) one('SELECT count(*) FROM knowledge_bases');
[$c, $b] = act_token('/bases/save.php', ['name' => 'SMOKE Watcher Base'], as_agent($watch));
ok($c === 401 && (int) one('SELECT count(*) FROM knowledge_bases') === $before, 'the Watcher (no grant) is refused, nothing created (' . $c . ')');
ok((int) one("SELECT count(*) FROM members WHERE id = :m AND capability IS NOT NULL", ['m' => WATCHER]) === 0, '…and never admitted');
finish();
