<?php
declare(strict_types=1);
/** Action `focus_area_dismiss` (base.manage on a curated base; free) — a proposed area archived, no project; an open archivist proposal naming it dismissed. Logged `focus_area.dismiss`. */
require_once dirname(__DIR__, 2) . '/app/features/areas/handler.php';
kn_handler_begin();
$pdo = db();
[$a, $b] = area_from_request($pdo);
$bid = (int) $b['base_id'];
require_base_right($bid, 'base.manage');
require_base_live($b);
$aid = (int) $a['focus_area_id'];
$me = (int) current_member_id();
kn_guard_fields($pdo, static function () use ($pdo, $aid, $bid, $me): void {
    $pdo->beginTransaction();
    $r = decide_area($pdo, $aid, false, $me);
    area_log($pdo, 'focus_area.dismiss', $bid, $aid, ['after' => ['focus_area_id' => $aid, 'proposed_by' => $r['proposed_by'], 'proposal_id' => $r['proposal_id']]]);
    $pdo->commit();
}, area_field_refusals());
kn_done('Dismissed ' . $a['name'], $aid, kn_land('/bases/' . $bid . '/areas', 'dismissed'), 'areasChanged', ['base_id' => $bid]);
