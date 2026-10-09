<?php
declare(strict_types=1);
/**
 * /bases/{id}/settings (screen `base-settings`): auto-extract new sources, sensitivity, language; the monthly text limit (read-only for
 * a curator — "the admin sets it" — editable for a holder of limits.manage); the namespace and the guideline version, read-only.
 */
require_once dirname(__DIR__, 2) . '/app/features/bases/handler.php';
require_human();
$pdo = db();
$b = base_from_request($pdo, 'base-settings');
$id = (int) $b['base_id'];
require_base_right($id, 'base.manage');
$mayLimit = has_right('limits.manage');
base_screen_view($pdo, 'base-settings', $id);
if (wants_json()) {
    respond_screen(['base' => present_base($b), 'may_limit' => $mayLimit, 'sensitivities' => array_keys(BASE_SENSITIVITIES)]);
}
render_screen('Settings of ' . $b['name'], view('bases/settings.php', ['b' => $b, 'mayLimit' => $mayLimit, 'tabs' => base_tabs($id, base_level($id)),
    'notice' => kn_notice(request_string('notice') ?: null, base_notices($b))]),
    ['activeNav' => 'bases', 'screen' => 'base-settings', 'entity' => 'base', 'recordId' => (string) $id]);
