<?php
declare(strict_types=1);
/**
 * Action `base_member_remove` (base.share on a curated base; other — pauses for an agent) — `base_member` (a share row of the base).
 * The everyone row is refused by the trigger in its own words ("set visibility to private instead"); the owner is not a row. Logged
 * `base.unshare` with the row's facts.
 */
require_once dirname(__DIR__, 3) . '/app/features/bases/handler.php';
kn_handler_begin();
$pdo = db();
$b = base_from_request($pdo);
$id = (int) $b['base_id'];
require_base_right($id, 'base.share');
$rowId = request_integer('base_member');
if ($rowId === null) {
    kn_refuse_fields(['base_member' => 'Say which share to remove.']);
}
$me = (int) current_member_id();
$row = kn_guard_fields($pdo, static function () use ($pdo, $id, $rowId, $me): array {
    $pdo->beginTransaction();
    $row = unshare_base($pdo, $id, $rowId, $me);
    base_log($pdo, 'base.unshare', $id, ['before' => $row], 'base_member', $rowId);
    $pdo->commit();
    return $row;
}, share_field_refusals());
kn_done('Removed a share from ' . $b['name'], $rowId, kn_land(return_path('/bases/' . $id . '/share'), 'removed'), 'baseChanged', ['base_id' => $id]);
