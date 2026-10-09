<?php
declare(strict_types=1);
/**
 * Action `base_restore` (base.manage on a curated base; free) — the archive's undo. A base whose key another live base took meanwhile
 * is refused on the key ("change its key first"); a base being deleted (its forget build is live) is refused. Logged `base.restore`.
 */
require_once dirname(__DIR__, 2) . '/app/features/bases/handler.php';
kn_handler_begin();
$pdo = db();
$b = base_from_request($pdo);
$id = (int) $b['base_id'];
require_base_right($id, 'base.manage');
if ($b['archived_at'] === null) {
    refuse(422, 'Base "' . $b['name'] . '" is not archived.');
}
$me = (int) current_member_id();
kn_guard_fields($pdo, static function () use ($pdo, $id, $me): void {
    $pdo->beginTransaction();
    archive_base($pdo, $id, false, $me);
    base_log($pdo, 'base.restore', $id, ['after' => ['archived' => false]]);
    $pdo->commit();
}, ['knowledge_bases_key_live' => ['key', 'Another base already uses the key ' . $b['key'] . ': change its key first.']] + base_field_refusals());
kn_done('Restored ' . $b['name'], $id, kn_land(return_path('/bases/' . $id), 'restored'), 'baseChanged', ['base_id' => $id]);
