<?php
/** Slice 3 proof — focus areas against the engine, assignment, who sees (guidelines-areas.md "Proof", bullets 5–7). */
require __DIR__ . '/lib.php';

echo "5. Areas, against the engine\n";
$w = world();
$bid = $w['base'];
$priya = as_member(PRIYA);
fake('/reset', 'POST');
$extractBefore = (int) (fake('/calls')['extract'] ?? -1);
[$c, $b] = act($priya, '/areas/save.php', ['base' => $bid, 'name' => 'SMOKE Warranty', 'description' => 'Coverage and claims for every pump model.', 'seed_terms' => "Zorblat Pump\nclaim", 'example_questions' => 'How long is the pump covered?']);
$area = (int) ($b['record_id'] ?? 0);
$row = q('SELECT * FROM focus_areas WHERE id = :a', ['a' => $area])[0] ?? [];
$pid = (int) ($row['maludb_project_id'] ?? 0);
$proj = kn_engine_project($pid);
$linked = array_map(static fn ($s) => $s['name'] ?? ($s['canonical_name'] ?? ''), $proj['data']['project']['subjects'] ?? []);
sort($linked);
ok($c === 200 && $pid > 0 && ($proj['data']['project']['name'] ?? '') === 'kb:' . $bid . '/SMOKE Warranty' && ($b['location'] ?? '') === '/areas/' . $area, 'SMOKE Warranty → a project kb:' . $bid . '/SMOKE Warranty (' . ($b['did'] ?? '') . ')');
ok(count($proj['data']['project']['subjects'] ?? []) === 2, 'both seed terms linked: ' . implode(', ', $linked));
ok((int) (fake('/calls')['extract'] ?? -2) === $extractBefore, 'the seed term "claim" was seeded without a model call');
$since = last_activity_id();
[$c, $b] = act($priya, '/areas/save.php', ['focus_area' => $area, 'name' => 'SMOKE Guarantee']);
$pid2 = (int) one('SELECT maludb_project_id FROM focus_areas WHERE id = :a', ['a' => $area]);
$old = kn_engine_project($pid);
$new = kn_engine_project($pid2);
ok($c === 200 && $pid2 !== $pid && !$old['ok'] && $old['status'] === 404 && ($new['data']['project']['name'] ?? '') === 'kb:' . $bid . '/SMOKE Guarantee' && count($new['data']['project']['subjects'] ?? []) === 2,
    'a rename → the old project gone (404), the new one with the same links');
ok(one('SELECT slug FROM focus_areas WHERE id = :a', ['a' => $area]) === 'smoke-guarantee', 'the slug follows the name');
[$c, $b] = act($priya, '/areas/save.php', ['focus_area' => $area, 'seed_terms' => 'Zorblat Pump']);
ok($c === 200 && count(kn_engine_project($pid2)['data']['project']['subjects'] ?? []) === 1, 'a seed term removed → unlinked');
[$c, $b] = act($priya, '/areas/save.php', ['base' => $bid, 'name' => 'smoke guarantee']);
ok($c === 422 && (fields($b)['name'] ?? '') === 'The base already has an area called smoke guarantee.', 'the same slug → the name field');
// a proposal
$tok = run_token(SEAMUS, 7301);
$notesBefore = ['priya' => count(notifications_of(PRIYA, 'proposal')), 'marco' => count(notifications_of(MARCO, 'proposal'))];
[$c, $b] = act_token('/areas/propose.php', ['base' => $bid, 'name' => 'SMOKE Returns', 'description' => 'Returns and refunds.', 'seed_terms' => 'return', 'proposal_note' => 'A cluster of 14 subjects about returns.'], as_agent($tok));
$ret = (int) ($b['record_id'] ?? 0);
$r = q('SELECT * FROM focus_areas WHERE id = :a', ['a' => $ret])[0] ?? [];
ok($c === 200 && $r['status'] === 'proposed' && $r['maludb_project_id'] === null && (int) $r['proposed_by'] === SEAMUS, 'Seamus proposes SMOKE Returns: proposed, no project');
ok(count(notifications_of(PRIYA, 'proposal')) === $notesBefore['priya'] + 1 && count(notifications_of(MARCO, 'proposal')) === $notesBefore['marco'] + 1, 'the curators are told once each');
$ap = (int) one("INSERT INTO archivist_proposals (base_id, kind, subject_type, subject_id, title, reason, proposed_by) VALUES (:b, 'area', 'focus_area', :a, 'SMOKE Returns', 'A cluster.', " . SEAMUS . ") RETURNING id", ['b' => $bid, 'a' => $ret]);
$ap2Area = (int) (act_token('/areas/propose.php', ['base' => $bid, 'name' => 'SMOKE Shipping', 'proposal_note' => 'Some subjects about shipping.'], as_agent($tok))[1]['record_id'] ?? 0);
$ap2 = (int) one("INSERT INTO archivist_proposals (base_id, kind, subject_type, subject_id, title, reason, proposed_by) VALUES (:b, 'area', 'focus_area', :a, 'SMOKE Shipping', 'A cluster.', " . SEAMUS . ") RETURNING id", ['b' => $bid, 'a' => $ap2Area]);
$page = page($priya, '/bases/' . $bid . '/areas')['body'];
ok(str_contains($page, 'id="areas-proposed"') && str_contains($page, 'id="proposal-card-' . $ret . '"'), 'the proposed cards show above');
[$c, $b] = act($priya, '/areas/accept.php', ['focus_area' => $ret]);
$r = q('SELECT * FROM focus_areas WHERE id = :a', ['a' => $ret])[0];
ok($c === 200 && $r['status'] === 'active' && $r['maludb_project_id'] !== null && (int) $r['decided_by'] === PRIYA, 'Accept → active, linked, the decision stamped');
ok(one('SELECT status FROM archivist_proposals WHERE id = :p', ['p' => $ap]) === 'accepted', 'its archivist proposal follows (accepted)');
[$c, $b] = act($priya, '/areas/dismiss.php', ['focus_area' => $ap2Area]);
ok($c === 200 && one('SELECT status FROM focus_areas WHERE id = :a', ['a' => $ap2Area]) === 'archived' && one('SELECT maludb_project_id FROM focus_areas WHERE id = :a', ['a' => $ap2Area]) === null
    && one('SELECT status FROM archivist_proposals WHERE id = :p', ['p' => $ap2]) === 'dismissed', 'another dismissed → archived, no project, its proposal dismissed');
[$c, $b] = act($priya, '/areas/accept.php', ['focus_area' => $ret]);
ok($c === 422 && msg($b) === 'That area is not a proposal.', 'accepting a decided area → 422 in words');
$retPid = (int) one('SELECT maludb_project_id FROM focus_areas WHERE id = :a', ['a' => $ret]);
[$c, $b] = act($priya, '/areas/delete.php', ['focus_area' => $ret]);
ok($c === 200 && (int) one('SELECT count(*) FROM focus_areas WHERE id = :a', ['a' => $ret]) === 0 && kn_engine_project($retPid)['status'] === 404 && count(activity('focus_area.delete')) === 1,
    'delete → the project\'s links and the project removed, then the row');

echo "6. Assignment\n";
$v = base_version($bid);
$srcs = array_map('intval', array_column(q("SELECT id FROM sources WHERE base_id = :b AND status <> 'forgotten' ORDER BY id", ['b' => $bid]), 'id'));
$since = last_activity_id();
foreach ($srcs as $s) {
    [$c, $b] = act($priya, '/sources/areas.php', ['source' => $s, 'areas' => [$area]]);
}
$as = activity('focus_area.assign', $since);
ok(count($srcs) === 3 && count($as) === 3 && array_sum(array_map(static fn ($r) => json_decode($r['after'], true)['added'], $as)) === 3, 'three sources assigned from source-view (added 3)');
ok(str_contains(page($priya, '/sources/' . $srcs[0])['body'], 'id="source-areas-form"'), 'the source page carries the areas form');
[$c, $b] = act($priya, '/areas/assign.php', ['focus_area' => $area, 'source' => $srcs[2], 'assigned' => 'no']);
ok($c === 200 && (int) one('SELECT count(*) FROM source_focus_areas WHERE focus_area_id = :a', ['a' => $area]) === 2, 'one removed from area-view');
$other = make_base(PRIYA, 'SMOKE Other Areas Base');
[, $ob] = act($priya, '/areas/save.php', ['base' => $other, 'name' => 'SMOKE Elsewhere']);
[$c, $b] = act($priya, '/sources/areas.php', ['source' => $srcs[0], 'areas' => [(int) $ob['record_id']]]);
ok($c === 422 && str_contains(msg($b), 'A source is assigned to an area of its own base'), 'another base\'s area → the trigger\'s words (' . msg($b) . ')');
$ap = page($priya, '/bases/' . $bid . '/areas')['body'];
ok(str_contains($ap, 'id="unassigned-source-' . $srcs[2] . '"') && !str_contains($ap, 'id="unassigned-source-' . $srcs[0] . '"'), 'the unassigned list follows');
[, $eb] = act($priya, '/areas/save.php', ['base' => $bid, 'name' => 'SMOKE Empty Area']);
ok(str_contains(page($priya, '/bases/' . $bid . '/areas')['body'], 'id="areas-empty"'), 'the empty list shows an area with no source');
$av = page($priya, '/areas/' . $area)['body'];
ok(str_contains($av, 'id="area-built-before"') && str_contains($av, '12 sections of these sources were built before they joined'), '"12 sections built before they joined"');
ok(base_version($bid) === $v, 'assignment is no version bump');
ok(kn_compile_projects(pdo(), $srcs[0]) === ['kb:' . $bid . '/SMOKE Guarantee'] && kn_compile_projects(pdo(), $srcs[2]) === [], 'kn_compile_projects(): the linked area\'s project for a source in it; none for a source in no area');
pdo()->exec("UPDATE focus_areas SET status = 'archived' WHERE id = $area");
ok(kn_compile_projects(pdo(), $srcs[0]) === [], '…and none once the area is archived');
pdo()->exec("UPDATE focus_areas SET status = 'active' WHERE id = $area");
$p = req('GET', '/pick/?source=source&area=' . $area, ['jar' => $priya]);
ok($p['code'] === 200 && str_contains($p['body'], 'SMOKE Training Notes') && !str_contains($p['body'], 'SMOKE Pump Service Manual'), 'the picker offers the base\'s sources not in the area');

echo "7. Who sees\n";
$reid = as_member(REID);
$ar = page($reid, '/bases/' . $bid . '/areas')['body'];
ok(str_contains($ar, 'id="area-card-' . $area . '"') && !str_contains($ar, 'id="areas-proposed"') && !str_contains($ar, 'id="area-card-' . $area . '-linked"') && !str_contains($ar, 'id="areas-add-btn"'), 'Reid sees the areas: no proposals, no link status, no buttons');
$av = page($reid, '/areas/' . $area)['body'];
ok(str_contains($av, 'id="area-sources"') && !str_contains($av, 'id="area-engine-link"') && !str_contains($av, 'id="area-add-source-form"') && !str_contains($av, 'id="area-delete-form"'), '…and area-view, read-only');
foreach (['/bases/' . $bid . '/guidelines', '/bases/' . $bid . '/vocabulary', '/bases/' . $bid . '/compiled'] as $u) {
    $r = page($reid, $u);
    ok($r['code'] === 403 && str_contains($r['body'], 'Guidelines and the vocabulary are the curators'), "Reid → 403 on $u");
}
$ann = as_member(ANN);
ok(page($ann, '/areas/' . $area)['code'] === 200 && page($ann, '/bases/' . $bid . '/guidelines')['code'] === 403, 'Ann the same as Reid on the base named to her');
$since = last_activity_id();
page(as_member(AVERY), '/areas/' . $area);
page(as_member(AVERY), '/bases/' . $bid . '/guidelines');
ok(count(activity('base.admin_view', $since)) === 2, 'Avery\'s look at the private base logs base.admin_view');
finish();
