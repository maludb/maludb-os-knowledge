<?php
declare(strict_types=1);
/**
 * GET /home/builds — the home's polling partial (not a screen of the manifest; nothing logged): the Builds running region again, with the polling attributes while a build on a base I curate is
 * queued or running. When none is, it answers 286 (which stops htmx's polling) with the final region and sends HX-Trigger: baseChanged once, so the bases region refreshes (?region=bases
 * answers that region alone). JSON gets the builds.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/features/home/queries.php';
require_once APP_ROOT . '/app/features/home/present.php';
require_login();
require_human();
$pdo = db();
header('Vary: HX-Request');
header('Cache-Control: no-store');
if (request_string('region') === 'bases') {
    $bases = $pdo->query('SELECT * FROM mcp_bases WHERE archived_at IS NULL ORDER BY updated_at DESC, name LIMIT 50')->fetchAll();
    if (wants_json()) {
        respond_screen(['bases' => array_map('present_base', $bases)]);
    }
    echo view('home/partials/bases.php', ['bases' => $bases, 'may' => ['create' => has_right('bases.create')], 'tz' => member_timezone()]);
    exit;
}
$curates = (int) one_value($pdo, 'SELECT count(*) FROM mcp_bases WHERE i_curate') > 0;
$builds = $curates ? home_builds($pdo) : [];
if ($builds === []) {
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 286 Stop Polling', true, 286);       // Apache answers a bare unknown code as a 500
    hx_trigger('baseChanged');
}
if (wants_json()) {
    $GLOBALS['__json_sent'] = true;
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['data' => ['builds' => array_map(static fn (array $b): array => ['build_id' => (int) $b['build_id'], 'base_id' => (int) $b['base_id'], 'status' => $b['status'], 'percent' => (int) $b['percent']], $builds)]]);
    exit;
}
echo view('home/partials/builds.php', ['builds' => $builds, 'poll' => $builds !== []]);
