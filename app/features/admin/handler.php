<?php
declare(strict_types=1);

/**
 * The admin's settings handlers (slice 1): one shape for the three forms — the form's right, its fields read (types here, bounds the
 * database's), the write of that form's columns only, its own log event (settings.save | limits.save | retention.set — so the kernel's
 * approval policy of each matches it alone), back to /admin/settings.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/write.php';

function admin_settings_handle(string $form): never
{
    [$title, $right, , , $event] = KN_SETTINGS_FORMS[$form];
    kn_handler_begin();
    require_right($right);
    $pdo = db();
    [$fields, $errors] = kn_settings_request($form);
    if ($errors !== []) {
        kn_refuse_fields($errors);
    }
    $d = kn_guard_fields($pdo, static function () use ($pdo, $form, $fields, $event): array {
        $pdo->beginTransaction();
        $d = save_kn_settings($pdo, $form, $fields);
        if ($d['after'] !== []) {
            log_activity($pdo, $event, 'settings', 1, ['before' => $d['before'], 'after' => ['form' => $form] + $d['after']]);
        }
        $pdo->commit();
        return $d;
    }, kn_settings_refusals($form));
    kn_done($d['after'] === [] ? 'Nothing changed in the ' . strtolower($title) : 'Saved the ' . strtolower($title), 1,
        kn_land(return_path('/admin/settings'), $form, 'admin-settings-' . $form), 'settingsChanged', ['changed' => array_keys($d['after'])]);
}
