<?php
declare(strict_types=1);
/**
 * /bases/{id}/keys (screen `keys`, keys.manage on a curated base): the base's API keys (mcp_keys — never the hash) as a table at 1280 and cards at 375:
 * label, area, limits, today's count (danger at 90 % of the day), last used, status (live, expiring until <time>, revoked, expired), who minted it and
 * when, "rotated from"; Mint a key; per row Edit · Rotate · Revoke. After a mint or a rotate the raw key is shown ONCE in the copy-once box (the session's
 * `minted_key`); no JSON read ever carries it.
 */
require_once dirname(__DIR__, 2) . '/app/features/keys/handler.php';
require_human();
$pdo = db();
$b = base_from_request($pdo, 'keys');
$id = (int) $b['base_id'];
require_base_right($id, 'keys.manage');
$keys = find_keys($pdo, $id);
$def = key_defaults($pdo);
$may = ['mint' => $b['archived_at'] === null, 'edit' => true, 'overlap_hours' => $def['overlap_hours']];
base_screen_view($pdo, 'keys', $id);
if (wants_json()) {
    respond_screen(['base_id' => $id, 'keys' => array_map('present_key', $keys), 'defaults' => ['rate_per_minute' => $def['per_minute'], 'rate_per_day' => $def['per_day'], 'overlap_hours' => $def['overlap_hours']], 'api_url' => key_api_url('/api/v1/ask')]);
}
$minted = $_SESSION['minted_key'] ?? null;
unset($_SESSION['minted_key']);
if ($minted !== null) {
    header('Cache-Control: no-store');
}
render_screen('Keys of ' . $b['name'], view('keys/index.php', ['b' => $b, 'keys' => $keys, 'may' => $may, 'tabs' => base_tabs($id, base_level($id)), 'tz' => member_timezone(), 'minted' => $minted,
    'notice' => kn_notice(request_string('notice') ?: null, key_notices())]), ['activeNav' => 'bases', 'screen' => 'keys', 'entity' => 'base', 'recordId' => (string) $id]);
