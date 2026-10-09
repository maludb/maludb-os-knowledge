<?php
declare(strict_types=1);
/**
 * Action `base_visibility_set` (base.manage on a curated base; external_send — pauses for an agent in both directions) — `visibility`
 * private | internal. The trigger writes or removes the everyone row. Logged `base.visibility_set` (before / after).
 */
require_once dirname(__DIR__, 2) . '/app/features/bases/handler.php';
kn_handler_begin();
$pdo = db();
$b = base_from_request($pdo);
$id = (int) $b['base_id'];
require_base_right($id, 'base.manage');
require_base_live($b);
$v = (string) (req_val('visibility') ?? '');
if (!isset(BASE_VISIBILITIES[$v])) {
    kn_refuse_fields(['visibility' => 'The visibility is private or internal.']);
}
$me = (int) current_member_id();
$r = kn_guard_fields($pdo, static function () use ($pdo, $id, $v, $me): array {
    $pdo->beginTransaction();
    $r = set_base_visibility($pdo, $id, $v, $me);
    if ($r['before'] !== $r['after']) {
        base_log($pdo, 'base.visibility_set', $id, ['before' => ['visibility' => $r['before']], 'after' => ['visibility' => $r['after']]]);
    }
    $pdo->commit();
    return $r;
}, base_field_refusals());
kn_done($b['name'] . ' is ' . $v, $id, kn_land(return_path('/bases/' . $id . '/share'), 'visibility'), 'baseChanged', ['base_id' => $id]);
