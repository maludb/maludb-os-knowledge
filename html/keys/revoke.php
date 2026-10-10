<?php
declare(strict_types=1);
/**
 * Action `key_revoke` (keys.manage on a curated base; confirmed; deletion — pauses for an agent, its own keys included): `api_key`. The key answers
 * 401 on its next call; one mid-overlap is revoked alone. Refused on a key already revoked. Logged `key.revoke` (label). Location /bases/{id}/keys#key-{id}.
 */
require_once dirname(__DIR__, 2) . '/app/features/keys/handler.php';
kn_handler_begin();
$pdo = db();
[$k, $b] = key_from_request($pdo);
$bid = (int) $b['base_id'];
$id = (int) $k['key_id'];
require_base_right($bid, 'keys.manage');
$me = (int) current_member_id();
kn_guard($pdo, static function () use ($pdo, $id, $me, $bid, $k): void {
    $pdo->beginTransaction();
    revoke_key($pdo, $id, $me);
    key_log($pdo, 'key.revoke', $bid, $id, ['key_id' => $id, 'label' => $k['label']]);
    $pdo->commit();
});
kn_done('Revoked the key "' . $k['label'] . '"', $id, kn_land('/bases/' . $bid . '/keys', 'revoked', 'key-' . $id), 'keyChanged', ['base_id' => $bid]);
