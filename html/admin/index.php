<?php
declare(strict_types=1);
/**
 * /admin/ — the admin area's index (screen `admin`, settings.manage), built by slice 9: a card per page — the settings and templates (slice 1), the model, usage, every base, every key,
 * the agents, purge (this slice), the connections (slice 7), the proposals and dispatches (slice 8) — each with its one-line state ("3 bases private", "2 keys expiring", "1 dispatch failed").
 * A page this person lacks the right for is shown without a link. Logs screen.view.
 */
require_once dirname(__DIR__, 2) . '/app/features/admin/handler.php';
require_right('settings.manage');
require_human();
$pdo = db();
$pages = admin_index($pdo);
log_screen_view($pdo, 'admin');
if (wants_json()) {
    respond_screen(['pages' => present_admin_index($pages)]);
}
render_screen('Admin', view('admin/index.php', ['pages' => $pages]), ['activeNav' => 'admin', 'screen' => 'admin']);
