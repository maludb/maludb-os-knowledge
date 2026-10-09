<?php
declare(strict_types=1);
/**
 * /bases/{id} (screen `base-view`): kn_base_overview() — name, description, status, visibility, the owner and the curators, counts,
 * areas, the last build, an ask box; an EMPTY base shows one primary button, "Add documents", above everything else; the base's tabs;
 * a curator's extras (guidelines by kind, vocabulary, keys, proposals, gaps, the build estimate); Edit · Share · Settings ·
 * Archive/Restore · Delete by level; the timeline. The admin's look at a private base is logged (base_from_request).
 */
require_once dirname(__DIR__, 2) . '/app/features/bases/handler.php';
require_right('bases.read');
$pdo = db();
$b = base_from_request($pdo, 'base-view');
$id = (int) $b['base_id'];
$o = base_overview($pdo, $id) ?? refuse(404, 'Knowledge base not found.');
$level = (string) $o['my_level'];
$curate = in_array($level, ['own', 'curate'], true);
$may = [
    'manage' => $level === 'own' || ($level === 'curate' && has_right('base.manage')),
    'share' => $level === 'own' || ($level === 'curate' && has_right('base.share')),
    'delete' => $level === 'own',
    'add_sources' => $curate && $b['archived_at'] === null,
];
base_screen_view($pdo, 'base-view', $id);
$timeline = base_timeline($pdo, $id);
if (wants_json()) {
    $out = present_base($b) + ['overview' => $o, 'may' => $may, 'tabs' => array_map(static fn (array $t): array => ['label' => $t[0], 'url' => $t[1]], base_tabs($id, $level))];
    respond_screen($out);
}
$liveBuild = $b['archived_at'] !== null ? base_live_build($pdo, $id) : null;
render_screen($b['name'], view('bases/view.php', ['b' => $b, 'o' => $o, 'level' => $level, 'curate' => $curate, 'may' => $may, 'timeline' => $timeline,
    'deleting' => $liveBuild !== null && $liveBuild['kind'] === 'forget', 'tabs' => base_tabs($id, $level), 'tz' => member_timezone(), 'here' => here_url(),
    'notice' => kn_notice(request_string('notice') ?: null, base_notices($b))]),
    ['activeNav' => 'bases', 'screen' => 'base-view', 'entity' => 'base', 'recordId' => (string) $id]);
