<?php
/** Slice 9 proof — the admin index, and purge (home-admin.md "Proof": admin.php). */
require __DIR__ . '/lib.php';

$w = home_world();
$hb = $w['handbook'];
$owner = as_member(OWNER);

echo "1. The admin index\n";
$since = last_activity_id();
$h = page($owner, '/admin/')['body'];
$pages = ['admin-settings' => '/admin/settings', 'templates' => '/admin/templates', 'admin-model' => '/admin/model', 'admin-usage' => '/admin/usage', 'admin-bases' => '/admin/bases', 'admin-keys' => '/admin/keys',
          'admin-agents' => '/admin/agents', 'connections' => '/admin/connections', 'admin-proposals' => '/admin/proposals', 'dispatches' => '/admin/dispatches', 'admin-purge' => '/admin/purge'];
$bad = [];
foreach ($pages as $id => $url) {
    if (!str_contains($h, 'id="admin-page-' . $id . '"') || !str_contains($h, 'href="' . $url . '?back=%2Fadmin%2F"') || !str_contains($h, 'id="admin-page-' . $id . '-state"')) { $bad[] = $id; }
}
ok($bad === [], 'a card per page — slice 1\'s settings and templates, the model, usage, bases, keys, agents, purge, slice 7\'s connections, slice 8\'s proposals and dispatches — each linking to its page with a state line' . ($bad ? ': ' . implode(', ', $bad) : ''));
$rows = activity('screen.view', $since);
ok(count($rows) === 1 && $rows[0]['screen'] === 'admin' && !str_contains($h, 'admin-coming'), 'the index logs screen.view and is no longer the shell\'s placeholder');
// states: make something to say
pdo()->exec("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, base_id, status, detail, attempts, answered_at) VALUES ('base', $hb, " . EXPERT . ", 'propose_areas', 'chat', " . CARA . ", $hb, 'failed', 'x', 5, now())");
$k = make_key(CARA, $hb, null, 'SMOKE index key');
$k2 = make_key(CARA, $hb, null, 'SMOKE expiring key');
pdo()->exec("UPDATE mcp_access_tokens SET expires_at = now() + interval '1 day' WHERE id = $k2");
[$c, $d] = screen($owner, '/admin/');
$st = array_column($d['pages'], 'state', 'id');
ok($c === 200 && count($d['pages']) === 11 && preg_match('/^\d+ bases? · \d+ private$/', $st['admin-bases']) === 1 && preg_match('/^\d+ live keys? · 1 key expiring$/', $st['admin-keys']) === 1 && preg_match('/^\d+ dispatch(es)? failed$/', $st['dispatches']) === 1, 'JSON: eleven pages with their states — "' . $st['admin-bases'] . '", "' . $st['admin-keys'] . '", "' . $st['dispatches'] . '"');
ok(str_contains($st['admin-agents'], 'Expert: SMOKE Seamus'), 'the agents card names the expert (' . $st['admin-agents'] . ')');
foreach ([CARA => 'a Curator', PRIYA => 'a Member', REID => 'a Reader', ANN => 'a guest'] as $m => $who) {
    $r = page(as_member($m), '/admin/');
    ok($r['code'] === 403 && str_contains($r['body'], 'You may not change the application settings.'), "$who → 403 in words");
}
$r = page(as_member(AVERY), '/admin/');
ok($r['code'] === 200 && str_contains($r['body'], 'id="admin-page-admin-model"'), 'a second admin (the admin role, not the super-admin) opens the index');
$reg = json_decode(file_get_contents(APP_ROOT . '/mcp/action_registry.json'), true);
ok(($reg['actions']['model_config_save']['approval'] ?? '') === 'other' && ($reg['actions']['agents_settings_save']['approval'] ?? '') === 'other' && ($reg['actions']['question_purge']['approval'] ?? '') === 'deletion', 'the registry carries model_config_save and agents_settings_save as other, question_purge as deletion');
$man = json_decode(file_get_contents(APP_ROOT . '/maludb-os.json'), true);
$ap = array_column($man['approvals'], 'category', 'action');
ok(($ap['model_config_save'] ?? '') === 'other' && ($ap['agents_settings_save'] ?? '') === 'other' && ($ap['question_purge'] ?? '') === 'deletion', 'maludb-os.json approvals[] says the same');

echo "2. Purge\n";
$r = page($owner, '/admin/settings');
ok(str_contains($r['body'], 'id="admin-settings-purge-link"') && str_contains($r['body'], 'href="/admin/purge"'), 'slice 1\'s Retention form links to Purge now');
set_setting('question_retention_days', '30');
pdo()->exec('DELETE FROM questions');
$old = [];
foreach ([1, 2, 3] as $i) { $old[$i] = old_question($hb, PRIYA, "SMOKE old question $i " . SENTINEL, 45 * 86400); }
$fresh = old_question($hb, PRIYA, 'SMOKE fresh question', 3600);
$mid = old_question($hb, PRIYA, 'SMOKE ten day question', 10 * 86400);
$pinned = old_question($hb, PRIYA, 'SMOKE old pinned question', 60 * 86400);
$pin = (int) one('SELECT kn_pin_answer(:q, :m, :t)', ['q' => $pinned, 'm' => CARA, 't' => 'SMOKE purge pin']);
$sec = (int) one("SELECT id FROM sections WHERE base_id = :b AND status <> 'forgotten' ORDER BY id LIMIT 1", ['b' => $hb]);
pdo()->exec("INSERT INTO citations (question_id, rank, section_id, quote) VALUES ({$old[1]}, 1, $sec, 'a quote')");
pdo()->exec("INSERT INTO question_feedback (question_id, member_id, rating) VALUES ({$old[1]}, " . PRIYA . ", 'down')");
$r = page($owner, '/admin/purge');
ok($r['code'] === 200 && str_contains($r['body'], 'id="purge-confirm"') && str_contains($r['body'], '3 questions are older than 30 days') && str_contains($r['body'], 'id="admin-purge-btn"'), 'the page counts the questions older than 30 days first: 3 (not the pinned one)');
$r = page($owner, '/admin/purge?days=1');
ok(str_contains($r['body'], '4 questions are older than 1 days'), 'another N counts again (1 day: 4 — the fresh one is an hour old, the pinned one stays)');
[$c, $d] = screen($owner, '/admin/purge?days=30');
ok($c === 200 && $d['would_purge'] === 3 && $d['days'] === 30 && $d['retention_days'] === 30, 'JSON: would_purge 3');
[$c, $b] = act($owner, '/admin/purge.php', ['days' => 30]);
ok($c === 422 && isset(fields($b)['confirm']) && (int) one('SELECT count(*) FROM questions') === 6, 'a purge without confirm → 422 on confirm; nothing removed');
[$c, $b] = act($owner, '/admin/purge.php', ['days' => 0, 'confirm' => 'yes']);
[$c2, $b2] = act($owner, '/admin/purge.php', ['days' => 5000, 'confirm' => 'yes']);
ok($c === 422 && isset(fields($b)['days']) && $c2 === 422, 'days from 1 to 3,650 — 0 and 5,000 are refused on the field');
[$c, $b] = act(as_member(CARA), '/admin/purge.php', ['days' => 30, 'confirm' => 'yes']);
ok($c === 403, 'a Curator may not purge (retention.manage)');
$since = last_activity_id();
[$c, $b] = act($owner, '/admin/purge.php', ['days' => 30, 'confirm' => 'yes']);
ok($c === 200 && $b['purged'] === 3 && $b['record_id'] === 1 && $b['location'] === '/admin/purge?days=30&purged=3' && ($b['ok'] ?? false) === true, 'purge → 200 {ok, did, record_id, location, purged: 3} ("' . ($b['did'] ?? '') . '")');
ok((int) one('SELECT count(*) FROM questions WHERE id IN (' . implode(',', $old) . ')') === 0 && (int) one("SELECT count(*) FROM questions WHERE id IN ($fresh, $mid, $pinned)") === 3, 'the three old questions are gone; the fresh one, the ten-day one and the pinned one stay');
ok((int) one("SELECT count(*) FROM citations WHERE question_id = {$old[1]}") === 0 && (int) one("SELECT count(*) FROM question_feedback WHERE question_id = {$old[1]}") === 0, 'their citations and feedback went with them');
ok((string) one('SELECT answer_md FROM pinned_answers WHERE id = ' . $pin) !== '' && (string) one('SELECT status FROM pinned_answers WHERE id = ' . $pin) === 'active', 'the pin keeps its words');
$ev = activity('question.purge', $since);
$a = after_of($ev[0] ?? ['after' => '{}']);
ok(count($ev) === 1 && $a['purged'] === 3 && $a['older_than_days'] === 30 && $ev[0]['source'] === 'web' && !in_log(SENTINEL), 'question.purge logged with the count and the days — never a question\'s words');
$h = page($owner, '/admin/purge?days=30&purged=3&notice=purged')['body'];
ok(str_contains($h, 'Purged 3 questions.') && str_contains($h, 'No question is older than 30 days.') && !str_contains($h, 'id="admin-purge-btn"'), 'the landing says how many went, and there is nothing left to purge');
set_setting('question_retention_days', '365');
finish();
