<?php
declare(strict_types=1);
/** /ask — ask a base a question (screen `ask`) — built by slice 5 (NAV_SLICES); until then the shell's placeholder, 200 after the right (bases.read). */
require_once dirname(__DIR__) . '/app/bootstrap.php';
render_nav_stub('ask', NAV_SLICES['ask'], 'bases.read');
