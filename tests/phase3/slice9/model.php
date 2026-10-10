<?php
/** Slice 9 proof — the model page and model_config_save (home-admin.md "Proof": model.php). */
require __DIR__ . '/lib.php';

$w = home_world();
$hb = $w['handbook']; $pol = $w['policies']; $pv = $w['private']; $emp = $w['empty'];
pdo()->exec("UPDATE knowledge_bases SET archived_at = now() WHERE id = $emp");            // an archived base is sent nothing
$owner = as_member(OWNER);
$sec = q("SELECT id, text FROM sections WHERE base_id = :b AND status <> 'forgotten' ORDER BY id LIMIT 1", ['b' => $hb])[0];
$live = [$hb, $pol, $pv];

echo "1. The page\n";
model_world();
$h = page($owner, '/admin/model?base=' . $hb)['body'];
ok(str_contains($h, 'id="model-connection"') && str_contains($h, 'fake-model-1') && str_contains($h, 'fake-embed-1') && str_contains($h, 'http://127.0.0.1:9/v1') && str_contains($h, 'knowledge-ledger'), 'the business\'s connection from kb:0: provider, model id, embedding model, base URL');
ok(str_contains($h, 'knowledge-model-credential') && str_contains($h, 'the value is never shown') && !str_contains($h, need('MALUDB_KB_API_TOKEN')), 'the secret\'s NAME is shown, never a value (nor the engine token)');
ok(str_contains($h, 'id="model-health"') && str_contains($h, 'engine up') && str_contains($h, 'id="model-namespace"') && str_contains($h, 'Namespace kb:' . $hb), 'the engine\'s health, and what the chosen base\'s namespace holds');
ok(str_contains($h, 'K24 credential') && str_contains($h, 'priced in the OS\'s model registry') && str_contains($h, 'id="model-form"'), 'the form, with the K24 sentence');
[$c, $d] = screen($owner, '/admin/model?base=' . $hb);
ok($c === 200 && $d['connection']['ok'] === true && $d['connection']['config']['secret_name'] === 'knowledge-model-credential' && $d['health']['ok'] === true && $d['namespace']['base_id'] === $hb && !str_contains(json_encode($d), need('MALUDB_KB_API_TOKEN')), 'JSON: the connection, the health, the namespace — no secret value');
foreach ([CARA => 'a Curator', PRIYA => 'a Member'] as $m => $who) {
    ok(page(as_member($m), '/admin/model')['code'] === 403, "$who → 403 on the page");
}
[$c] = act(as_member(CARA), '/admin/model.php', ['model_alias' => 'x']);
ok($c === 403, '…and on the save');
ok(page(as_member(AVERY), '/admin/model')['code'] === 200, 'a second admin may open it (model.manage)');

echo "2. Saving a model id\n";
config_log_clear();
$since = last_activity_id();
[$c, $b] = act($owner, '/admin/model.php', ['model_alias' => 'fake-model-2']);
ok($c === 200 && ($b['ok'] ?? false) === true && $b['bases_sent'] === 3 && $b['bases_failed'] === 0 && $b['rebuild_marked'] === 0 && $b['record_id'] === 1 && $b['location'] === '/admin/model' && $b['refresh'] === 'settingsChanged', '200 {ok, did, record_id, location, refresh, bases_sent: 3, bases_failed: 0, rebuild_marked: 0} — "' . ($b['did'] ?? '') . '"');
$posts = config_posts();
$first = $posts[0]['body'] ?? [];
ok(count($posts) === 4 && ($first['namespace'] ?? '') === 'kb:0' && $first['alias']['model_identifier'] === 'fake-model-2' && $first['embedding_model'] === 'fake-embed-1', 'kb:0 first, with the new model id and the same embedding model; then one config call per live base (3) — the archived one is sent nothing');
ok(($first['secret_name'] ?? '') === 'knowledge-model-credential' && !array_key_exists('token', $first) && !str_contains(json_encode($posts), 'token'), 'the secret by name (secret_name), and no token anywhere in what was sent');
ok(($first['prompt_template'] ?? '') === 'REFERENCE PROMPT {{chunk}}', 'the reference\'s own prompt was kept');
$ns = array_map(static fn ($p) => $p['body']['namespace'], array_slice($posts, 1));
sort($ns);
$want = array_map(static fn ($i) => 'kb:' . $i, $live);
sort($want);
ok($ns === $want, 'the live bases were sent the connection: ' . implode(', ', $ns));
$cfg = kb_cfg();
ok($cfg['kb:' . $hb]['model_identifier'] === 'fake-model-2' && str_contains((string) $cfg['kb:' . $hb]['prompt_template'], 'kb:' . $hb) && !isset($cfg['kb:' . $emp]), 'a base\'s namespace now holds the new model and its own compiled prompt; the archived base\'s was not touched');
$s = settings_row();
ok($s['model_alias'] === 'fake-model-2' && $s['embedding_model'] === 'fake-embed-1', 'the choice is recorded in kn_settings (model_alias, embedding_model)');
$ev = activity('model_config.save', $since);
$a = after_of($ev[0] ?? ['after' => '{}']);
$bf = json_decode((string) ($ev[0]['before'] ?? '{}'), true);
ok(count($ev) === 1 && $a['model_alias'] === 'fake-model-2' && $a['bases_sent'] === 3 && $a['bases_failed'] === 0 && $a['rebuild_marked'] === 0 && array_key_exists('model_alias', $bf), 'model_config.save logged: before/after of the values, bases_sent, bases_failed, rebuild_marked');
ok(!in_log('knowledge-model-credential') && !in_log(need('MALUDB_KB_API_TOKEN')), 'no secret in the log');
$h = page($owner, '/admin/model')['body'];
ok(str_contains($h, 'fake-model-2') && str_contains($h, 'Last chosen here: fake-model-2'), 'the page shows the new model and the recorded choice');

echo "3. The engine refuses\n";
model_world(true, ['kb:0' => 'validation_failed']);
config_log_clear();
$since = last_activity_id();
[$c, $b] = act($owner, '/admin/model.php', ['model_alias' => 'fake-model-3']);
ok($c === 422 && fields($b)['model_alias'] === kn_engine_error_message('validation_failed'), 'the engine refusing kb:0 → 422 on the field, in the module\'s sentence ("' . (fields($b)['model_alias'] ?? '') . '")');
ok(count(config_posts()) === 1 && settings_row()['model_alias'] === 'fake-model-2' && activity('model_config.save', $since) === [], 'nothing else changed: no base was sent anything, the choice is not recorded, nothing logged');
model_world(true, ['kb:' . $pol => 'engine_error']);
config_log_clear();
[$c, $b] = act($owner, '/admin/model.php', ['model_alias' => 'fake-model-4']);
ok($c === 200 && $b['bases_sent'] === 2 && $b['bases_failed'] === 1 && $b['failed_base_ids'] === [$pol] && settings_row()['model_alias'] === 'fake-model-4', 'one base refusing: the save stands (recorded, 2 sent) and that base is named');
$h = page($owner, '/admin/model')['body'];
ok(str_contains($h, 'id="model-failed-' . $pol . '"') && str_contains($h, 'SMOKE Policies') && str_contains($h, kn_engine_error_message('engine_error')) && str_contains($h, 'sent again before that base\'s next build'), 'the page lists it with the module\'s sentence');
$h = page($owner, '/admin/model')['body'];
ok(!str_contains($h, 'id="model-failed-'), '…once');
$a = after_of(activity('model_config.save', $since)[0] ?? ['after' => '{}']);
ok(($a['bases_failed'] ?? null) === 1 && ($a['failed_base_ids'] ?? null) === [$pol], 'the log counts the failure and names the base id');

echo "4. kb:0 missing\n";
model_world(false);
$h = page($owner, '/admin/model')['body'];
ok(str_contains($h, 'id="model-not-configured"') && str_contains($h, 'deploy/ROOT_STEPS.sh') && str_contains($h, 'disabled'), 'model_not_configured in words — "the owner configures it once — deploy/ROOT_STEPS.sh" — and the save is disabled');
[$c, $d] = screen($owner, '/admin/model');
ok($d['connection']['ok'] === false && $d['connection']['code'] === 'model_not_configured', 'JSON: the code model_not_configured');
[$c, $b] = act($owner, '/admin/model.php', ['model_alias' => 'x']);
ok($c === 422 && str_contains(fields($b)['model_alias'] ?? '', 'ROOT_STEPS'), 'a save is refused in the same words');

echo "5. The embedding model\n";
model_world();
config_log_clear();
[$c, $b] = act($owner, '/admin/model.php', ['embedding_model' => 'fake-embed-2']);
ok($c === 422 && isset(fields($b)['rebuild_confirmed']) && str_contains(fields($b)['rebuild_confirmed'], 'must be rebuilt') && config_posts() === [], 'a new embedding model without the box → 422 on rebuild_confirmed; the engine was not touched');
[$c, $b] = act($owner, '/admin/model.php', ['model_alias' => '  ', 'embedding_model' => 'fake-embed-1']);
ok($c === 422 && isset(fields($b)['model_alias']), 'a blank model id → 422 on the field');
$ext = (int) one("SELECT count(*) FROM sections WHERE base_id = $hb AND status = 'extracted'");
$all = (int) one('SELECT count(*) FROM knowledge_bases');
[$c, $b] = act($owner, '/admin/model.php', ['embedding_model' => 'fake-embed-2', 'rebuild_confirmed' => 'yes']);
ok($c === 200 && $b['rebuild_marked'] === $all && $ext > 0, 'with the box: 200, every base marked (rebuild_marked ' . ($b['rebuild_marked'] ?? '?') . ' of ' . $all . ')');
ok((int) one("SELECT count(*) FROM sections WHERE base_id = $hb AND status = 'extracted'") === 0 && (int) one("SELECT count(*) FROM sections WHERE base_id = $hb AND status = 'stale'") >= $ext && (string) one("SELECT status FROM knowledge_bases WHERE id = $hb") === 'needs_rebuild', 'the sections of the Handbook are stale and the base needs a rebuild');
ok(settings_row()['embedding_model'] === 'fake-embed-2' && kb_cfg()['kb:0']['embedding_model'] === 'fake-embed-2', 'the new embedding model is recorded and written to kb:0');
$h = html_of(OWNER, '/');
ok(str_contains($h, 'id="home-base-' . $hb . '-stale"') && str_contains($h, 'needs a rebuild'), 'the home shows "needs a rebuild" on the base');
$a = after_of(activity('model_config.save', 0)[count(activity('model_config.save', 0)) - 1]);
ok($a['rebuild_marked'] === $all, 'the log carries rebuild_marked');

echo "6. Test: the prompt a section would be sent\n";
$since = last_activity_id();
$r = page($owner, '/admin/model?base=' . $hb . '&section=' . $sec['id']);
$out = '';
if (preg_match('#id="model-test-output">(.*?)</pre>#s', $r['body'], $m)) { $out = html_entity_decode($m[1]); }
ok($r['code'] === 200 && str_contains($out, 'KNOWLEDGE BASE kb:' . $hb) && str_contains($out, mb_substr($sec['text'], 0, 40)) && !str_contains($out, '{{chunk}}'), 'the base\'s compiled prompt with the section\'s text in place of {{chunk}}');
ok(str_contains($r['body'], 'This shows the prompt the model would be sent — MaluDB cannot yet run a dry extraction (M5).'), '…labelled with the M5 sentence');
ok(str_contains($r['body'], 'id="model-section"') && str_contains($r['body'], 'class="kn-preview-pre"'), 'the section is chosen from the base\'s own; the output uses the wrapping preview block');
ok(count(q('SELECT 1 FROM activity_log WHERE id > :s', ['s' => $since])) === 1 && count(activity('screen.view', $since)) === 1, 'a test writes nothing but the screen view');
$other = (int) one("SELECT id FROM sections WHERE base_id = $pol AND status <> 'forgotten' ORDER BY id LIMIT 1");
$r = page($owner, '/admin/model?base=' . $hb . '&section=' . $other);
ok(!str_contains($r['body'], 'id="model-test-output"'), 'a section of another base shows nothing');
[$c, $d] = screen($owner, '/admin/model?base=' . $hb . '&section=' . $sec['id']);
ok($c === 200 && str_contains($d['test']['text'], 'KNOWLEDGE BASE kb:' . $hb) && str_contains($d['note'], 'M5'), 'JSON carries the test and the note');
pdo()->exec("UPDATE knowledge_bases SET archived_at = NULL WHERE id = $emp");
model_world();
finish();
