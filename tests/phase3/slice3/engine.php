<?php
/** Slice 3 proof — when the engine is down, and the purge of a deleted base's areas (guidelines-areas.md "Proof", bullets 2 and 5). */
require __DIR__ . '/lib.php';

echo "8. The engine down, then back\n";
$w = world();
$bid = $w['base'];
$priya = as_member(PRIYA);
engine('restart-api');                                   // stops the API
$v = base_version($bid);
[$c, $b] = act($priya, '/guidelines/save.php', ['base' => $bid, 'kind' => 'extraction', 'title' => 'SMOKE Offline', 'body' => 'Extract every offline spare part.']);
ok($c === 200 && base_version($bid) === $v + 1 && str_contains($b['did'] ?? '', 'the engine will get them before the next build'), 'a guideline save with the engine stopped stands; the did says "before the next build"');
$gs = activity('guideline.save');
ok(json_decode(end($gs)['after'], true)['engine_synced'] === false, '…logged engine_synced false');
[$c, $b] = act($priya, '/areas/save.php', ['base' => $bid, 'name' => 'SMOKE Offline Area', 'seed_terms' => 'Merrow Filter']);
$area = (int) ($b['record_id'] ?? 0);
ok($c === 200 && ($b['did'] ?? '') === 'Saved — the engine will link it before the next build' && one('SELECT maludb_project_id FROM focus_areas WHERE id = :a', ['a' => $area]) === null, 'an area saved with the engine stopped: "not linked"');
ok(str_contains(page($priya, '/areas/' . $area)['body'], 'id="area-link-btn"'), 'its page offers Link now');
[$c, $b] = act($priya, '/areas/link.php', ['focus_area' => $area]);
ok($c === 422 && msg($b) !== '' && !str_contains(msg($b), 'Something went wrong'), 'Link now refuses in the engine\'s words: "' . msg($b) . '"');
[, $lb] = act(as_member(PRIYA), '/areas/save.php', ['base' => $bid, 'name' => 'SMOKE Linked Before']);
engine('restart-api');                                   // starts it
[, $lb] = act($priya, '/areas/link.php', ['focus_area' => (int) $lb['record_id']]);
$linkedArea = (int) $lb['record_id'];
engine('restart-api');                                   // stops it again
[$c, $b] = act($priya, '/areas/delete.php', ['focus_area' => $linkedArea]);
ok($c === 422 && msg($b) === 'The engine could not remove the area\'s project — try again.' && (int) one('SELECT count(*) FROM focus_areas WHERE id = :a', ['a' => $linkedArea]) === 1, 'with the engine stopped a delete is refused and the area kept');
engine('restart-api');                                   // starts it
[$c, $b] = act($priya, '/areas/link.php', ['focus_area' => $area]);
ok($c === 200 && one('SELECT maludb_project_id FROM focus_areas WHERE id = :a', ['a' => $area]) !== null, 'back up: Link now links');
ok(kn_compile_ensure(pdo(), $bid)['sent'] === true && kn_compile_engine_sha('kb:' . $bid) === kn_compile_prompt(pdo(), $bid)['sha256'], 'and kn_compile_ensure() sends what the engine missed');

echo "9. The purge of a deleted base removes its areas' projects\n";
$doomed = make_base(PRIYA, 'SMOKE Doomed Areas');
kn_compile_send(pdo(), $doomed);
[, $ab] = act($priya, '/areas/save.php', ['base' => $doomed, 'name' => 'SMOKE Doomed Area', 'seed_terms' => 'Zorblat Pump']);
$dpid = (int) one('SELECT maludb_project_id FROM focus_areas WHERE id = :a', ['a' => (int) $ab['record_id']]);
[$c, $b] = act($priya, '/bases/delete.php', ['base' => $doomed, 'confirm_name' => 'SMOKE Doomed Areas']);
ok($c === 200 && ($b['phase'] ?? '') === 'forgetting' && $dpid > 0, 'a base whose area has a project is deleted in the forgetting phase');
worker();
ok(kn_engine_project($dpid)['status'] === 404 && base_row_of($doomed) === [], 'the next pass removes the area\'s project, then the base');
finish();
