<?php
declare(strict_types=1);
/**
 * Action `guideline_delete` (base.manage on a curated base; deletion — pauses for an agent; confirmed): an active graph-shaping guideline's
 * delete bumps the version (the trigger); the compiled prompt sent best-effort. A shipped guideline may be deleted. Logged
 * `guideline.delete` (guideline_id, kind, title, version_no) and the version rows. Location /bases/{id}/guidelines.
 */
require_once dirname(__DIR__, 2) . '/app/features/guidelines/handler.php';
kn_handler_begin();
$pdo = db();
[$g, $b] = guideline_from_request($pdo);
$bid = (int) $b['base_id'];
require_base_right($bid, 'base.manage');
require_base_live($b);
$me = (int) current_member_id();
$gid = (int) $g['guideline_id'];
$r = kn_guard_fields($pdo, static function () use ($pdo, $gid, $bid, $me): array {
    $pdo->beginTransaction();
    $r = delete_guideline($pdo, $gid, $me);
    guideline_log($pdo, 'guideline.delete', $bid, 'guideline', $gid, ['after' => ['guideline_id' => $gid, 'kind' => $r['kind'], 'title' => $r['title'], 'version_no' => $r['version_no']]]);
    log_version_move($pdo, $bid, $r);
    $pdo->commit();
    return $r;
}, guideline_field_refusals());
$sent = send_compiled_after($pdo, $bid, $r);
$did = 'Deleted' . ($r['base_version_after'] !== $r['base_version_before'] ? ' — version ' . $r['base_version_after'] . ': ' . $r['stale_total'] . ' sections need a rebuild' : '');
kn_done($did, $gid, kn_land('/bases/' . $bid . '/guidelines', 'deleted'), 'guidelinesChanged', ['base_id' => $bid, 'version' => $r['base_version_after'],
    'engine_synced' => $sent === null ? null : (bool) ($sent['ok'] ?? false)]);
