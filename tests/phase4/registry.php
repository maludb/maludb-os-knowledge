<?php
/**
 * Proof — the registry wrapper and the manifest, against the KERNEL's own code (read only): deploy/kernel-registry-knowledge.json + mcp/action_registry.json, composed as the installer writes mcp/registries/knowledge.json,
 * are loaded by the kernel's loader (application_actions.load_registries) and registered on a FastMCP exactly as the kernel's actions server does (application_actions.register): a tool per built action, approval categories
 * the kernel knows, the `confirmed` guard on destructive ones; every resolve entity is answered by THIS application's live records server through the kernel's own resolver (application_actions.resolve_via_mcp) with the
 * caller's token; and whole actions are driven through the kernel's tool (the approval hook and the handler stubbed — nothing is written): an agent's key_mint is a paused action. Then maludb-os.json: the agents' grants name
 * only tools and actions that exist, and the Archivist holds no action that mints, deletes or shares.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$S = surface();
$root = dirname(__DIR__, 2);
$wrapper = json_decode((string) file_get_contents($root . '/deploy/kernel-registry-knowledge.json'), true);
$reg = json_decode((string) file_get_contents($root . '/mcp/action_registry.json'), true);
$manifest = json_decode((string) file_get_contents($root . '/maludb-os.json'), true);
$actions = $reg['actions'];
$KNOWN_CATEGORIES = ['money_out', 'deletion', 'external_send', 'other'];
$a = $w['ask'];
$hb = $a['handbook'];
$k = $w['keys'];

echo "1. The wrapper, the registry and the manifest read right\n";
ok(($wrapper['schema'] ?? '') === 'maludb-os.registry/1' && $wrapper['app_key'] === 'knowledge' && count($wrapper['resolve']) === 13, 'the wrapper: schema maludb-os.registry/1, app_key knowledge, thirteen resolve entities');
$php = (int) shell_exec('cd ' . escapeshellarg($root) . ' && php bin/build_action_registry.php --check >/dev/null 2>&1; echo $?');
ok($php === 0, 'the registry and maludb-os.json approvals[] are current with the action manifest (bin/build_action_registry.php --check)');
ok(count($actions) === 62 && count(array_filter($actions, fn ($x) => !empty($x['built']))) === 62, 'the registry holds 62 actions, every one built');
$cats = array_unique(array_filter(array_column($actions, 'approval')));
$withCat = count(array_filter($actions, fn ($x) => $x['approval'] !== null));
ok(count(array_diff($cats, $KNOWN_CATEGORIES)) === 0 && $withCat === 20, 'every approval category is one the kernel knows (' . implode(', ', $cats) . '); ' . $withCat . ' actions carry one');
$missing = [];
foreach ($actions as $key => $x) { $p = $root . '/html' . parse_url($x['endpoint'], PHP_URL_PATH); if (!is_file($p) && !is_file(preg_replace('/\{[a-z_]+\}/', '1', $p))) { $missing[] = $key . ' → ' . $x['endpoint']; } }
ok($missing === [], 'every action\'s endpoint exists under html/' . ($missing ? ' — missing: ' . implode(', ', array_slice($missing, 0, 5)) : ''));
$phantom = [];
foreach ($actions as $key => $x) { foreach ($x['params'] as $p) { if (($p['name'] ?? null) === null) { $phantom[] = $key; } } }
ok($phantom === [], 'no action carries a phantom parameter');
ok(array_filter($actions, fn ($x) => empty($x['log_event'])) === [], 'every action names the log event the approval hook asks about');

echo "2. Every resolve entity: its tool exists on the records server and answers the plain list\n";
foreach ($wrapper['resolve'] as $entity => $spec) {
    ok(in_array($spec['tool'], $S['records'], true) && ($spec['query_param'] ?? '') === 'q' && !empty($spec['id_field']) && !empty($spec['label_field']) && !empty($spec['params']), "$entity: tool {$spec['tool']} is on the records server; id {$spec['id_field']}, label {$spec['label_field']}, params " . implode(', ', $spec['params']));
}
$pm = [];
foreach ($wrapper['resolve'] as $e => $spec) { foreach ($spec['params'] as $p) { $pm[$p][] = $e; } }
ok(count(array_filter($pm, fn ($e) => count($e) > 1)) === 0, 'no param is claimed by two entities');
$idsOnly = ['section', 'build', 'base_member', 'dispatch', 'notification', 'token', 'subject', 'subject_id', 'citation', 'note', 'attachment', 'community', 'key'];
ok(count(array_intersect($idsOnly, array_keys($pm))) === 0, 'the ids-only params (section, build, dispatch, notification, token, subject …) and the base\'s own `key` are never resolved by name');
$used = [];
foreach ($actions as $x) { foreach ($x['params'] as $p) { if (isset($pm[$p['name']])) { $used[$p['name']] = true; } } }
ok(count($used) >= 10, 'the resolved params are really used by actions (' . count($used) . ' distinct names)');
$resolve = [
    ['base', 'SMOKE Handbook', ptoken(MARCO)], ['base', 'smoke-manuals', ptoken(MARCO)], ['focus_area', 'Leave', ptoken(MARCO)], ['source', 'Staff handbook', ptoken(MARCO)], ['guideline', 'SMOKE Answer briefly', ptoken(MARCO)],
    ['term', 'PTO', ptoken(MARCO)], ['question', 'p4 Do unicorn leave days exist?', ptoken(PRIYA)], ['pinned_answer', 'p4 Carry-over', ptoken(MARCO)], ['api_key', 'p4 manuals key', ptoken(MARCO)],
    ['proposal', 'Add a source on unicorn', ptoken(MARCO)], ['feed', 'spaces.pages_index', ptoken(MARCO)], ['member', 'SMOKE Reid', ptoken(MARCO)], ['department', 'Engineering', ptoken(MARCO)], ['template', 'general', ptoken(MARCO)],
];
$drive = [
    ['base_share', ['base' => 'SMOKE Handbook', 'member' => 'SMOKE Reid', 'level' => 'read'], ptoken(MARCO)],
    ['key_mint', ['base' => 'SMOKE Manuals', 'label' => 'p4 never minted', 'confirmed' => true], ptoken(MARCO), 'pending_approval'],
    ['key_mint', ['base' => 'SMOKE Manuals', 'label' => 'p4 never minted either'], ptoken(MARCO)],
    ['source_remove', ['source' => 'Staff handbook', 'confirmed' => true], ptoken(MARCO), 'pending_approval'],
    ['base_share_application', ['base' => 'smoke-manuals', 'application' => 'helpdesk', 'confirmed' => true], ptoken(MARCO), 'pending_approval'],
    ['guideline_save', ['base' => 'SMOKE Handbook', 'kind' => 'answering', 'title' => 'p4 never saved', 'body' => 'Answer in one sentence.'], ptoken(MARCO)],
    ['focus_area_propose', ['base' => 'SMOKE Handbook', 'name' => 'p4 never proposed', 'proposal_note' => 'Two questions asked.', 'seed_terms' => 'leave, carry-over'], ptoken(MARCO)],
    ['archivist_propose', ['base' => 'SMOKE Handbook', 'kind' => 'gap', 'title' => 'p4 never proposed gap', 'reason' => 'Because.'], ptoken(MARCO)],
    ['answer_pin', ['question' => 'p4 Do unicorn leave days exist?', 'title' => 'p4 never pinned'], ptoken(PRIYA)],
    ['build_start', ['base' => 'no such base at all'], ptoken(MARCO)],
    ['base_share', ['base' => 'SMOKE Handbook', 'member' => 'SMOKE'], ptoken(MARCO)],
];
$kpy = '/var/www/mcp/venv/bin/python';
if (!is_executable($kpy)) { $kpy = trim((string) shell_exec('command -v python3')); }
$cmd = 'PYTHONDONTWRITEBYTECODE=1 ' . escapeshellarg($kpy) . ' -I ' . escapeshellarg(__DIR__ . '/registry_check.py') . ' ' . escapeshellarg($root) . ' ' . escapeshellarg('http://127.0.0.1:' . REC . '/mcp') . ' ' . escapeshellarg(json_encode(['resolve' => $resolve, 'drive' => $drive, 'env' => need('KN_DEV_ENV')])) . ' 2>/tmp/kn-registry-check.err';
$out = (string) shell_exec($cmd);
$kr = json_decode($out, true);
ok(is_array($kr), 'the kernel\'s loader and tool factory ran against the composed registry' . (is_array($kr) ? '' : ' — ' . substr((string) @file_get_contents('/tmp/kn-registry-check.err'), -600)));
if (!is_array($kr)) { finish(); }
ok($kr['loaded'] === ['knowledge'] && $kr['registered'] === 62, 'application_actions.load_registries() accepts it and register() adds 62 tools');
ok(count($kr['tools']) === 62 && count(array_diff(array_keys($actions), array_keys($kr['tools']))) === 0, 'one tool per action, by the action\'s name');
$noApp = [];
foreach ($actions as $key => $x) { if ($x['approval'] !== null && !str_contains($kr['tools'][$key]['description'], 'category: ' . $x['approval'])) { $noApp[] = $key; } }
ok($noApp === [], 'a tool\'s description states its approval category (' . $withCat . ' of them)');
$noConfirm = [];
foreach ($actions as $key => $x) { $txt = json_encode($kr['tools'][$key]['schema']); if (!empty($x['confirm']) && !str_contains($txt, 'confirmed')) { $noConfirm[] = $key; } }
ok($noConfirm === [], 'every destructive action carries the `confirmed` guard (' . count(array_filter($actions, fn ($x) => !empty($x['confirm']))) . ' of them)');
$noParam = [];
foreach ($actions as $key => $x) { $props = array_keys($kr['tools'][$key]['schema']['$defs'][array_key_first($kr['tools'][$key]['schema']['$defs'] ?? [])]['properties'] ?? []); foreach ($x['params'] as $p) { if (($p['name'] ?? null) && !in_array($p['name'], $props, true)) { $noParam[] = "$key.{$p['name']}"; } } }
ok($noParam === [], 'every manifest parameter is a field of its tool' . ($noParam ? ' — missing: ' . implode(', ', array_slice($noParam, 0, 5)) : ''));
echo "   resolving through the kernel's resolver, as the person who asks:\n";
foreach ($resolve as [$entity, $value]) {
    $r = $kr['resolved'][$entity . '|' . $value] ?? null;
    $spec = $wrapper['resolve'][$entity];
    ok($r !== null && !isset($r['error']) && $r['matches'] === 1 && $r['id'] !== null && $r['label'] !== null && in_array($spec['id_field'], $r['keys'], true) && in_array($spec['label_field'], $r['keys'], true),
        "resolver: $entity '" . substr((string) $value, 0, 28) . "' → 1 match" . ($r && isset($r['label']) ? ', ' . $spec['id_field'] . ' ' . substr((string) $r['id'], 0, 8) . ', "' . substr((string) $r['label'], 0, 40) . '"' : ' — ' . json_encode($r)));
}
$id = fn ($e, $v) => (string) $kr['resolved'][$e . '|' . $v]['id'];
$d = $kr['driven'];
echo "3. Whole actions through the kernel's tool\n";
ok(($d[0]['answer']['status'] ?? '') === 'success' && count($d[0]['posts']) === 1 && $d[0]['posts'][0]['path'] === '/bases/members/add.php' && $d[0]['posts'][0]['fields'] === ['base' => (string) $hb, 'member' => (string) REID, 'level' => 'read'],
    'base_share (no approval): the base and the person resolved by NAME, the level as given; the handler is posted the ids and the kernel\'s hook is not asked');
ok(($d[1]['answer']['status'] ?? '') === 'pending_approval' && count($d[1]['posts']) === 1 && $d[1]['posts'][0]['path'] === '/approvals/hook.php' && $d[1]['posts'][0]['fields']['action_key'] === 'key_mint' && $d[1]['posts'][0]['fields']['log_event'] === 'key.mint'
    && $d[1]['answer']['resolved']['base'] === 'SMOKE Manuals', 'AN AGENT\'S key_mint PAUSES: the kernel\'s hook is asked about key.mint (external_send) and answers pending_approval — nothing is posted to Knowledge, no key is minted');
ok(($d[2]['answer']['status'] ?? '') === 'needs_confirmation' && $d[2]['posts'] === [], 'key_mint without `confirmed`: needs_confirmation, nothing posted — not even the hook');
ok(($d[3]['answer']['status'] ?? '') === 'pending_approval' && count($d[3]['posts']) === 1 && $d[3]['posts'][0]['fields']['log_event'] === 'source.remove', 'source_remove (deletion) pauses too');
ok(($d[4]['answer']['status'] ?? '') === 'pending_approval' && $d[4]['posts'][0]['fields']['log_event'] === 'base.share_application' && count($d[4]['posts']) === 1, 'sharing a base with an application (external_send) pauses');
ok(($d[5]['answer']['status'] ?? '') === 'success' && $d[5]['posts'][0]['path'] === '/guidelines/save.php' && $d[5]['posts'][0]['fields']['base'] === (string) $hb && $d[5]['posts'][0]['fields']['kind'] === 'answering', 'guideline_save: the base resolved by name, the rest as given');
ok(($d[6]['answer']['status'] ?? '') === 'success' && $d[6]['posts'][0]['fields']['seed_terms[]'] === ['leave', 'carry-over'] && $d[6]['posts'][0]['fields']['base'] === (string) $hb, 'focus_area_propose: the repeated seed_terms are passed as given, never resolved');
ok(($d[7]['answer']['status'] ?? '') === 'success' && $d[7]['posts'][0]['path'] === '/proposals/save.php' && $d[7]['posts'][0]['fields']['kind'] === 'gap', 'archivist_propose: no approval, posted');
ok(($d[8]['answer']['status'] ?? '') === 'success' && $d[8]['posts'][0]['fields']['question'] === (string) $w['q_gap'], 'answer_pin: the question resolved from its words among Priya\'s own');
ok(($d[9]['answer']['status'] ?? '') === 'error' && str_contains($d[9]['answer']['message'], "No base matching 'no such base at all'") && $d[9]['posts'] === [], 'an unknown name: "No base matching …" and nothing posted');
ok(($d[10]['answer']['status'] ?? '') === 'error' && str_contains($d[10]['answer']['message'], 'Several match') && $d[10]['posts'] === [], 'an ambiguous name (SMOKE): the kernel lists the candidates and posts nothing');
$wrote = (int) one("SELECT count(*) FROM mcp_access_tokens WHERE label LIKE 'p4 never%'") + (int) one("SELECT count(*) FROM guidelines WHERE title = 'p4 never saved'") + (int) one("SELECT count(*) FROM focus_areas WHERE name = 'p4 never proposed'");
ok($wrote === 0, 'and nothing was written to the application: the handler posts were stubs');

echo "4. maludb-os.json is in step with what the servers offer\n";
$agents = array_column($manifest['agents'], null, 'key');
ok(array_keys($agents) === ['expert', 'archivist'], 'two agents hired on install: the expert and the Archivist');
$badTool = [];
foreach ($manifest['agents'] as $ag) {
    foreach ($ag['tool_grants']['Records MCP'] ?? [] as $t) { if (!in_array($t, $S['records'], true)) { $badTool[] = $ag['key'] . ' Records ' . $t; } }
    foreach ($ag['tool_grants']['Activity MCP'] ?? [] as $t) { if (!in_array($t, $S['activity'], true)) { $badTool[] = $ag['key'] . ' Activity ' . $t; } }
    foreach ($ag['tool_grants']['Actions MCP'] ?? [] as $t) { if (!isset($actions[$t])) { $badTool[] = $ag['key'] . ' Actions ' . $t; } }
}
ok($badTool === [], 'every tool an agent is granted exists: a records or activity tool of the surface, an action of the registry' . ($badTool ? ' — ' . implode(', ', $badTool) : ''));
ok(count(array_intersect(array_merge($agents['expert']['tool_grants']['Records MCP'], $agents['archivist']['tool_grants']['Records MCP']), $S['kernel_only'])) === 0, 'no agent is granted bases_index, the share that answers only as an application principal');
$arch = $agents['archivist']['tool_grants']['Actions MCP'];
$risky = array_values(array_filter($arch, fn ($t) => preg_match('/mint|delete|remove|share|revoke|rotate|purge|visibility|rebuild|limits|retention|model|settings|invite|guest/', $t) || ($actions[$t]['approval'] ?? null) !== null));
ok($arch === ['archivist_propose', 'notification'] && $risky === [], 'the Archivist holds two actions — archivist_propose and notification — and none that mints, deletes, shares or pauses');
$archRec = $agents['archivist']['tool_grants']['Records MCP'];
ok(!in_array('ask', $archRec, true) && !in_array('records_search', $archRec, true) && !in_array('extraction_preview', $archRec, true), 'its reads leave out ask, records_search and the extraction preview');
$weighty = [];
foreach ($agents['expert']['tool_grants']['Actions MCP'] as $t) {
    if (preg_match('/mint|share_guest|share_application|remove|delete|revoke|rotate|purge|visibility|limits|retention|model_config|settings_save/', $t) && ($actions[$t]['approval'] ?? null) === null) { $weighty[] = $t; }
}
ok($weighty === [], 'every action of the expert that mints, deletes or shares outside the business is a paused one (key_mint, source_remove, base_share_guest, base_share_application …)');
ok($actions['key_mint']['approval'] === 'external_send' && ($actions['key_mint']['confirm'] ?? false) === true && in_array('key_mint', $agents['expert']['tool_grants']['Actions MCP'], true), 'key_mint is in the expert\'s grants as an external_send action with a confirmation');
$shares = array_column($manifest['shares'], 'tool');
ok($shares === $S['shares'] && count(array_filter($manifest['shares'], fn ($s) => ($s['timeout_seconds'] ?? 0) === 60)) === 2, 'shares[] names exactly the three tools; ask and search declare the 60-second wait');
$appr = array_column($manifest['approvals'], 'category', 'action');
$bad = [];
foreach ($manifest['approvals'] as $x) { if (!isset($actions[$x['action']]) || $actions[$x['action']]['approval'] !== $x['category']) { $bad[] = $x['action']; } }
ok($bad === [] && count($appr) === $withCat, 'approvals[] is the manifest\'s ' . $withCat . ' pauses, each an action of the registry with that category');
foreach ($manifest['skills'] as $sk) { ok(is_file($root . '/' . $sk . '/SKILL.md'), "skill $sk has its SKILL.md"); }
foreach ($manifest['agents'] as $ag) { ok(is_file($root . '/' . $ag['job_description']), "the job description {$ag['job_description']} exists"); }
$known = array_merge($S['records'], $S['activity'], array_keys($actions), ['message_send', 'inbox_read', 'feed_stopped', 'stale_source', 'failed_source', 'review_pin', 'limit_near', 'url_changed']);   // the last six are the Archivist's proposal kinds
$unknown = [];
foreach (array_merge(glob($root . '/skills/*/SKILL.md'), glob($root . '/os/*.md')) as $f) {
    preg_match_all('/`([a-z]+(?:_[a-z]+)+)`/', (string) file_get_contents($f), $m);
    foreach (array_unique($m[1]) as $name) { if (!in_array($name, $known, true) && preg_match('/^(find|get|my|search|record|actor|recent|source|section|graph|god|subject|community|ask|base|build|guideline|vocabulary|focus|key|pin|question|archivist|agent|answer|unused|unassigned|empty|duplicate|extraction|suggested|activity|share|feed|notification|settings|limits)_/', $name)) { $unknown[] = basename(dirname($f)) . '/' . basename($f) . ': ' . $name; } }
}
ok($unknown === [], 'every tool or action a skill or a job description names exists' . ($unknown ? ' — ' . implode('; ', $unknown) : ''));
finish();
