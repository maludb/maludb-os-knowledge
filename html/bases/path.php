<?php
declare(strict_types=1);
/**
 * /bases/{id}/path?from=&to= (screen `path`): how two subjects are related inside the base — two names, the engine's shortest path wholly
 * inside the base's documents, as a chain of subjects and sections. A path that runs through another base's document (or an end that is not
 * this base's) is withheld in the same words as no path, never naming the outside node.
 */
require_once dirname(__DIR__, 2) . '/app/features/bases/handler.php';
require_once APP_ROOT . '/app/features/graph/queries.php';
require_right('bases.read');
$pdo = db();
$b = base_from_request($pdo, 'path');
$id = (int) $b['base_id'];
graph_fresh(graph_curates($id) && request_string('fresh') === '1');
$from = trim(mb_substr(request_string('from'), 0, 300));
$to = trim(mb_substr(request_string('to'), 0, 300));
$p = graph_path($pdo, $id, $from, $to);
$asked = $from !== '' && $to !== '';
$god = graph_region_god_nodes($pdo, $id, 20, true);
$suggest = $god['ok'] ? array_values(array_unique(array_column($god['nodes'], 'name'))) : [];
base_screen_view($pdo, 'path', $id, $asked ? ['found' => $p['path'] !== null, 'withheld' => (bool) $p['withheld']] : ['found' => false, 'withheld' => false]);
if (wants_json()) {
    respond_screen(['base' => ['base_id' => $id, 'name' => $b['name']]] + present_graph_path($id, $p) + ['asked' => $asked, 'suggestions' => $suggest]);
}
render_screen('Path · ' . $b['name'], view('graph/path.php', ['b' => $b, 'p' => $p, 'asked' => $asked, 'suggest' => $suggest, 'tabs' => base_tabs($id, base_level($id)), 'back' => back_link()]),
    ['activeNav' => 'bases', 'screen' => 'path', 'entity' => 'base', 'recordId' => (string) $id]);
