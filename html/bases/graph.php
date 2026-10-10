<?php
declare(strict_types=1);
/**
 * /bases/{id}/graph?q=&fresh= (screen `graph`): what the base's graph holds, confined to the base (app/features/graph/queries.php) — its size
 * (the engine's document count for this base and the counts cached on the base), a search, the communities (MaluDB has none yet: the
 * connected subjects stand in), the most connected, the surprising links, and for curators the duplicate-looking subjects. Read-only;
 * each region shows its own engine error and the others still render. ?fresh=1 bypasses the cache for a curator.
 */
require_once dirname(__DIR__, 2) . '/app/features/bases/handler.php';
require_once APP_ROOT . '/app/features/graph/queries.php';
require_right('bases.read');
$pdo = db();
$b = base_from_request($pdo, 'graph');
$id = (int) $b['base_id'];
$level = base_level($id);
$curate = in_array($level, ['own', 'curate'], true);
graph_fresh($curate && request_string('fresh') === '1');
$q = trim(mb_substr(request_string('q'), 0, 200));
$o = graph_overview($pdo, $id);
$search = $q === '' ? null : graph_search($pdo, $id, $q);
if ($search !== null && !$search['ok']) {
    $o['errors']['search'] = $search['code'];
}
$dups = null;
if ($curate) {
    $dups = duplicate_subjects($pdo, $id, graph_ns($pdo, $id));
    if (!$dups['ok']) {
        $o['errors']['duplicates'] = $dups['code'];
        $dups = null;
    }
}
base_screen_view($pdo, 'graph', $id, ['searched' => $q !== '']);
if (wants_json()) {
    respond_screen(['base' => present_base($b)] + present_graph_overview($id, $o, $dups, $q, $search !== null && $search['ok'] ? $search : null) + ['may' => ['curate' => $curate]]);
}
render_screen('Graph · ' . $b['name'], view('graph/index.php', ['b' => $b, 'o' => $o, 'q' => $q, 'search' => $search !== null && $search['ok'] ? $search : null, 'dups' => $dups, 'curate' => $curate,
    'tabs' => base_tabs($id, $level), 'tz' => member_timezone(), 'here' => graph_here()]), ['activeNav' => 'bases', 'screen' => 'graph', 'entity' => 'base', 'recordId' => (string) $id]);
