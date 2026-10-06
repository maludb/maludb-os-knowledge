<?php
declare(strict_types=1);
/** /bases — the knowledge bases I may read (screen `bases`) — built by slice 1 (NAV_SLICES); until then the shell's placeholder, 200 after the right (bases.read). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('bases', NAV_SLICES['bases'], 'bases.read');
