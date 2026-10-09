<?php
declare(strict_types=1);
/**
 * /guidelines/new?base={id}&kind= (screen `guideline-add`) and /guidelines/{id}/edit (screen `guideline-edit`): kind, title, body, active,
 * position; the kind's line and three examples beside it (below at 375); Preview on one of the base's sections (HTMX into #preview, or a
 * full page without JavaScript — html/guidelines/preview.php renders this same form with the preview filled).
 */
require_once dirname(__DIR__, 2) . '/app/features/guidelines/handler.php';
require_human();
$pdo = db();
$id = request_integer('id');
if ($id !== null) {
    [$g, $b] = guideline_from_request($pdo, 'guideline-edit');
} else {
    $g = null;
    $b = base_from_request($pdo, 'guideline-add');
}
$bid = (int) $b['base_id'];
require_base_right($bid, 'base.manage');
require_base_live($b);
$kind = $g['kind'] ?? request_string('kind', 'extraction');
if (!isset(GUIDELINE_KINDS[$kind])) {
    $kind = 'extraction';
}
$draft = $g === null ? ['kind' => $kind, 'title' => '', 'body' => '', 'is_active' => true, 'position' => null]
    : ['kind' => $g['kind'], 'title' => $g['title'], 'body' => $g['body'], 'is_active' => (bool) $g['is_active'], 'position' => (int) $g['position']];
base_screen_view($pdo, $g === null ? 'guideline-add' : 'guideline-edit', $bid);
$sections = preview_sections($pdo, $bid);
if (wants_json()) {
    respond_screen(['guideline' => $g === null ? null : present_guideline($g), 'base' => present_base($b), 'kinds' => array_keys(GUIDELINE_KINDS), 'examples' => GUIDELINE_EXAMPLES,
                    'sections' => array_map(static fn (array $x): array => ['section_id' => (int) $x['section_id'], 'heading_path' => $x['heading_path']], $sections)]);
}
render_screen($g === null ? 'Add a guideline' : 'Change a guideline', view('guidelines/form.php', ['g' => $g, 'b' => $b, 'draft' => $draft, 'sections' => $sections,
    'section' => request_integer('section'), 'preview' => null, 'stale' => built_under($pdo, $bid)['total']]),
    ['activeNav' => 'bases', 'screen' => $g === null ? 'guideline-add' : 'guideline-edit', 'entity' => 'guideline', 'recordId' => $g === null ? '' : (string) $g['guideline_id']]);
