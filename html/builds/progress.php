<?php
declare(strict_types=1);
/**
 * GET /builds/{id}/progress — build-view's polling partial (not a screen of the manifest; nothing logged): the progress region again, with the
 * polling attributes while the build is queued or running; once it is finished it answers 286 with its final content, which stops the polling.
 */
require_once dirname(__DIR__, 2) . '/app/features/builds/handler.php';
require_right('bases.read');
$pdo = db();
$id = request_integer('id') ?? refuse(422, 'Say which build.');
$p = build_progress($pdo, $id) ?? refuse(404, 'Build not found.');
header('Vary: HX-Request');
header('Cache-Control: no-store');
if (!in_array($p['status'], ['queued', 'running'], true)) {
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 286 Stop Polling', true, 286);   // Apache answers a bare unknown code as a 500
}
if (wants_json()) {
    $GLOBALS['__json_sent'] = true;
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['data' => $p], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
$cancel = base_level((int) $p['base_id']) === 'own' || has_right('builds.run');
echo view('builds/partials/progress.php', ['p' => $p, 'poll' => true, 'tz' => member_timezone(), 'cancel' => $cancel]);
