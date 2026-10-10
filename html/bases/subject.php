<?php
declare(strict_types=1);
/**
 * /bases/{id}/subject?name= (screen `subject-view`): one subject of the base, addressed by its canonical name — its type and description
 * (the engine's), the names it goes by, the statements about it grouped by verb, its neighbours, the sections behind it, a drawing of its
 * neighbourhood, "Ask about this" and "Path to…". A name the engine does not know and a subject the base's documents never touch are the
 * same 404; an engine that cannot be reached is a page that says so (200), not a 404.
 */
require_once dirname(__DIR__, 2) . '/app/features/bases/handler.php';
require_once APP_ROOT . '/app/features/graph/queries.php';
require_right('bases.read');
$pdo = db();
$b = base_from_request($pdo, 'subject-view');
$id = (int) $b['base_id'];
$level = base_level($id);
$curate = in_array($level, ['own', 'curate'], true);
graph_fresh($curate && request_string('fresh') === '1');
$name = trim(mb_substr(request_string('name'), 0, 300));
if ($name === '') {
    refuse(422, 'Say which subject.');
}
$r = graph_subject($pdo, $id, $name) ?? refuse(404, 'Subject not found in this base.');
base_screen_view($pdo, 'subject-view', $id, ['subject_id' => $r['subject']['subject_id']]);
if (wants_json()) {
    respond_screen(['base' => ['base_id' => $id, 'name' => $b['name']]] + present_graph_subject($id, $r));
}
render_screen($r['subject']['name'], view('graph/subject.php', ['b' => $b, 'r' => $r, 'here' => graph_here(), 'back' => back_link()]), ['activeNav' => 'bases', 'screen' => 'subject-view', 'entity' => 'base', 'recordId' => (string) $id]);
