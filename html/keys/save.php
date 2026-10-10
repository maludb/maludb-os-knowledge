<?php
declare(strict_types=1);
/**
 * Action `key_save` (keys.manage on a curated base; free for an agent): `api_key`, `label`, `focus_area`, `rate_per_minute`, `rate_per_day` — a field
 * left out keeps its value (an agent's `_partial=1` fills the rest from the key's row); never the base. A limit above the default is the admin's.
 * Logged `key.save` (before and after of what changed). Location /bases/{id}/keys#key-{id}.
 */
require_once dirname(__DIR__, 2) . '/app/features/keys/handler.php';
kn_handler_begin();
$pdo = db();
[$k, $b] = key_from_request($pdo);
$bid = (int) $b['base_id'];
$id = (int) $k['key_id'];
require_base_right($bid, 'keys.manage');
[$f, $errors] = key_fields_from_request($pdo, $bid, $k);
if ($errors !== []) {
    kn_refuse_fields($errors);
}
$me = (int) current_member_id();
$changed = kn_guard_fields($pdo, static function () use ($pdo, $id, $f, $me, $bid, $k): array {
    $pdo->beginTransaction();
    $r = save_key($pdo, $id, $f, $me);
    if ($r['changed'] !== []) {
        $was = ['label' => $k['label'], 'focus_area_id' => $k['focus_area_id'] === null ? null : (int) $k['focus_area_id'], 'rate_per_minute' => (int) $k['rate_per_minute'], 'rate_per_day' => (int) $k['rate_per_day']];
        $d = kn_diff($was, array_intersect_key($f, $was));
        key_log($pdo, 'key.save', $bid, $id, ['key_id' => $id, 'label' => $f['label']] + $d['after'], $d['before']);
    }
    $pdo->commit();
    return $r['changed'];
}, key_field_refusals());
kn_done($changed === [] ? 'Nothing changed in the key "' . $f['label'] . '"' : 'Saved the key "' . $f['label'] . '"', $id, kn_land('/bases/' . $bid . '/keys', 'saved', 'key-' . $id), 'keyChanged', ['base_id' => $bid, 'changed' => $changed]);
