<?php
declare(strict_types=1);
/** / — home (screen `home`): a person with no bases sees one button, \"Create a knowledge base\"; otherwise the bases they may read (design §9). */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/home/queries.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
log_screen_view($pdo, 'home');
$s = home_summary($pdo, $me);
if (wants_json()) {
    respond_screen($s);
}
render_screen('Home', view('home/dashboard.php', ['s' => $s, 'tz' => member_timezone()]), ['activeNav' => 'home', 'screen' => 'home']);
