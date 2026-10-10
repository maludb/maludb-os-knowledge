<?php
declare(strict_types=1);
/**
 * Action `key_rotate` (keys.manage on a curated base; confirmed; external_send — pauses for an agent): `api_key`, a LIVE one. A new key for the same
 * base, area, limits and minter (kn_key_rotate); the old one keeps working for the settings' overlap (24 h) and then stops. The new raw key is shown
 * ONCE exactly as a mint's is. Logged `key.rotate` on the new key (old_key_id, new_key_id, overlap_hours). record_id = the new key.
 */
require_once dirname(__DIR__, 2) . '/app/features/keys/handler.php';
kn_handler_begin();
$pdo = db();
[$k, $b] = key_from_request($pdo);
$bid = (int) $b['base_id'];
$old = (int) $k['key_id'];
require_base_right($bid, 'keys.manage');
require_base_live($b);
if (!filter_var($k['is_live'], FILTER_VALIDATE_BOOLEAN)) {
    kn_refuse_fields(['api_key' => 'Only a live key is rotated.']);                  // kn_key_rotate()'s words; an expired key is refused here too
}
$me = (int) current_member_id();
$r = kn_guard_fields($pdo, static function () use ($pdo, $old, $me, $bid, $k): array {
    $pdo->beginTransaction();
    $r = rotate_key($pdo, $old, $me);
    key_log($pdo, 'key.rotate', $bid, $r['key_id'], ['old_key_id' => $old, 'new_key_id' => $r['key_id'], 'overlap_hours' => $r['overlap_hours'], 'label' => $k['label']]);
    $pdo->commit();
    return $r;
}, key_field_refusals());
$id = $r['key_id'];
$ends = $r['old_expires_at'] === null ? null : json_ts((string) $r['old_expires_at']);
$did = 'Rotated the key "' . $k['label'] . '" — the new key is shown once; the old one stops working at ' . ($ends ?? 'once the overlap ends');
$land = '/bases/' . $bid . '/keys#key-' . $id;
if (wants_json()) {
    emit_action_status(true, ['did' => $did, 'record_id' => $id, 'refresh' => 'keyChanged']);
    respond_saved(['did' => $did, 'record_id' => $id, 'location' => $land, 'refresh' => 'keyChanged', 'base_id' => $bid, 'old_key_id' => $old, 'old_expires_at' => $ends, 'key' => $r['raw']]);
}
$_SESSION['minted_key'] = ['key_id' => $id, 'raw' => $r['raw'], 'expires_old' => $r['old_expires_at'] === null ? null : strtotime((string) $r['old_expires_at']), 'old_key_id' => $old];
kn_done($did, $id, $land, 'keyChanged', ['base_id' => $bid, 'old_key_id' => $old]);
