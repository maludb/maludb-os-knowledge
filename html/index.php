<?php
declare(strict_types=1);
/**
 * / — home (screen `home`, design §9, docs/build-specs/home-admin.md): a person with no base sees ONE card — "Create a knowledge base" and the three steps (a guest or anyone
 * without bases.create: "Nothing has been shared with you yet."). Otherwise My bases, Builds running (curators; it polls), My recent questions, Pins due for review, the Archivist's
 * note, Gaps this week (curators) and, for the admin, usage, failed builds, dispatches and open proposals. Logs screen.view and writes nothing.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/home/queries.php';
require_once dirname(__DIR__) . '/app/features/home/present.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
log_screen_view($pdo, 'home');
$s = home_summary($pdo, $me);
if (wants_json()) {
    respond_screen(present_home($s));
}
render_screen('Home', view('home/dashboard.php', ['s' => $s, 'tz' => member_timezone()]), ['activeNav' => 'home', 'screen' => 'home']);
