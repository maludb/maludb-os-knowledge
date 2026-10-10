<?php
declare(strict_types=1);
/**
 * /admin/bases?visibility=&status=&archived= (screen `admin-bases`, settings.manage): every base — name, owner, visibility (a private one marked with a lock), status, sources, sections, questions
 * in the last 30 days, last built, archived — as a table at 1280 and cards at 375. A name opens the base, which logs `base.admin_view` when the admin is not one of its members (slice 1's rule);
 * this list itself logs nothing but the screen.
 */
require_once dirname(__DIR__, 2) . '/app/features/admin/handler.php';
require_right('settings.manage');
require_human();
$pdo = db();
$f = ['visibility' => request_string('visibility'), 'status' => request_string('status'), 'archived' => request_bool('archived')];
$rows = admin_bases($pdo, $f);
log_screen_view($pdo, 'admin-bases');
if (wants_json()) {
    respond_screen(['bases' => array_map('present_admin_base', $rows), 'filters' => $f]);
}
render_screen('Every base', view('admin/bases.php', ['rows' => $rows, 'f' => $f, 'tz' => member_timezone()]), ['activeNav' => 'admin', 'screen' => 'admin-bases']);
