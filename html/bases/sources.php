<?php
declare(strict_types=1);
/**
 * /bases/{id}/sources (screen `sources`): the base's sources as cards (mcp_sources) — kind, title, status, sections, characters, pages,
 * areas, stale / failed / no text, added by and when; filters kind, status (removed included), area, search; Add documents for whoever
 * may add; while a source is being read the cards poll /bases/{id}/sources-status every 5 s (the partial answers 286 when nothing is
 * pending, which stops the polling). A Reader sees the cards and no add button.
 */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
require_right('bases.read');
$pdo = db();
$b = base_from_request($pdo, 'sources');
$id = (int) $b['base_id'];
$level = base_level($id);
$filters = ['kind' => request_string('kind'), 'status' => request_string('status'), 'area' => request_integer('area'), 'q' => mb_substr(request_string('q'), 0, 80)];
$sources = find_sources($pdo, $id, $filters);
$pending = pending_sources($pdo, $id);
$may = ['add' => $b['archived_at'] === null && ($level === 'own' || ($level === 'curate' && has_right('sources.add'))), 'curate' => in_array($level, ['own', 'curate'], true)];
base_screen_view($pdo, 'sources', $id);
if (wants_json()) {
    respond_screen(['base' => present_base($b), 'sources' => array_map('present_source', $sources), 'pending' => count($pending), 'filters' => $filters, 'may' => $may]);
}
render_screen('Sources of ' . $b['name'], view('sources/index.php', ['b' => $b, 'sources' => $sources, 'pending' => $pending, 'filters' => $filters, 'may' => $may,
    'areas' => source_area_names($pdo, $id), 'tabs' => base_tabs($id, $level), 'tz' => member_timezone(), 'here' => here_url(),
    'notice' => kn_notice(request_string('notice') ?: null, source_notices())]),
    ['activeNav' => 'bases', 'screen' => 'sources', 'entity' => 'base', 'recordId' => (string) $id]);
