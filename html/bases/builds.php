<?php
declare(strict_types=1);
/**
 * /bases/{id}/builds (screen `builds`, curators): the estimate (sections new · stale · failed, characters, about N engine calls, this month
 * against the limit), Start the build (disabled with the reason), Rebuild everything (its own estimate, confirmed), a running build's
 * progress, auto-extract as a line, the builds as a table.
 */
require_once dirname(__DIR__, 2) . '/app/features/builds/handler.php';
require_right('bases.read');
$pdo = db();
$b = base_from_request($pdo, 'builds');
$id = (int) $b['base_id'];
$level = base_level($id);
if ($level === 'read') {
    refuse(403, 'Builds are the curators\'.');
}
$est = build_estimate($pdo, $id);
$all = build_estimate($pdo, $id, 'rebuild_all');
$conn = kn_compile_connection();
$blocker = build_start_blocker($b, $est, $conn);
$may = ['run' => $level === 'own' || has_right('builds.run')];
$builds = find_builds($pdo, $id);
$running = $est !== null && $est['running_build_id'] !== null ? build_progress($pdo, (int) $est['running_build_id']) : null;
base_screen_view($pdo, 'builds', $id);
if (wants_json()) {
    respond_screen(['base' => present_base($b), 'estimate' => $est + ['calls' => build_call_estimate($pdo, $id)], 'rebuild_all' => $all + ['calls' => build_call_estimate($pdo, $id, 'rebuild_all')],
                    'blocker' => $blocker, 'builds' => array_map('present_build', $builds), 'running' => $running]);
}
render_screen('Builds of ' . $b['name'], view('builds/index.php', ['b' => $b, 'est' => $est, 'all' => $all, 'calls' => build_call_estimate($pdo, $id), 'callsAll' => build_call_estimate($pdo, $id, 'rebuild_all'),
    'blocker' => $blocker, 'may' => $may, 'builds' => $builds, 'running' => $running, 'tabs' => base_tabs($id, $level), 'tz' => member_timezone(),
    'notice' => kn_notice(request_string('notice') ?: null, build_notices())]), ['activeNav' => 'bases', 'screen' => 'builds', 'entity' => 'base', 'recordId' => (string) $id]);
