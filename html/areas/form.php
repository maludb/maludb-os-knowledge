<?php
declare(strict_types=1);
/**
 * /areas/new?base={id} (screen `area-add`) and /areas/{id}/edit (screen `area-edit`): name, description (the project's classifier — "say what
 * belongs here and what does not"), seed terms (a datalist of the base's vocabulary), example questions, position; status on the edit.
 */
require_once dirname(__DIR__, 2) . '/app/features/areas/handler.php';
require_human();
$pdo = db();
$id = request_integer('id');
$a = null;
if ($id !== null) {
    [$a, $b] = area_from_request($pdo, 'area-edit');
} else {
    $b = base_from_request($pdo, 'area-add');
}
$bid = (int) $b['base_id'];
require_base_right($bid, 'base.manage');
require_base_live($b);
if ($a !== null && $a['status'] === 'proposed') {
    refuse(422, 'Accept or dismiss a proposed area first.');
}
$terms = array_column($pdo->query('SELECT canonical_name FROM mcp_vocabulary_terms WHERE base_id = ' . $bid . ' ORDER BY lower(canonical_name)')->fetchAll(), 'canonical_name');
$screen = $a === null ? 'area-add' : 'area-edit';
base_screen_view($pdo, $screen, $bid);
if (wants_json()) {
    respond_screen(['area' => $a === null ? null : present_area($a, true), 'terms' => $terms]);
}
render_screen($a === null ? 'New focus area' : 'Change ' . $a['name'], view('areas/form.php', ['a' => $a, 'b' => $b, 'terms' => $terms]),
    ['activeNav' => 'bases', 'screen' => $screen, 'entity' => 'focus_area', 'recordId' => $a === null ? '' : (string) $a['focus_area_id']]);
