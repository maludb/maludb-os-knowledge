<?php
declare(strict_types=1);
/**
 * Action `focus_area_delete` (base.manage on a curated base; deletion — pauses for an agent; confirmed): the area's project removed from the
 * engine FIRST (links, then the project) — its failure refuses the delete, so no project outlives its area — then the row (assignments
 * cascade). Logged `focus_area.delete` (name, project_id, sources). Location /bases/{id}/areas.
 */
require_once dirname(__DIR__, 2) . '/app/features/areas/handler.php';
kn_handler_begin();
$pdo = db();
[$a, $b] = area_from_request($pdo);
$bid = (int) $b['base_id'];
require_base_right($bid, 'base.manage');
$aid = (int) $a['focus_area_id'];
$rm = kn_area_engine_remove($pdo, $aid);
if (!$rm['ok']) {
    refuse(422, 'The engine could not remove the area\'s project — try again.');
}
$me = (int) current_member_id();
$r = kn_guard_fields($pdo, static function () use ($pdo, $aid, $bid, $me, $rm): array {
    $pdo->beginTransaction();
    $r = delete_area($pdo, $aid, $me);
    area_log($pdo, 'focus_area.delete', $bid, $aid, ['after' => ['focus_area_id' => $aid, 'name' => $r['name'], 'project_id' => $rm['project_id'], 'sources' => $r['sources']]]);
    $pdo->commit();
    return $r;
}, area_field_refusals());
kn_done('Deleted the area ' . $a['name'], $aid, kn_land('/bases/' . $bid . '/areas', 'deleted'), 'areasChanged', ['base_id' => $bid]);
