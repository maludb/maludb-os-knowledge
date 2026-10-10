<?php
declare(strict_types=1);
/**
 * /admin/dispatches (screen `dispatches`, settings.manage or agents.settings): every request to the agents (mcp_agent_dispatches) — agent, kind, base, the person acting, a status chip, attempts,
 * the next attempt, the run (a link to the OS's run for agents.settings), created, answered, the reply's first 200 characters, the detail — as a table at 1280 and cards at 375, filtered by
 * status, kind and base; Retry on a failed or refused one.
 */
require_once dirname(__DIR__, 2) . '/app/features/agents/handler.php';
require_any_right('settings.manage|agents.settings');
require_human();
$pdo = db();
$f = ['status' => request_string('status'), 'kind' => request_string('kind'), 'base' => request_integer('base')];
$page = max(1, request_integer('page') ?? 1);
$list = find_dispatches($pdo, $f, $page);
$may = ['retry' => has_right('agents.settings'), 'run_link' => has_right('agents.settings')];
log_screen_view($pdo, 'dispatches');
if (wants_json()) {
    respond_screen(['dispatches' => array_map('present_dispatch', $list['rows']), 'total' => $list['total'], 'page' => $list['page'], 'pages' => $list['pages']]);
}
render_screen('Agent dispatches', view('admin/dispatches.php', ['list' => $list, 'f' => $f, 'may' => $may, 'tz' => member_timezone(), 'notice' => kn_notice(request_string('notice') ?: null, dispatch_notices()),
    'base_label' => $f['base'] ? picker_label($pdo, 'base', $f['base']) : null]), ['activeNav' => 'admin', 'screen' => 'dispatches']);
