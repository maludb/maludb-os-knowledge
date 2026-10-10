<?php
declare(strict_types=1);
/**
 * /admin/connections (screen `connections`, settings.manage or agents.settings; read-only): the three K7 shares this application declares (tool, what it answers,
 * its document); the applications named on bases and since when; the reads it declares as a consumer, with the state of the feeds that use them; the last 50
 * `share.read` rows (when, consumer, tool, base, outcome, milliseconds); the K26 notice. Approving a connection between applications is a super-admin's
 * decision in the OS — this screen only shows it.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/features/bases/present.php';                 // chip()
require_once APP_ROOT . '/app/features/connections/queries.php';
require_once APP_ROOT . '/app/features/connections/present.php';
require_any_right('settings.manage|agents.settings');
require_human();
$pdo = db();
$shares = declared_shares();
$principals = application_principals($pdo);
$reads = declared_reads();
$feeds = read_feed_states($pdo);
$calls = share_reads($pdo, 50);
$unnamed = count(array_filter($calls, static fn (array $c): bool => $c['outcome'] === 'consumer_unknown'));
log_screen_view($pdo, 'connections');
if (wants_json()) {
    respond_screen(['shares' => $shares, 'applications' => $principals, 'reads' => array_map(static fn (array $r): array => $r + ['feeds' => $feeds[$r['app'] . '/' . $r['tool']] ?? null], $reads),
                    'share_reads' => array_map('present_share_read', $calls), 'consumer_unknown' => $unnamed]);
}
render_screen('Connections', view('admin/connections.php', ['shares' => $shares, 'principals' => $principals, 'reads' => $reads, 'feeds' => $feeds, 'calls' => $calls, 'unnamed' => $unnamed,
    'os_url' => os_applications_url(), 'tz' => member_timezone()]), ['activeNav' => 'admin', 'screen' => 'connections']);
