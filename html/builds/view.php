<?php
declare(strict_types=1);
/**
 * /builds/{id} (screen `build-view`, curators): the progress (polling every 5 s while queued or running and the page is visible), the guideline
 * version and the prompt's hash line, the counts, the usage, the error in words, the failed sections with their reasons, the items (200 a page),
 * Cancel while queued or running, the log lines.
 */
require_once dirname(__DIR__, 2) . '/app/features/builds/handler.php';
require_right('bases.read');
$pdo = db();
[$x, $b] = build_from_request($pdo, 'build-view');
$id = (int) $x['build_id'];
$bid = (int) $b['base_id'];
$p = build_progress($pdo, $id) ?? refuse(404, 'Build not found.');
$items = build_items($pdo, $id, max(1, request_integer('page') ?? 1));
$may = ['cancel' => in_array($x['status'], ['queued', 'running'], true) && (base_level($bid) === 'own' || has_right('builds.run'))];
base_screen_view($pdo, 'build-view', $bid, ['build_id' => $id]);
if (wants_json()) {
    respond_screen(['build' => present_build($x), 'progress' => $p, 'items' => array_map(static fn (array $i): array => ['build_item_id' => (int) $i['build_item_id'], 'section_id' => (int) $i['section_id'],
        'heading_path' => $i['heading_path'], 'status' => $i['status'], 'chars' => (int) $i['chars'], 'error' => $i['error']], $items['rows']), 'page' => $items['page'], 'pages' => $items['pages'],
        'log' => build_log_lines($pdo, $id)]);
}
render_screen('Build ' . $id, view('builds/view.php', ['x' => $x, 'b' => $b, 'p' => $p, 'items' => $items, 'may' => $may, 'log' => build_log_lines($pdo, $id), 'tz' => member_timezone(),
    'notice' => kn_notice(request_string('notice') ?: null, build_notices())]), ['activeNav' => 'bases', 'screen' => 'build-view', 'entity' => 'build', 'recordId' => (string) $id]);
