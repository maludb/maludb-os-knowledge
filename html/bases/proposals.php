<?php
declare(strict_types=1);
/**
 * /bases/{id}/proposals (screen `proposals`, questions.review on a curated base): the Archivist's proposals for the base as cards — the kind, the title, the reason, the subject (a link to
 * its screen), who proposed and when, the payload in words — in three tabs (proposed · accepted · dismissed) and a kind filter; on a proposed one Accept (its form by kind: a merge's tick boxes,
 * an area's fields to edit, a build's estimate) and Dismiss (an optional reason).
 */
require_once dirname(__DIR__, 2) . '/app/features/proposals/handler.php';
require_human();
$pdo = db();
$b = base_from_request($pdo, 'proposals');
$id = (int) $b['base_id'];
require_base_right($id, 'questions.review');
$f = ['status' => request_string('status') ?: 'proposed', 'kind' => request_string('kind')];
$rows = find_proposals($pdo, $id, $f);
$counts = [];
foreach (PROPOSAL_STATUSES as $s) {
    $counts[$s] = (int) one_value($pdo, 'SELECT count(*) FROM mcp_archivist_proposals WHERE base_id = :b AND status = :s', ['b' => $id, 's' => $s]);
}
$may = ['decide' => $b['archived_at'] === null];
$estimates = $b['archived_at'] === null ? proposal_estimates($pdo, $rows) : [];
base_screen_view($pdo, 'proposals', $id);
if (wants_json()) {
    respond_screen(['base' => present_base($b), 'status' => $f['status'], 'counts' => $counts, 'proposals' => array_map('present_proposal', $rows)]);
}
render_screen('Proposals for ' . $b['name'], view('proposals/index.php', ['b' => $b, 'rows' => $rows, 'f' => $f, 'counts' => $counts, 'may' => $may, 'estimates' => $estimates, 'tabs' => base_tabs($id, base_level($id)), 'tz' => member_timezone(),
    'notice' => kn_notice(request_string('notice') ?: null, proposal_notices())]), ['activeNav' => 'bases', 'screen' => 'proposals', 'entity' => 'base', 'recordId' => (string) $id]);
