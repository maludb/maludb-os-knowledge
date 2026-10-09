<?php
declare(strict_types=1);
/**
 * /bases/{id}/guidelines (screen `guidelines`, curators): the five kinds in order, each a group with its line and its guidelines (title, the
 * body's first two lines, origin, version; an inactive one greyed), Add a {kind} guideline; the head says what the graph is built under,
 * a needs-a-rebuild box when sections are stale, the compiled prompt's warnings, and See what the engine is told.
 */
require_once dirname(__DIR__, 2) . '/app/features/guidelines/handler.php';
require_right('bases.read');
$pdo = db();
$b = base_from_request($pdo, 'guidelines');
$id = (int) $b['base_id'];
require_base_curator_screen($id);
$groups = find_guidelines($pdo, $id);
$built = built_under($pdo, $id);
$compiled = kn_compile_prompt($pdo, $id);
$level = base_level($id);
$may = ['manage' => $level === 'own' || has_right('base.manage')];
base_screen_view($pdo, 'guidelines', $id);
if (wants_json()) {
    respond_screen(['base' => present_base($b), 'guidelines' => array_map(static fn (array $rows): array => array_map('present_guideline', $rows), $groups), 'built_under' => $built,
                    'compiled' => ['sha256' => $compiled['sha256'], 'lines' => $compiled['lines'], 'truncated' => $compiled['truncated'], 'conflicts' => $compiled['conflicts']]]);
}
render_screen('Guidelines of ' . $b['name'], view('guidelines/index.php', ['b' => $b, 'groups' => $groups, 'built' => $built, 'compiled' => $compiled, 'may' => $may,
    'tabs' => base_tabs($id, $level), 'tz' => member_timezone(), 'notice' => kn_notice(request_string('notice') ?: null, guideline_notices($built))]),
    ['activeNav' => 'bases', 'screen' => 'guidelines', 'entity' => 'base', 'recordId' => (string) $id]);
