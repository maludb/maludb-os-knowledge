<?php
declare(strict_types=1);
/**
 * /areas/{id} (screen `area-view`): the description, the seed terms, the example questions (each "Ask this"), the sources in the area with
 * Remove (curators) and Add a source (the record picker), the sections built before their source joined, the engine link (curators), the
 * timeline.
 */
require_once dirname(__DIR__, 2) . '/app/features/areas/handler.php';
require_right('bases.read');
$pdo = db();
[$a, $b] = area_from_request($pdo, 'area-view');
$aid = (int) $a['focus_area_id'];
$bid = (int) $b['base_id'];
$level = base_level($bid);
$curate = in_array($level, ['own', 'curate'], true);
if (!$curate && $a['status'] !== 'active') {
    refuse(404, 'Focus area not found.');
}
$may = ['manage' => $curate && ($level === 'own' || has_right('base.manage')) && $b['archived_at'] === null, 'curate' => $curate];
base_screen_view($pdo, 'area-view', $bid, ['focus_area_id' => $aid]);
$sources = area_sources($pdo, $aid);
if (wants_json()) {
    respond_screen(['area' => present_area($a, $curate), 'sources' => array_map(static fn (array $s): array => ['source_id' => (int) $s['source_id'], 'title' => $s['title'], 'kind' => $s['kind']], $sources),
                    'sections_built_before' => $curate ? area_sections_built_before($pdo, $aid) : null]);
}
render_screen($a['name'], view('areas/view.php', ['a' => $a, 'b' => $b, 'sources' => $sources, 'may' => $may, 'before' => $curate ? area_sections_built_before($pdo, $aid) : 0,
    'timeline' => area_timeline($pdo, $aid), 'tz' => member_timezone(), 'notice' => kn_notice(request_string('notice') ?: null, area_notices())]),
    ['activeNav' => 'bases', 'screen' => 'area-view', 'entity' => 'focus_area', 'recordId' => (string) $aid]);
