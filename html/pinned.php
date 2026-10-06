<?php
declare(strict_types=1);
/** /pinned — the pinned answers across my bases (screen `pinned`) — built by slice 5 (NAV_SLICES); until then the shell's placeholder, 200 after the right (bases.read). */
require_once dirname(__DIR__) . '/app/bootstrap.php';
render_nav_stub('pinned', NAV_SLICES['pinned'], 'bases.read');
