<?php
declare(strict_types=1);
/**
 * /sources/{id} (screen `source-view`): the title, kind, origin (a file with Download, an address as a link, pasted text), the status
 * with the error in words, the counts, built under vN and stale sections, the area chips, the outline (kn_source_outline() nested —
 * each heading a link to its section, a page chip, its characters, a section status chip for curators), the pages (each linking to the
 * first section on it), the curators' Edit · Replace · Remove · Retry, the timeline; a replaced source names its successor.
 */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
require_right('bases.read');
$pdo = db();
[$s, $b] = source_from_request($pdo, 'source-view');
$sid = (int) $s['source_id'];
$bid = (int) $b['base_id'];
$level = base_level($bid);
$curate = in_array($level, ['own', 'curate'], true);
$may = [
    'manage' => $s['status'] !== 'forgotten' && $s['kind'] !== 'feed' && ($level === 'own' || ($level === 'curate' && has_right('sources.manage'))),
    'retry' => $s['status'] === 'failed' && $b['archived_at'] === null && ($level === 'own' || ($level === 'curate' && has_right('sources.add'))),
    'areas' => $s['status'] !== 'forgotten' && $b['archived_at'] === null && ($level === 'own' || ($level === 'curate' && has_right('base.manage'))),
];
$activeAreas = [];
if ($may['areas']) {
    require_once APP_ROOT . '/app/features/areas/queries.php';
    $activeAreas = active_areas($pdo, $bid);
}
base_screen_view($pdo, 'source-view', $bid, ['source_id' => $sid]);
$outline = $s['status'] === 'forgotten' ? [] : source_outline($pdo, $sid);
$pages = $s['status'] === 'forgotten' ? [] : source_pages($pdo, $sid);
$successor = $s['superseded_by'] !== null ? find_source($pdo, (int) $s['superseded_by']) : null;
if (wants_json()) {
    respond_screen(['source' => present_source($s), 'base' => ['base_id' => $bid, 'name' => $b['name']], 'outline' => $outline, 'pages' => $pages, 'may' => $may,
                    'superseded_by' => $successor === null ? null : ['source_id' => (int) $successor['source_id'], 'title' => $successor['title']]]);
}
render_screen($s['title'], view('sources/view.php', ['s' => $s, 'b' => $b, 'outline' => $outline, 'pages' => $pages, 'may' => $may, 'curate' => $curate, 'successor' => $successor,
    'timeline' => source_timeline($pdo, $sid), 'activeAreas' => $activeAreas, 'tz' => member_timezone(), 'here' => here_url(), 'notice' => kn_notice(request_string('notice') ?: null, source_notices($s))]),
    ['activeNav' => 'bases', 'screen' => 'source-view', 'entity' => 'source', 'recordId' => (string) $sid]);
