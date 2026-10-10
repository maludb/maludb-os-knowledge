<?php
declare(strict_types=1);
/**
 * /admin/keys?base=&status= (screen `admin-keys`, keys.all): every API key of every base (all_keys() over mcp_keys — never the hash or the value) — base, label, area, limits, today's count, last used, status, who
 * minted it — as a table at 1280 and cards at 375; Revoke is slice 7's key_revoke (the admin owns every base), landing back here. A key's value is shown once, when it is minted on its base's Keys screen.
 */
require_once dirname(__DIR__, 2) . '/app/features/admin/handler.php';
require_once APP_ROOT . '/app/features/keys/queries.php';
require_right('keys.all');
require_human();
$pdo = db();
$f = ['base' => request_integer('base'), 'status' => request_string('status')];
$keys = all_keys($pdo, ['base' => $f['base']]);
if (isset(KEY_STATUSES[$f['status']])) {
    $keys = array_values(array_filter($keys, static fn (array $k): bool => key_status($k) === $f['status']));
}
log_screen_view($pdo, 'admin-keys');
if (wants_json()) {
    respond_screen(['keys' => array_map(static fn (array $k): array => present_key($k) + ['base' => $k['base_name']], $keys), 'filters' => $f]);
}
render_screen('Every key', view('admin/keys.php', ['keys' => $keys, 'f' => $f, 'tz' => member_timezone(), 'base_label' => $f['base'] ? picker_label($pdo, 'base', $f['base']) : null,
    'notice' => kn_notice(request_string('notice') ?: null, key_notices())]), ['activeNav' => 'admin', 'screen' => 'admin-keys']);
