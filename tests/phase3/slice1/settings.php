<?php
/** Slice 1 proof — a base's settings and the admin's three forms (bases-core.md "Proof", bullet 5). */
require __DIR__ . '/lib.php';

echo "5. Settings\n";
$priya = as_member(PRIYA);
$marco = as_member(MARCO);
$avery = as_member(AVERY);
$id = make_base(PRIYA, 'SMOKE Settings Base');
act($priya, '/bases/members/add.php', ['base' => $id, 'member' => MARCO, 'level' => 'curate']);
$since = last_activity_id();
[$c, $b] = act($marco, '/bases/settings-save.php', ['base' => $id, 'auto_extract' => 'no', 'sensitivity' => 'confidential', 'language' => 'pt-BR']);
$row = base_row_of($id);
ok($c === 200 && $row['auto_extract'] === false && $row['sensitivity'] === 'confidential' && $row['language'] === 'pt-BR', 'a curator sets auto-extract, sensitivity, language');
$u = activity('base.update', $since);
$after = json_decode(end($u)['after'], true);
ok(count($u) === 1 && $after['form'] === 'settings' && $after['sensitivity'] === 'confidential' && $after['auto_extract'] === false && (int) $u[0]['base_id'] === $id, 'logged base.update with after.form = settings and the diff');
[$c, $b] = act($marco, '/bases/settings-save.php', ['base' => $id, 'monthly_text_mb_limit' => 99]);
ok($c === 403 && msg($b) === 'You may not set the limits.' && (int) base_row_of($id)['monthly_text_mb_limit'] === 50, 'a curator posting the limit → 403 "You may not set the limits."');
[$c, $b] = act($avery, '/bases/settings-save.php', ['base' => $id, 'monthly_text_mb_limit' => 10]);
ok($c === 200 && (int) base_row_of($id)['monthly_text_mb_limit'] === 10, 'Avery (limits.manage) sets it to 10');
[$c, $b] = act($marco, '/bases/settings-save.php', ['base' => $id, 'language' => 'English!']);
ok($c === 422 && (fields($b)['language'] ?? '') === 'The language is an ISO code such as en, fr or pt-BR.', 'an invalid language → the CHECK, on its field');
[$c, $b] = act($marco, '/bases/settings-save.php', ['base' => $id, 'sensitivity' => 'secret']);
ok($c === 422 && isset(fields($b)['sensitivity']), 'an unknown sensitivity → its field');
$s = page($marco, '/bases/' . $id . '/settings');
ok($s['code'] === 200 && str_contains($s['body'], 'The admin sets it.') && !preg_match('/<input[^>]*name="monthly_text_mb_limit"/', $s['body']) && str_contains($s['body'], 'kb:' . $id), 'the curator\'s screen shows the limit read-only and the namespace');
$s = page($avery, '/bases/' . $id . '/settings');
ok(preg_match('/<input[^>]*name="monthly_text_mb_limit"/', $s['body']) === 1, 'the admin\'s screen lets her edit the limit');
$r = page(as_member(BEA), '/bases/' . $id . '/settings');
ok($r['code'] === 404, 'Bea (no share) cannot open the base\'s settings');

echo "   The admin's three forms\n";
$before = q('SELECT * FROM kn_settings')[0];
$since = last_activity_id();
[$c, $b] = act($avery, '/admin/settings/save.php', ['business_name' => 'SMOKE Business', 'max_evidence' => 12, 'evidence_threshold' => '0.55', 'url_fetch_allowed' => 'no', 'timezone' => 'America/Chicago',
    'default_monthly_text_mb' => 999, 'question_retention_days' => 5]);
$after = q('SELECT * FROM kn_settings')[0];
ok($c === 200 && $after['business_name'] === 'SMOKE Business' && (int) $after['max_evidence'] === 12 && (float) $after['evidence_threshold'] === 0.55 && $after['url_fetch_allowed'] === false && $after['timezone'] === 'America/Chicago',
    'Settings saves its own columns');
ok((int) $after['default_monthly_text_mb'] === (int) $before['default_monthly_text_mb'] && (int) $after['question_retention_days'] === (int) $before['question_retention_days'], '…and never a Limits or a Retention column sent with it');
$log = activity('settings.save', $since);
$la = json_decode($log[0]['after'] ?? '{}', true);
ok(count($log) === 1 && $la['form'] === 'settings' && $la['max_evidence'] === 12 && !array_key_exists('default_monthly_text_mb', $la), 'settings.save logged with the form and the changed fields');
[$c, $b] = act($avery, '/admin/settings/limits.php', ['default_monthly_text_mb' => 75, 'max_source_mb' => 20, 'url_fetch_max_kb' => 512, 'key_rate_per_minute' => 40, 'max_evidence' => 3]);
$after = q('SELECT * FROM kn_settings')[0];
ok($c === 200 && (int) $after['default_monthly_text_mb'] === 75 && (int) $after['max_source_bytes'] === 20 * 1048576 && (int) $after['url_fetch_max_bytes'] === 512 * 1024 && (int) $after['key_rate_per_minute'] === 40 && (int) $after['max_evidence'] === 12,
    'Limits saves its own columns (MB and KB stored as bytes), not max_evidence');
ok(count(activity('limits.save', $since)) === 1, 'limits.save logged');
[$c, $b] = act($avery, '/admin/settings/limits.php', ['max_source_bytes' => 3145728]);
ok($c === 200 && (int) q('SELECT max_source_bytes FROM kn_settings')[0]['max_source_bytes'] === 3145728, 'an agent may send the column itself, in bytes');
[$c, $b] = act($avery, '/admin/settings/retention.php', ['question_retention_days' => 400]);
ok($c === 200 && (int) q('SELECT question_retention_days FROM kn_settings')[0]['question_retention_days'] === 400 && count(activity('retention.set', $since)) === 1, 'Retention saves and logs retention.set');
[$c, $b] = act($avery, '/admin/settings/save.php', ['section_target_chars' => 5000, 'section_max_chars' => 4000]);
ok($c === 422 && (fields($b)['section_max_chars'] ?? '') === 'Section maximum must be at least the target.', 'section maximum below the target → the CHECK\'s sentence');
[$c, $b] = act($avery, '/admin/settings/save.php', ['max_evidence' => 80]);
ok($c === 422 && (fields($b)['max_evidence'] ?? '') === 'Evidence per answer is from 1 to 50.', 'a value outside its CHECK → that field with its bounds (' . json_encode(fields($b)) . ')');
[$c, $b] = act($avery, '/admin/settings/retention.php', ['question_retention_days' => 'soon']);
ok($c === 422 && isset(fields($b)['question_retention_days']), 'a non-number → its field');
[$c, $b] = act($avery, '/admin/settings/save.php', ['timezone' => 'Mars/Olympus']);
ok($c === 422 && isset(fields($b)['timezone']), 'an unknown time zone → its field');
foreach (['/admin/settings/save.php' => ['max_evidence' => 9], '/admin/settings/limits.php' => ['key_rate_per_day' => 9], '/admin/settings/retention.php' => ['question_retention_days' => 9]] as $path => $form) {
    [$c, $b] = act($marco, $path, $form);
    ok($c === 403, "Marco → 403 on $path (" . msg($b) . ')');
}
ok(page($marco, '/admin/settings')['code'] === 403, 'and on the page');
$pg = page($avery, '/admin/settings');
ok($pg['code'] === 200 && str_contains($pg['body'], 'id="admin-settings-settings"') && str_contains($pg['body'], 'id="admin-settings-limits"') && str_contains($pg['body'], 'id="admin-settings-retention"')
    && str_contains($pg['body'], 'From 1 to 50'), 'the admin page: three forms, each field with its bounds');
pdo()->exec("UPDATE kn_settings SET model_alias = 'smoke-model'");
pdo()->exec("SELECT set_config('app.member_id', '" . MARCO . "', false)");
$ms = q('SELECT model_alias, embedding_model, max_evidence FROM mcp_settings')[0];
pdo()->exec("SELECT set_config('app.member_id', '" . AVERY . "', false)");
$as = q('SELECT model_alias FROM mcp_settings')[0];
ok($ms['model_alias'] === null && (int) $ms['max_evidence'] === 12 && $as['model_alias'] === 'smoke-model', 'mcp_settings hides the model columns from Marco and shows them to the admin');
$t = page($avery, '/admin/templates');
ok($t['code'] === 200 && str_contains($t['body'], 'id="template-card-general"') && str_contains($t['body'], 'What to extract'), 'the templates screen shows each template with its shipped guidelines');
ok(page($marco, '/admin/templates')['code'] === 403, 'a curator cannot open the templates screen');
finish();
