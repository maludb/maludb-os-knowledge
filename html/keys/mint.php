<?php
declare(strict_types=1);
/**
 * Action `key_mint` (keys.manage on a curated base; external_send — pauses for an agent): `base`, `label` (1–80 — what will use the key), `focus_area`
 * (an active area of the base — empty for the whole base), `rate_per_minute` and `rate_per_day` (blank = the settings' defaults; above them only an
 * admin). The raw key is `mcp_` + 48 hex, stored as sha256 only and shown ONCE: a browser lands on /bases/{id}/keys#key-{id} with it in the session's
 * copy-once box; JSON mode (an agent after its approval) gets it once in `key`. Logged `key.mint` (label, area, limits — never the key or its hash).
 */
require_once dirname(__DIR__, 2) . '/app/features/keys/handler.php';
kn_handler_begin();
$pdo = db();
$b = base_from_request($pdo);
$bid = (int) $b['base_id'];
require_base_right($bid, 'keys.manage');
if ($b['archived_at'] !== null) {
    refuse(422, 'An archived base gets no keys.');
}
[$f, $errors] = key_fields_from_request($pdo, $bid, null);
if ($errors !== []) {
    kn_refuse_fields($errors);
}
$me = (int) current_member_id();
$r = kn_guard_fields($pdo, static function () use ($pdo, $bid, $f, $me): array {
    $pdo->beginTransaction();
    $r = mint_key($pdo, $bid, $f, $me);
    $row = $pdo->prepare('SELECT label, focus_area_id, rate_per_minute, rate_per_day FROM mcp_access_tokens WHERE id = :id');
    $row->execute(['id' => $r['key_id']]);
    key_log($pdo, 'key.mint', $bid, $r['key_id'], ['key_id' => $r['key_id']] + $row->fetch(PDO::FETCH_ASSOC));
    $pdo->commit();
    return $r;
}, key_field_refusals());
$id = $r['key_id'];
$did = 'Minted the key "' . $f['label'] . '" — shown once, copy it now';
$land = '/bases/' . $bid . '/keys#key-' . $id;
if (wants_json()) {
    emit_action_status(true, ['did' => $did, 'record_id' => $id, 'refresh' => 'keyChanged']);            // the value is in the body, once — never in a header
    respond_saved(['did' => $did, 'record_id' => $id, 'location' => $land, 'refresh' => 'keyChanged', 'base_id' => $bid, 'key' => $r['raw']]);
}
$_SESSION['minted_key'] = ['key_id' => $id, 'raw' => $r['raw'], 'expires_old' => null];
kn_done($did, $id, $land, 'keyChanged', ['base_id' => $bid]);
