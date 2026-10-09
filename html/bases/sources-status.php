<?php
declare(strict_types=1);
/**
 * GET /bases/{id}/sources-status — the sources screen's polling partial (not a screen of the manifest; nothing logged): the cards region,
 * re-rendered. While a source is still being read it carries the polling attributes again; when nothing is pending it answers 286, which
 * stops HTMX's polling, with the final cards.
 */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
require_right('bases.read');
$pdo = db();
$id = request_integer('id') ?? request_integer('base') ?? refuse(422, 'Say which knowledge base.');
$b = find_base($pdo, $id) ?? refuse(404, 'Knowledge base not found.');
$filters = ['kind' => request_string('kind'), 'status' => request_string('status'), 'area' => request_integer('area'), 'q' => mb_substr(request_string('q'), 0, 80)];
$pending = pending_sources($pdo, $id);
header('Vary: HX-Request');
header('Cache-Control: no-store');
// 286 is htmx's "stop polling"; Apache answers an unknown code given by number as a 500, so the whole status line is sent.
$stop = static function (): void { header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 286 Stop Polling', true, 286); };
if (wants_json()) {
    if ($pending === []) {
        $GLOBALS['__json_sent'] = true;
        $stop();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['data' => ['pending' => 0, 'sources' => []]]);
        exit;
    }
    json_response(['data' => ['pending' => count($pending), 'sources' => array_map(static fn (array $p): array => ['source_id' => (int) $p['source_id'], 'status' => $p['status']], $pending)]]);
}
if ($pending === []) {
    $stop();
}
echo view('sources/partials/sources-status.php', ['b' => $b, 'sources' => find_sources($pdo, $id, $filters), 'pending' => $pending, 'filters' => $filters,
    'curate' => in_array(base_level($id), ['own', 'curate'], true), 'tz' => member_timezone(), 'here' => '/bases/' . $id . '/sources']);
