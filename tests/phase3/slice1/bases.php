<?php
/** Slice 1 proof — the first run, update and template (bases-core.md "Proof", bullets 1 and 2). */
require __DIR__ . '/lib.php';

echo "1. The first run\n";
$priya = as_member(PRIYA);
$home = page($priya, '/');
ok(str_contains($home['body'], 'id="home-first-run"') && substr_count($home['body'], 'id="home-create-base-btn"') === 1, "Priya's home shows one button, Create a knowledge base");
$form = page($priya, '/bases/new');
ok($form['code'] === 200 && str_contains($form['body'], 'id="base-add-form"') && str_contains($form['body'], 'id="template-card-general"')
    && preg_match('/id="template-card-general-radio"[^>]*checked/', $form['body']) === 1 && str_contains($form['body'], 'autofocus'), 'the first-run form: a name (autofocus) and the templates as cards, General chosen');
ok(preg_match('#<details[^>]*id="base-form-more"#', $form['body']) === 1, '"More" holds the description and the key');
$since = last_activity_id();
[$code, $b] = act($priya, '/bases/save.php', ['name' => 'SMOKE Field Manuals', 'template' => 'general']);
$id = (int) ($b['record_id'] ?? 0);
ok($code === 200 && $id > 0 && ($b['location'] ?? '') === '/bases/' . $id, "base_create answers record_id and a location ending in it ({$code}, " . ($b['location'] ?? '') . ')');
$row = base_row_of($id);
ok($row['key'] === 'kb-' . $id && $row['namespace'] === 'kb:' . $id && $row['visibility'] === 'private' && $row['status'] === 'empty' && (int) $row['monthly_text_mb_limit'] === 50,
    "the row: key kb-{$id}, namespace kb:{$id}, private, empty, limit 50");
$g = q("SELECT kind, origin FROM guidelines WHERE base_id = :b ORDER BY position", ['b' => $id]);
ok(array_column($g, 'kind') === ['extraction', 'exclusion', 'answering', 'audience'] && array_unique(array_column($g, 'origin')) === ['template'], 'the four shipped guidelines copied with origin template');
$log = activity('base.create', $since);
ok(count($log) === 1 && (int) $log[0]['base_id'] === $id && json_decode($log[0]['after'], true)['template'] === 'general', 'base.create logged with base_id');
pdo()->exec("SELECT set_config('app.member_id', '" . PRIYA . "', false)");
ok(one('SELECT kn_base_level(:b)', ['b' => $id]) === 'own', "Priya's level is own");
$view = page($priya, '/bases/' . $id . '?notice=created');
$addAt = strpos($view['body'], 'id="base-add-documents"');
ok($view['code'] === 200 && $addAt !== false && $addAt < strpos($view['body'], 'id="base-ask-box"') && $addAt < strpos($view['body'], 'id="base-view-about"'), 'the base lands with Add documents first');
ok(str_contains($view['body'], 'href="/sources/new?base=' . $id . '"'), 'Add documents goes to /sources/new?base= (slice 2)');
foreach ([REID => 'Reid (Reader)', ANN => 'Ann (a guest)'] as $m => $who) {
    [$c, $body] = act(as_member($m), '/bases/save.php', ['name' => 'SMOKE not mine']);
    ok($c === 403 && msg($body) === 'You may not create a knowledge base.', "$who cannot create: 403 in words ($c " . msg($body) . ')');
}
$r = page(as_member(REID), '/bases/new');
ok($r['code'] === 403, 'and the form refuses them too');

echo "2. Update and template\n";
[$c, $body] = act($priya, '/bases/save.php', ['base' => $id, 'name' => 'SMOKE Field Manuals 2', 'key' => 'field-manuals', 'description' => "Line one.\nLine two."]);
$row = base_row_of($id);
ok($c === 200 && $row['name'] === 'SMOKE Field Manuals 2' && $row['key'] === 'field-manuals', 'rename and a key field-manuals');
$u = activity('base.update', $since);
$after = json_decode(end($u)['after'], true);
ok(isset($after['description_chars']) && $after['description_chars'] === 19 && !str_contains(end($u)['after'], 'Line one'), 'the description is logged as description_chars only');
$other = make_base(PRIYA, 'SMOKE Second');
[$c, $body] = act($priya, '/bases/save.php', ['base' => $other, 'key' => 'field-manuals']);
ok($c === 422 && (fields($body)['key'] ?? '') === 'Another base already uses the key field-manuals.', 'a second base taking the same key → the key field\'s sentence (' . json_encode(fields($body)) . ')');
[$c, $body] = act($priya, '/bases/save.php', ['base' => $other, 'key' => 'deleting-x']);
ok($c === 422 && isset(fields($body)['key']) && str_contains(fields($body)['key'], 'deleting-'), 'a key deleting-x is refused');
[$c, $body] = act($priya, '/bases/save.php', ['base' => $other, 'key' => 'Bad Key!']);
ok($c === 422 && isset(fields($body)['key']), 'a key outside the pattern is refused on the key field (the CHECK)');
// a second template, inserted by the test
pdo()->exec("INSERT INTO base_templates (key, name, description, version, guidelines, area_rule, sort_order) VALUES ('smoke_policies', 'SMOKE Policies', 'A test template.', 1,
   jsonb_build_array(jsonb_build_object('kind', 'extraction', 'title', 'Policies', 'body', 'Extract each policy and its owner.'),
                     jsonb_build_object('kind', 'answering', 'title', 'Quote', 'body', 'Quote the policy.')), 'none', 2) ON CONFLICT (key) DO NOTHING");
// edit one shipped guideline of Priya's base: it must survive the switch
pdo()->exec("SELECT set_config('app.member_id', '" . PRIYA . "', false)");
pdo()->exec("UPDATE guidelines SET body = body || ' Edited.' WHERE base_id = $id AND kind = 'audience'");
$since2 = last_activity_id();
[$c, $body] = act($priya, '/bases/save.php', ['base' => $id, 'template' => 'smoke_policies']);
$g = q('SELECT kind, origin, version_no, title FROM guidelines WHERE base_id = :b ORDER BY kind, title', ['b' => $id]);
$titles = array_map(static fn ($r) => $r['kind'] . ':' . $r['title'], $g);
ok($c === 200 && base_row_of($id)['template'] === 'smoke_policies', 'switching the empty base\'s template saves');
ok(in_array('audience:Who is asking', $titles, true) && in_array('extraction:Policies', $titles, true) && in_array('answering:Quote', $titles, true)
    && !in_array('extraction:What to extract', $titles, true) && !in_array('exclusion:What to ignore', $titles, true), 'the untouched shipped rows went, the edited one stayed, the new set arrived: ' . implode(', ', $titles));
$u = activity('base.update', $since2);
$after = json_decode(end($u)['after'], true);
ok(($after['template_switch'] ?? null) == ['removed' => 3, 'added' => 2] && ($after['template'] ?? '') === 'smoke_policies', 'logged with template_switch {removed 3, added 2}');
$src = mksource($id);
[$c, $body] = act($priya, '/bases/save.php', ['base' => $id, 'template' => 'general']);
ok($c === 422 && (fields($body)['template'] ?? '') === 'A base may switch template only while it is empty.', 'with a source present the switch → the trigger\'s sentence on the template field (' . json_encode(fields($body)) . ')');
$edit = page($priya, '/bases/' . $id . '/edit');
ok(preg_match('/id="base-form-field-template"[^>]*disabled/', $edit['body']) === 1 && str_contains($edit['body'], 'A base switches template only while it is empty'), 'the edit form disables the template with the sentence');
pdo()->exec("DELETE FROM sources WHERE id = $src");
ok(right_of(PRIYA, 'base.manage') === false, 'Priya holds Member only (no base.manage)…');
[$c, $body] = act($priya, '/bases/save.php', ['base' => $id, 'description' => 'Hers to change.']);
ok($c === 200 && base_row_of($id)['description'] === 'Hers to change.', '…and still updates her own base');
[$c, $body] = act(as_member(BEA), '/bases/save.php', ['base' => $id, 'name' => 'SMOKE taken']);
ok($c === 404 && msg($body) === 'Knowledge base not found.', 'Bea, with no share, gets 404');
pdo()->exec("INSERT INTO base_members (base_id, principal_kind, principal_id, level, granted_by) VALUES ($id, 'member', " . REID . ", 'read', " . PRIYA . ')');
[$c, $body] = act(as_member(REID), '/bases/save.php', ['base' => $id, 'name' => 'SMOKE taken']);
ok($c === 403 && msg($body) === 'You may not change this knowledge base.', 'Reid with a read share gets 403 in words');
[$c, $body] = act($priya, '/bases/save.php', ['base' => $id, 'name' => '']);
ok($c === 422 && isset(fields($body)['name']), 'an empty name is refused on the name field');
finish();
