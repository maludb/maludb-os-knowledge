<?php
declare(strict_types=1);
/** /admin/ — the admin area (screen `admin`) — built by slice 9 (NAV_SLICES); until then the shell's placeholder, 200 after the right (settings.manage). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('admin', NAV_SLICES['admin'], 'settings.manage');
