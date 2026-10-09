<?php
declare(strict_types=1);
/**
 * /bases/{id}/areas (screen `areas`, everyone who reads the base): the active areas as cards; curators also see the proposed ones above with
 * Accept · Dismiss, the sources in no area with an area select, the areas with no source, the archived behind a toggle, New area.
 */
require_once dirname(__DIR__, 2) . '/app/features/areas/handler.php';
require_right('bases.read');
$pdo = db();
$b = base_from_request($pdo, 'areas');
$id = (int) $b['base_id'];
$level = base_level($id);
$curate = in_array($level, ['own', 'curate'], true);
$showArchived = request_bool('archived');
$areas = find_areas($pdo, $id, $showArchived ? ['archived' => true] : []);
if (!$curate) {
    $areas = array_values(array_filter($areas, static fn (array $a): bool => $a['status'] === 'active'));
}
$may = ['manage' => $curate && ($level === 'own' || has_right('base.manage')) && $b['archived_at'] === null, 'curate' => $curate];
$unassigned = $curate ? unassigned_sources($pdo, $id) : [];
$empty = $curate ? empty_areas($pdo, $id) : [];
base_screen_view($pdo, 'areas', $id);
if (wants_json()) {
    respond_screen(['areas' => array_map(static fn (array $a): array => present_area($a, $curate), $areas), 'unassigned' => $unassigned, 'empty' => $empty]);
}
render_screen('Focus areas of ' . $b['name'], view('areas/index.php', ['b' => $b, 'areas' => $areas, 'unassigned' => $unassigned, 'empty' => $empty, 'may' => $may, 'showArchived' => $showArchived,
    'active' => array_values(array_filter($areas, static fn (array $a): bool => $a['status'] === 'active')), 'tabs' => base_tabs($id, $level), 'here' => here_url(),
    'notice' => kn_notice(request_string('notice') ?: null, area_notices())]), ['activeNav' => 'bases', 'screen' => 'areas', 'entity' => 'base', 'recordId' => (string) $id]);
