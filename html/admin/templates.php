<?php
declare(strict_types=1);
/** /admin/templates (screen `templates`): the templates as cards, read-only — name, key, version, description, the shipped guidelines by kind, the subject types, the area rule. */
require_once dirname(__DIR__, 2) . '/app/features/bases/handler.php';
require_right('settings.manage');
require_human();
$pdo = db();
$templates = find_templates($pdo, false);
log_screen_view($pdo, 'templates');
if (wants_json()) {
    respond_screen(['templates' => array_map('present_template', $templates)]);
}
render_screen('Templates', view('admin/templates.php', ['templates' => $templates]), ['activeNav' => 'admin', 'screen' => 'templates']);
