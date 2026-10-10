<?php
declare(strict_types=1);
/**
 * Action `build_cancel` (builds.run on the build's base; free): a queued or running build cancelled (the trigger skips what is left; the item in
 * flight finishes in the engine and the worker records its document on the section); a finished build refuses in the trigger's words. Logged
 * `build.cancel` (done, failed, skipped). Nobody is told.
 */
require_once dirname(__DIR__, 2) . '/app/features/builds/handler.php';
kn_handler_begin();
$pdo = db();
[$x, $b] = build_from_request($pdo);
$bid = (int) $b['base_id'];
require_base_right($bid, 'builds.run');
$id = (int) $x['build_id'];
if (!in_array($x['status'], ['queued', 'running'], true)) {
    refuse(422, 'A finished build stays ' . $x['status'] . '.');   // the trigger's sentence; re-cancelling a cancelled build is no change, so it would not raise
}
$me = (int) current_member_id();
$r = kn_guard_fields($pdo, static function () use ($pdo, $id, $bid, $me): array {
    $pdo->beginTransaction();
    $r = cancel_build($pdo, $id, $me);
    build_log($pdo, 'build.cancel', $bid, $id, ['after' => $r]);
    $pdo->commit();
    return $r;
}, []);                                                 // a finished build: the trigger's own sentence
kn_done('Cancelled the build: ' . $r['done'] . ' done, ' . $r['failed'] . ' failed, ' . $r['skipped'] . ' skipped', $id, kn_land('/builds/' . $id, 'cancelled'), 'buildsChanged', ['base_id' => $bid]);
