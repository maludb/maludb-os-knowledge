<?php
declare(strict_types=1);
/**
 * /admin/proposals (screen `admin-proposals`, settings.manage or agents.settings): every base's proposals (the admin curates every base) as the cards of a base's own proposals
 * screen, with the base named on each and filters by base, status and kind. Accept and Dismiss are the questions.review of the base, as ever.
 */
require_once dirname(__DIR__, 2) . '/app/features/proposals/handler.php';
require_any_right('settings.manage|agents.settings');
require_human();
$pdo = db();
$f = ['status' => request_string('status') ?: 'proposed', 'kind' => request_string('kind'), 'base' => request_integer('base')];
$rows = find_proposals($pdo, null, $f);
log_screen_view($pdo, 'admin-proposals');
if (wants_json()) {
    respond_screen(['proposals' => array_map('present_proposal', $rows), 'status' => $f['status']]);
}
render_screen('Proposals', view('admin/proposals.php', ['rows' => $rows, 'estimates' => proposal_estimates($pdo, $rows), 'f' => $f, 'tz' => member_timezone(), 'notice' => kn_notice(request_string('notice') ?: null, proposal_notices()),
    'base_label' => $f['base'] ? picker_label($pdo, 'base', $f['base']) : null]), ['activeNav' => 'admin', 'screen' => 'admin-proposals']);
