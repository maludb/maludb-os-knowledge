<?php
declare(strict_types=1);
/**
 * /keys/new?base={id} (screen `key-add`) and /keys/{id}/edit (screen `key-edit`): label (1–80, what will use the key), area (Whole base or an active area
 * of the base), calls per minute and per day (blank = the settings' defaults, shown as the placeholder). The base of a key never changes.
 */
require_once dirname(__DIR__, 2) . '/app/features/keys/handler.php';
require_human();
$pdo = db();
$id = request_integer('id');
$k = null;
if ($id !== null) {
    [$k, $b] = key_from_request($pdo, 'key-edit');
    if (key_status($k) === 'revoked') {
        refuse(422, 'A revoked key cannot be changed.');
    }
} else {
    $b = base_from_request($pdo, 'key-add');
}
$bid = (int) $b['base_id'];
require_base_right($bid, 'keys.manage');
if ($k === null) {
    require_base_live($b);
}
$screen = $k === null ? 'key-add' : 'key-edit';
base_screen_view($pdo, $screen, $bid);
$areas = key_areas($pdo, $bid);
if (wants_json()) {
    respond_screen(['key' => $k === null ? null : present_key($k), 'areas' => $areas, 'defaults' => key_defaults($pdo)]);
}
render_screen($k === null ? 'Mint a key' : 'Change ' . $k['label'], view('keys/form.php', ['k' => $k, 'b' => $b, 'areas' => $areas, 'defaults' => key_defaults($pdo), 'may_limits' => has_right('limits.manage')]),
    ['activeNav' => 'bases', 'screen' => $screen, 'entity' => 'key', 'recordId' => $k === null ? '' : (string) $k['key_id']]);
