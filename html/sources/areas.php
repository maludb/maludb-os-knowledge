<?php
declare(strict_types=1);
/**
 * Action `source_areas_set` (base.manage on a curated base; free) — `source` and the full set of its areas (`areas[]`, active areas of the
 * source's own base — the trigger refuses another base's). Logged `focus_area.assign` (entity source: source_id, added, removed).
 */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
require_once APP_ROOT . '/app/features/areas/handler.php';
kn_handler_begin();
$pdo = db();
[$s, $b] = source_from_request($pdo);
$bid = (int) $b['base_id'];
require_base_right($bid, 'base.manage');
if ($s['status'] === 'forgotten') {
    refuse(422, '"' . $s['title'] . '" is removed: it is in no area.');
}
$ids = array_map('intval', request_list('areas') ?? []);
$activeIds = array_map(static fn (array $a): int => (int) $a['focus_area_id'], active_areas($pdo, $bid));
foreach ($ids as $aid) {
    if (!in_array($aid, $activeIds, true) && one_value($pdo, 'SELECT 1 FROM focus_areas WHERE id = :a AND base_id = :b', ['a' => $aid, 'b' => $bid]) !== null) {
        kn_refuse_fields(['areas' => 'A source goes into active areas only.']);
    }
}
$sid = (int) $s['source_id'];
$me = (int) current_member_id();
$r = kn_guard_fields($pdo, static function () use ($pdo, $sid, $ids, $me, $bid): array {
    $pdo->beginTransaction();
    $r = set_source_areas($pdo, $sid, $ids, $me);
    log_activity($pdo, 'focus_area.assign', 'source', $sid, ['base_id' => $bid, 'after' => ['source_id' => $sid, 'added' => $r['added'], 'removed' => $r['removed']]]);
    $pdo->commit();
    return $r;
}, area_field_refusals());
kn_done('Saved the areas of ' . $s['title'] . ' (' . $r['added'] . ' added, ' . $r['removed'] . ' removed)', $sid, kn_land(return_path('/sources/' . $sid), 'saved'), 'areasChanged',
    ['base_id' => $bid, 'added' => $r['added'], 'removed' => $r['removed']]);
