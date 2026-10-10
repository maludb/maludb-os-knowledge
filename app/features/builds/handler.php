<?php
declare(strict_types=1);

/** The prelude of the build screens and handlers (slice 4): the build a request names (mcp_builds — a curator's) with its base, the log with base_id. */
require_once dirname(__DIR__) . '/bases/handler.php';
require_once APP_ROOT . '/app/knowledge/compile.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/write.php';

/** The build the request names (`build` or `id`) and its base: [build, base]. A reader of the base is told whose it is (403); unseen is 404. */
function build_from_request(PDO $pdo, ?string $screen = null): array
{
    $id = request_integer('build') ?? request_integer('id');
    if ($id === null) {
        refuse(422, 'Say which build.');
    }
    $b = find_build($pdo, $id);
    if ($b === null) {
        $base = one_value($pdo, 'SELECT base_id FROM builds WHERE id = :id', ['id' => $id]);
        if ($base !== null && base_level((int) $base) === 'read') {
            refuse(403, 'Builds are the curators\'.');
        }
        refuse(404, 'Build not found.');
    }
    $base = find_base($pdo, (int) $b['base_id']) ?? refuse(404, 'Build not found.');
    base_admin_view_log($pdo, $base, $screen ?? request_route());
    return [$b, $base];
}

function build_log(PDO $pdo, string $action, int $baseId, int $buildId, array $opts = []): void
{
    log_activity($pdo, $action, 'build', $buildId, ['base_id' => $baseId] + $opts);
}

/**
 * The refusals before a build is queued, in the spec's order, each a 422 sentence: the model not set up, nothing to build, a build running,
 * the month spent, an agent's build that does not fit. Returns the estimate.
 */
function build_start_gate(PDO $pdo, array $b, string $kind): array
{
    $bid = (int) $b['base_id'];
    require_base_live($b);
    if (!kn_compile_connection()['ok']) {
        refuse(422, BUILD_ERRORS['model_not_configured']);
    }
    $est = build_estimate($pdo, $bid, $kind) ?? refuse(403, 'You may not start a build.');
    if ((int) $est['sections'] === 0) {
        refuse(422, 'Nothing to build — every section is in the graph under version ' . (int) $est['guideline_version'] . '.');
    }
    if ($est['running_build_id'] !== null) {
        refuse(422, 'A build is already running.');
    }
    if ((int) $est['remaining_chars'] === 0) {
        refuse(422, 'This month\'s text limit for this base is spent.');
    }
    if (current_agent_run_id() !== null && empty($est['within_limit'])) {
        refuse(422, 'An agent\'s build must fit this month\'s limit: it needs ' . number_format((int) $est['characters']) . ' characters, ' . number_format((int) $est['remaining_chars']) . ' remain.');
    }
    return $est;
}

/** Queue it (one transaction) and log; the database's unique index is the last word on a second build. */
function build_start_commit(PDO $pdo, array $b, string $kind, string $event, array $est): never
{
    $bid = (int) $b['base_id'];
    $me = (int) current_member_id();
    $sha = kn_compile_prompt($pdo, $bid)['sha256'];
    $r = kn_guard_fields($pdo, static function () use ($pdo, $bid, $kind, $me, $event, $est, $sha): array {
        $pdo->beginTransaction();
        $r = start_build($pdo, $bid, $kind, $me);
        build_log($pdo, $event, $bid, $r['build_id'], ['after' => ['build_id' => $r['build_id'], 'kind' => $kind, 'sections' => $r['sections'], 'characters' => $r['characters'],
            'guideline_version' => (int) $est['guideline_version'], 'prompt_sha256' => $sha, 'within_limit' => (bool) $est['within_limit'], 'auto' => false]]);
        $pdo->commit();
        return $r;
    }, ['builds_one_live_per_base' => ['build', 'A build is already running.'], '/build is already queued or running/' => ['build', 'A build is already running.'], '/archived base is not built/' => ['base', null]]);
    kn_done(($kind === 'rebuild_all' ? 'Rebuilding everything: ' : 'Building ') . $r['sections'] . ' section' . ($r['sections'] === 1 ? '' : 's') . ', ' . number_format($r['characters']) . ' characters',
        $r['build_id'], kn_land('/builds/' . $r['build_id'], 'started'), 'buildsChanged', ['base_id' => $bid, 'sections' => $r['sections'], 'characters' => $r['characters']]);
}
