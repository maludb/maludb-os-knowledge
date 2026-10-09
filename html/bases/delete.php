<?php
declare(strict_types=1);
/**
 * Action `base_delete` (own level — the owner or an admin; deletion — pauses for an agent) — `confirm_name` (the base's name typed).
 * A base that never reached the engine goes at once; one that did is renamed deleting-<id>, its sources forgotten, a forget build
 * queued and archived — the worker deletes it when the engine has forgotten it (divergence 2). Refused while a build is queued or
 * running. Logged `base.delete` (name, phase, sources, sections, build_id). Location /bases.
 */
require_once dirname(__DIR__, 2) . '/app/features/bases/handler.php';
kn_handler_begin();
$pdo = db();
$b = base_from_request($pdo);
$id = (int) $b['base_id'];
require_base_own($id, 'delete it');
if (trim((string) (req_val('confirm_name') ?? '')) !== $b['name']) {
    kn_refuse_fields(['confirm_name' => 'Type the base\'s name exactly — ' . $b['name'] . ' — to delete it.']);
}
$me = (int) current_member_id();
$r = kn_guard_fields($pdo, static function () use ($pdo, $id, $b, $me): array {
    $pdo->beginTransaction();
    $r = delete_base($pdo, $id, $me);
    base_log($pdo, 'base.delete', $id, ['after' => ['name' => $b['name'], 'phase' => $r['phase'], 'sources' => $r['sources'], 'sections' => $r['sections'], 'build_id' => $r['build_id']]]);
    $pdo->commit();
    return $r;
}, base_field_refusals());
delete_base_files();
$did = $r['phase'] === 'deleted' ? 'Deleted ' . $b['name']
    : 'Forgetting ' . $r['sections'] . ' section' . ($r['sections'] === 1 ? '' : 's') . ' in the engine — ' . $b['name'] . ' disappears when that finishes';
kn_done($did, $id, kn_land('/bases', $r['phase'] === 'deleted' ? 'deleted' : 'forgetting'), 'baseChanged', ['base_id' => $id, 'phase' => $r['phase'], 'build_id' => $r['build_id']]);
