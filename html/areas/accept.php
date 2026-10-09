<?php
declare(strict_types=1);
/**
 * Action `focus_area_accept` (base.manage on a curated base; free) — a proposed area becomes active (the trigger stamps the decision), its
 * project brought in step best-effort, an open archivist proposal naming it accepted. Logged `focus_area.accept`.
 */
require_once dirname(__DIR__, 2) . '/app/features/areas/handler.php';
kn_handler_begin();
$pdo = db();
[$a, $b] = area_from_request($pdo);
$bid = (int) $b['base_id'];
require_base_right($bid, 'base.manage');
require_base_live($b);
$aid = (int) $a['focus_area_id'];
$me = (int) current_member_id();
$r = kn_guard_fields($pdo, static function () use ($pdo, $aid, $bid, $me): array {
    $pdo->beginTransaction();
    $r = decide_area($pdo, $aid, true, $me);
    area_log($pdo, 'focus_area.accept', $bid, $aid, ['after' => ['focus_area_id' => $aid, 'proposed_by' => $r['proposed_by'], 'proposal_id' => $r['proposal_id']]]);
    $pdo->commit();
    return $r;
}, area_field_refusals());
$sync = area_sync_best_effort($pdo, $aid);
kn_done('Accepted ' . $a['name'] . (($sync['ok'] ?? false) ? ' — linked in the engine' : ' — the engine will link it before the next build'), $aid, kn_land('/bases/' . $bid . '/areas', 'accepted'), 'areasChanged',
    ['base_id' => $bid, 'engine_linked' => (bool) ($sync['ok'] ?? false)]);
