<?php
declare(strict_types=1);
/** Action `focus_area_link` (base.manage on a curated base; free) — the area's project brought in step now, refused in the engine's words when it fails. Logged `focus_area.save` (after.engine_linked, project_id). */
require_once dirname(__DIR__, 2) . '/app/features/areas/handler.php';
kn_handler_begin();
$pdo = db();
[$a, $b] = area_from_request($pdo);
$bid = (int) $b['base_id'];
require_base_right($bid, 'base.manage');
require_base_live($b);
$aid = (int) $a['focus_area_id'];
if ($a['status'] !== 'active') {
    refuse(422, 'Only an active area is linked in the engine.');
}
$sync = kn_area_engine_sync($pdo, $aid);
if (!$sync['ok']) {
    refuse(422, kn_engine_error_message($sync['code']));
}
area_log($pdo, 'focus_area.save', $bid, $aid, ['after' => ['focus_area_id' => $aid, 'engine_linked' => true, 'project_id' => $sync['project_id'], 'linked' => $sync['linked'], 'unlinked' => $sync['unlinked']]]);
kn_done('Linked ' . $a['name'] . ' in the engine', $aid, kn_land('/areas/' . $aid, 'linked'), 'areasChanged', ['base_id' => $bid, 'project_id' => $sync['project_id']]);
