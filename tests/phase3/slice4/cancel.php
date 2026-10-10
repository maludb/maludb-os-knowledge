<?php
/** Slice 4 proof — cancelling (builds.md "Proof", bullet 7). */
require __DIR__ . '/lib.php';

echo "14. Cancel\n";
world4();
$cb = curated_base('SMOKE Cancel', 'smoke-cancel');
add_text($cb, 'SMOKE Cancel Manual', manual_text());
worker();
[, $b] = start(PRIYA, $cb);
$id = (int) $b['record_id'];
worker();
$n0 = (int) one("SELECT count(*) FROM notifications WHERE kind IN ('build_done', 'build_failed')");
$since = last_activity_id();
[$c, $b] = act(as_member(MARCO), '/builds/cancel.php', ['build' => $id]);
$r = build_row($id);
$left = (int) $r['items_total'] - 10;
ok($c === 200 && ($b['did'] ?? '') === "Cancelled the build: 10 done, 0 failed, $left skipped" && ($b['location'] ?? '') === "/builds/$id", 'Marco cancels between passes: "' . ($b['did'] ?? '') . '"');
ok($r['status'] === 'cancelled' && (int) one("SELECT count(*) FROM build_items WHERE build_id = :b AND status = 'skipped'", ['b' => $id]) === $left, "the build cancelled, the remaining $left items skipped");
ok((int) one("SELECT count(*) FROM notifications WHERE kind IN ('build_done', 'build_failed')") === $n0, 'nobody told');
$log = activity('build.cancel', $since);
ok(count($log) === 1 && json_decode($log[0]['after'], true) == ['build_id' => $id, 'done' => 10, 'failed' => 0, 'skipped' => $left] && (int) $log[0]['base_id'] === $cb, 'build.cancel logged (done, failed, skipped, base_id)');
[$c, $b] = act(as_member(MARCO), '/builds/cancel.php', ['build' => $id]);
ok($c === 422 && msg($b) === 'A finished build stays cancelled.', 'cancelling it again: "' . msg($b) . '"');
worker();
ok(live_build($cb) === null, 'the next pass takes nothing (auto-extract off)');

echo "15. A section in flight when the build is cancelled\n";
$fb = curated_base('SMOKE Cancel Flight', 'smoke-cancel-flight');
add_text($fb, 'SMOKE Flight Slow', "# Slow\n\nThe Zorblat Pump hums slowly. SLOWPROVIDER");
add_text($fb, 'SMOKE Flight Quick', "# Quick\n\nThe Merrow Filter traps dust.");
worker();
[, $b] = start(PRIYA, $fb);
$id2 = (int) $b['record_id'];
$slow = (int) one("SELECT x.id FROM sections x JOIN sources s ON s.id = x.source_id WHERE s.title = 'SMOKE Flight Slow'");
$proc = worker_bg();
$t = microtime(true);
while (one("SELECT status FROM build_items WHERE build_id = :b AND section_id = :s", ['b' => $id2, 's' => $slow]) !== 'running' && microtime(true) - $t < 15) { usleep(100000); }
[$c, $b] = act(as_member(PRIYA), '/builds/cancel.php', ['build' => $id2]);
wait_worker($proc);
$sec = q('SELECT * FROM sections WHERE id = :s', ['s' => $slow])[0];
ok($c === 200 && build_row($id2)['status'] === 'cancelled', 'cancelled while the slow section was in the engine');
ok($sec['maludb_document_id'] !== null && in_array((int) $sec['maludb_document_id'], engine_docs($fb), true) && $sec['status'] === 'queued', 'its section keeps the new document id (still queued)');
ok(one('SELECT status FROM build_items WHERE build_id = :b AND section_id = :s', ['b' => $id2, 's' => $slow]) === 'skipped', '…and its item stays skipped');
$old = (int) $sec['maludb_document_id'];
[, $b] = start(PRIYA, $fb);
run_build((int) $b['record_id']);
$sec = q('SELECT * FROM sections WHERE id = :s', ['s' => $slow])[0];
ok(build_row((int) $b['record_id'])['status'] === 'done' && kn_engine_document($old)['status'] === 404 && (int) $sec['maludb_document_id'] !== $old && $sec['status'] === 'extracted', 'the next build forgets that document, then ingests the section again');
ok(count(engine_docs($fb)) === 2, 'the namespace holds two documents, one a section');
[$c, $b] = act(as_member(PRIYA), '/builds/cancel.php', ['build' => (int) one('SELECT max(id) FROM builds WHERE base_id = :b', ['b' => $fb])]);
ok($c === 422 && msg($b) === 'A finished build stays done.', 'cancelling a done build: "' . msg($b) . '"');
finish();
