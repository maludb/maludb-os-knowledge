<?php
declare(strict_types=1);
/**
 * /admin/usage?month=&base= (screen `admin-usage`, costs.read): the engine usage of a month by base and kind (mcp_ai_usage) — calls, input and output tokens, characters — with totals, the
 * month picker (the last twelve) and each base's characters read against its monthly limit (a warning at the setting's percent, danger at 100). Dollars are the OS's AI statement (a link).
 */
require_once dirname(__DIR__, 2) . '/app/features/admin/handler.php';
require_right('costs.read');
require_human();
$pdo = db();
$month = request_string('month');
$base = request_integer('base');
$u = usage_by_base($pdo, $month, $base);
$warn = (int) one_value($pdo, 'SELECT limit_warn_pct FROM kn_settings WHERE id = 1');
log_screen_view($pdo, 'admin-usage');
if (wants_json()) {
    respond_screen(present_usage($u) + ['months' => usage_months($pdo), 'warn_pct' => $warn, 'statement_url' => os_page_url('/ai/spend/statements')]);
}
render_screen('Usage', view('admin/usage.php', ['u' => $u, 'months' => usage_months($pdo), 'base' => $base, 'base_label' => $base ? picker_label($pdo, 'base', $base) : null, 'warn' => $warn, 'statement' => os_page_url('/ai/spend/statements')]),
    ['activeNav' => 'admin', 'screen' => 'admin-usage']);
