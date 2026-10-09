<?php
/** Slice 3 proof — guidelines (guidelines-areas.md "Proof", bullet 2). */
require __DIR__ . '/lib.php';

echo "2. Guidelines\n";
$w = world();
$bid = $w['base'];
$priya = as_member(PRIYA);
ok(count($w['sections']) === 12 && base_version($bid) === 1 && base_row_of($bid)['status'] !== 'needs_rebuild', 'the world: twelve sections extracted at version 1 (real engine documents)');
$page = page($priya, '/bases/' . $bid . '/guidelines');
$ok = $page['code'] === 200;
foreach (['extraction', 'vocabulary', 'exclusion', 'answering', 'audience'] as $k) { $ok = $ok && str_contains($page['body'], 'id="guidelines-kind-' . $k . '"'); }
$shipped = q("SELECT id, kind FROM guidelines WHERE base_id = :b AND origin = 'template' ORDER BY position", ['b' => $bid]);
ok($ok && count($shipped) === 4 && str_contains($page['body'], 'id="guideline-card-' . $shipped[0]['id'] . '"'), 'the four shipped rows, grouped by the five kinds');
ok(str_contains($page['body'], 'id="built-under"') && str_contains($page['body'], '12 sections at v1'), 'the head says what the graph is built under ("12 sections at v1")');
ok(str_contains($page['body'], 'id="base-tab-guidelines"') && str_contains($page['body'], 'id="base-tab-areas"'), 'the Guidelines and Areas tabs are lit');
$since = last_activity_id();
$body = 'Extract every pump model, its seals and their service intervals. A seal is a part.';
[$c, $b] = act($priya, '/guidelines/save.php', ['base' => $bid, 'kind' => 'extraction', 'title' => 'SMOKE Pumps', 'body' => $body]);
$gid = (int) ($b['record_id'] ?? 0);
ok($c === 200 && base_version($bid) === 2 && stale_count($bid) === 12 && base_row_of($bid)['status'] === 'needs_rebuild', 'an extraction save → version 2, twelve sections stale, the base needs a rebuild');
ok((int) one('SELECT base_guideline_version FROM guideline_versions WHERE guideline_id = :g ORDER BY version_no DESC LIMIT 1', ['g' => $gid]) === 2, 'its version row: base_guideline_version 2');
ok(str_contains($b['did'] ?? '', 'version 2: 12 sections need a rebuild; the engine has the new guidelines'), 'the did says so: "' . ($b['did'] ?? '') . '"');
ok(count(activity('guideline.save', $since)) === 1 && count(activity('guideline.version', $since)) === 1 && count(activity('section.stale', $since)) === 1
    && json_decode(activity('section.stale', $since)[0]['after'], true)['count'] === 12, 'guideline.save, guideline.version and one section.stale (count 12) logged');
ok(!words_in_log('its seals and their service intervals'), 'no body in any row');
ok(kn_compile_engine_sha('kb:' . $bid) === kn_compile_prompt(pdo(), $bid)['sha256'] && str_contains(kn_compile_prompt(pdo(), $bid)['prompt_template'], 'EXTRACT: Extract every pump model'), 'the namespace\'s prompt re-sent: the engine holds the new hash');
ok(str_contains(page($priya, '/bases/' . $bid . '/guidelines')['body'], 'id="guidelines-needs-rebuild"'), 'the needs-a-rebuild box shows');
$since = last_activity_id();
[$c, $b] = act($priya, '/guidelines/save.php', ['guideline' => $gid, 'position' => 9]);
ok($c === 200 && base_version($bid) === 2 && (int) one('SELECT version_no FROM guidelines WHERE id = :g', ['g' => $gid]) === 1 && count(activity('guideline.version', $since)) === 0, 'a position-only change → no version');
$sha = kn_compile_engine_sha('kb:' . $bid);
$ans = guideline_of($bid, 'answering');
[$c, $b] = act($priya, '/guidelines/save.php', ['guideline' => $ans['id'], 'body' => 'Answer in one short paragraph, quoting the manual.']);
ok($c === 200 && base_version($bid) === 2 && ($b['did'] ?? '') === 'Saved — applies to the next question' && kn_compile_engine_sha('kb:' . $bid) === $sha, 'an answering save → version unchanged, nothing sent, "applies to the next question"');
ok(str_contains(page($priya, '/bases/' . $bid . '/guidelines')['body'], '>edited<'), 'a shipped row edited → the "edited" chip');
[$c, $b] = act($priya, '/guidelines/save.php', ['guideline' => $gid, 'is_active' => 'no']);
ok($c === 200 && !str_contains(kn_compile_prompt(pdo(), $bid)['prompt_template'], 'Extract every pump model') && base_version($bid) === 3, 'an extraction guideline made inactive is absent from the compiled prompt (and that shapes the graph: version 3)');
$ex = guideline_of($bid, 'exclusion');
[$c, $b] = act($priya, '/guidelines/delete.php', ['guideline' => $ex['id']]);
ok($c === 200 && base_version($bid) === 4 && (int) one('SELECT count(*) FROM guidelines WHERE id = :g', ['g' => $ex['id']]) === 0 && count(activity('guideline.delete')) === 1, 'delete of an exclusion → version 4, guideline.delete logged');
ok(!str_contains(kn_compile_prompt(pdo(), $bid)['prompt_template'], 'EXCLUDE:'), '…and the prompt has no EXCLUDE block');
[$c, $b] = act($priya, '/guidelines/save.php', ['base' => $bid, 'kind' => 'nonsense', 'body' => 'x']);
ok($c === 422 && isset(fields($b)['kind']), 'an unknown kind → its field');
[$c, $b] = act($priya, '/guidelines/save.php', ['base' => $bid, 'kind' => 'audience', 'body' => '']);
ok($c === 422 && isset(fields($b)['body']), 'an empty body → its field');
$v = page($priya, '/guidelines/' . $gid);
ok($v['code'] === 200 && str_contains($v['body'], 'id="guideline-version-1"') && str_contains($v['body'], 'id="guideline-version-2"'), 'the guideline\'s versions page lists both versions');
$r = page(as_member(REID), '/bases/' . $bid . '/guidelines');
ok($r['code'] === 403 && str_contains($r['body'], 'Guidelines and the vocabulary are the curators'), 'Reid (read) → 403 in words');
[$c, $b] = act(as_member(REID), '/guidelines/save.php', ['base' => $bid, 'kind' => 'audience', 'body' => 'Readers.']);
ok($c === 403, 'and may not save one');
ok(page(as_member(BEA), '/bases/' . $bid . '/guidelines')['code'] === 404 && page(as_member(BEA), '/guidelines/' . $gid)['code'] === 404, 'Bea → 404');
ok(page(as_member(REID), '/guidelines/' . $gid)['code'] === 403, 'Reid on a guideline\'s page → 403');
$cp = page($priya, '/bases/' . $bid . '/compiled');
ok($cp['code'] === 200 && str_contains($cp['body'], 'id="compiled-prompt"') && str_contains($cp['body'], 'id="compiled-system"') && str_contains($cp['body'], kn_compile_prompt(pdo(), $bid)['sha256']), 'the compiled view: the prompt, its hash, the answer system');
ok(str_contains(page(as_member(AVERY), '/bases/' . $bid . '/compiled')['body'], 'the engine has it'), 'the admin sees that the engine holds this prompt');
finish();
