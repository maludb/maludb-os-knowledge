<?php
declare(strict_types=1);
/**
 * /guidelines/{id} (screen `guideline-view`, curators): the guideline's versions, newest first — version, saved by, when, active, the base's
 * version after the save, the body in a <details>; the version the graph was built under is marked; Edit · Delete.
 */
require_once dirname(__DIR__, 2) . '/app/features/guidelines/handler.php';
require_right('bases.read');
$pdo = db();
[$g, $b] = guideline_from_request($pdo, 'guideline-view');
$bid = (int) $b['base_id'];
$versions = guideline_versions($pdo, (int) $g['guideline_id']);
$built = built_under($pdo, $bid)['built_version'];
$builtVersionNo = null;                                              // the latest save at or below the version the sections were built under
if ($built !== null) {
    foreach ($versions as $v) {
        if ((int) $v['base_guideline_version'] <= $built) {
            $builtVersionNo = (int) $v['version_no'];
            break;
        }
    }
}
$may = ['manage' => base_level($bid) === 'own' || has_right('base.manage')];
base_screen_view($pdo, 'guideline-view', $bid, ['guideline_id' => (int) $g['guideline_id']]);
if (wants_json()) {
    respond_screen(['guideline' => present_guideline($g), 'versions' => array_map(static fn (array $v): array => ['version_no' => (int) $v['version_no'], 'kind' => $v['kind'], 'title' => $v['title'],
        'body' => $v['body'], 'is_active' => (bool) $v['is_active'], 'base_guideline_version' => (int) $v['base_guideline_version'], 'saved_by' => $v['saved_by_name'], 'saved_at' => json_ts($v['saved_at'])], $versions),
        'built_version_no' => $builtVersionNo]);
}
render_screen($g['title'] !== '' ? $g['title'] : 'Guideline', view('guidelines/view.php', ['g' => $g, 'b' => $b, 'versions' => $versions, 'builtVersionNo' => $builtVersionNo, 'may' => $may,
    'timeline' => guideline_timeline($pdo, (int) $g['guideline_id']), 'tz' => member_timezone()]), ['activeNav' => 'bases', 'screen' => 'guideline-view', 'entity' => 'guideline', 'recordId' => (string) $g['guideline_id']]);
