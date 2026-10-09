<?php
declare(strict_types=1);
/**
 * Action `base_archive` (base.manage on a curated base; free; confirmed) — the base stays readable; the database refuses its new
 * sources and builds in its own words. Undo: base_restore. Logged `base.archive`.
 */
require_once dirname(__DIR__, 2) . '/app/features/bases/handler.php';
kn_handler_begin();
$pdo = db();
$b = base_from_request($pdo);
$id = (int) $b['base_id'];
require_base_right($id, 'base.manage');
if ($b['archived_at'] !== null) {
    refuse(422, 'Base "' . $b['name'] . '" is archived already.');
}
$me = (int) current_member_id();
kn_guard_fields($pdo, static function () use ($pdo, $id, $me): void {
    $pdo->beginTransaction();
    archive_base($pdo, $id, true, $me);
    base_log($pdo, 'base.archive', $id, ['after' => ['archived' => true]]);
    $pdo->commit();
}, base_field_refusals());
kn_done('Archived ' . $b['name'], $id, kn_land(return_path('/bases/' . $id), 'archived'), 'baseChanged', ['base_id' => $id]);
