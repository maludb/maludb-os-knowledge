<?php
declare(strict_types=1);
/**
 * Action `focus_area_assign` (base.manage on a curated base; free) — `focus_area`, `source`, `assigned` yes | no: one source in or out of one
 * area (only an area of the source's own base — the trigger refuses another's). Assignment shapes nothing now; the next extraction sends the
 * area's project. Logged `focus_area.assign` (source_id, added, removed).
 */
require_once dirname(__DIR__, 2) . '/app/features/areas/handler.php';
require_once APP_ROOT . '/app/features/sources/queries.php';
kn_handler_begin();
$pdo = db();
[$a, $b] = area_from_request($pdo);
$bid = (int) $b['base_id'];
require_base_right($bid, 'base.manage');
$sid = request_integer('source');
if ($sid === null) {
    kn_refuse_fields(['source' => 'Choose a source.']);
}
$src = find_source($pdo, $sid);
if ($src === null || $src['status'] === 'forgotten') {
    kn_refuse_fields(['source' => 'That source is not here.']);
}
$assigned = kn_yes('assigned', true);
if ($assigned && $a['status'] !== 'active') {
    kn_refuse_fields(['focus_area' => 'A source goes into an active area.']);
}
$aid = (int) $a['focus_area_id'];
$me = (int) current_member_id();
$changed = kn_guard_fields($pdo, static function () use ($pdo, $aid, $sid, $assigned, $me, $bid): bool {
    $pdo->beginTransaction();
    $changed = assign_area($pdo, $aid, $sid, $assigned, $me);
    if ($changed) {
        area_log($pdo, 'focus_area.assign', $bid, $aid, ['after' => ['source_id' => $sid, 'focus_area_id' => $aid, 'added' => $assigned ? 1 : 0, 'removed' => $assigned ? 0 : 1]]);
    }
    $pdo->commit();
    return $changed;
}, area_field_refusals());
kn_done(($assigned ? 'Put ' . $src['title'] . ' in ' : 'Took ' . $src['title'] . ' out of ') . $a['name'], $aid, kn_land(return_path('/areas/' . $aid), 'assigned'), 'areasChanged',
    ['base_id' => $bid, 'changed' => $changed]);
