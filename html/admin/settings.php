<?php
declare(strict_types=1);
/**
 * /admin/settings (screen `admin-settings`): three forms on one page, each its own action and right — Settings (settings.manage),
 * Limits (limits.manage), Retention (retention.manage). Each field shows its bounds (db/005's CHECKs). A form whose right the person
 * lacks is shown read-only; a person holding none of the three is refused.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/features/admin/write.php';
require_any_right('settings.manage|limits.manage|retention.manage');
require_human();
$pdo = db();
$settings = find_kn_settings($pdo);
$may = [];
foreach (KN_SETTINGS_FORMS as $form => [, $right]) {
    $may[$form] = has_right($right);
}
log_screen_view($pdo, 'admin-settings');
if (wants_json()) {
    $out = [];
    foreach (KN_SETTINGS_FIELDS as $col => $f) {
        $out[$col] = ['value' => $settings[$col] ?? null, 'form' => $f['form'], 'label' => $f['label'], 'min' => $f['min'] ?? null, 'max' => $f['max'] ?? null, 'scale' => $f['scale'] ?? 1];
    }
    respond_screen(['settings' => $out, 'may' => $may]);
}
$notices = ['settings' => ['success', 'Saved the settings.'], 'limits' => ['success', 'Saved the limits.'], 'retention' => ['success', 'Saved the retention.']];
render_screen('Settings', view('admin/settings.php', ['s' => $settings, 'may' => $may, 'notice' => kn_notice(request_string('notice') ?: null, $notices)]),
    ['activeNav' => 'admin', 'screen' => 'admin-settings']);
