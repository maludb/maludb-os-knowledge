<?php
declare(strict_types=1);

/**
 * The prelude of the focus-area screens and handlers (slice 3): the feature's files and slice 1's base prelude, the area a request names
 * (through mcp_focus_areas — 404 when unseen) with its base, the log with base_id, the engine sync after a save (best-effort).
 */
require_once dirname(__DIR__) . '/bases/handler.php';
require_once APP_ROOT . '/app/knowledge/areas.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/write.php';

/** The area the request names (`focus_area`, `area` or `id`) and its base: [area, base]. */
function area_from_request(PDO $pdo, ?string $screen = null): array
{
    $id = request_integer('focus_area') ?? request_integer('area') ?? request_integer('id');
    if ($id === null) {
        refuse(422, 'Say which focus area.');
    }
    $a = find_area($pdo, $id) ?? refuse(404, 'Focus area not found.');
    $b = find_base($pdo, (int) $a['base_id']) ?? refuse(404, 'Focus area not found.');
    base_admin_view_log($pdo, $b, $screen ?? request_route());
    return [$a, $b];
}

function area_log(PDO $pdo, string $action, int $baseId, int $areaId, array $opts = []): void
{
    log_activity($pdo, $action, 'focus_area', $areaId, ['base_id' => $baseId] + $opts);
}

/** After a commit: the area's project brought in step, never refusing the save. */
function area_sync_best_effort(PDO $pdo, int $areaId): array
{
    try {
        return kn_area_engine_sync($pdo, $areaId);
    } catch (Throwable $e) {
        error_log('area sync: ' . $e->getMessage());
        return ['ok' => false, 'code' => 'engine_error', 'project_id' => null];
    }
}

/** The fields of an area from the request (save and propose): name, description, seed terms, example questions, position. A field left out keeps $cur's. */
function area_fields_from_request(?array $cur): array
{
    $errors = [];
    $f = [];
    $f['name'] = req_has('name') ? trim((string) req_val('name')) : (string) ($cur['name'] ?? '');
    if ($f['name'] === '' || mb_strlen($f['name']) > 120) {
        $errors['name'] = 'Give the area a name of up to 120 characters.';
    }
    $f['description'] = req_has('description') ? trim((string) ($_POST['description'] ?? '')) : (string) ($cur['description'] ?? '');
    if (mb_strlen($f['description']) > 20000) {
        $errors['description'] = 'A description is up to 20,000 characters.';
    }
    $f['seed_terms'] = req_has('seed_terms') ? area_lines(is_array($_POST['seed_terms']) ? implode("\n", $_POST['seed_terms']) : (($l = kn_pg_literal_list($_POST['seed_terms'])) !== null ? implode("\n", $l) : (string) $_POST['seed_terms'])) : ($cur['seed_terms_list'] ?? []);
    if (count($f['seed_terms']) > 30 || array_filter($f['seed_terms'], static fn ($t) => mb_strlen($t) > 200) !== []) {
        $errors['seed_terms'] = 'At most 30 seed terms, each up to 200 characters.';
    }
    $f['example_questions'] = req_has('example_questions') ? area_lines(is_array($_POST['example_questions']) ? implode("\n", $_POST['example_questions']) : (($l = kn_pg_literal_list($_POST['example_questions'])) !== null ? implode("\n", $l) : (string) $_POST['example_questions'])) : ($cur['example_questions_list'] ?? []);
    if (count($f['example_questions']) > 10 || array_filter($f['example_questions'], static fn ($t) => mb_strlen($t) > 300) !== []) {
        $errors['example_questions'] = 'At most 10 example questions, each up to 300 characters.';
    }
    $f['position'] = (int) ($cur['position'] ?? 0);
    if (req_has('position') && (string) req_val('position') !== '') {
        $f['position'] = (int) kn_int('position', $f['position'], 0, 100000, 'The position', $errors);
    }
    return [$f, $errors];
}

/** The database's refusals of an area, named on the field. */
function area_field_refusals(string $name = ''): array
{
    return [
        'focus_areas_one_slug' => ['name', 'The base already has an area called ' . ($name !== '' ? $name : 'that') . '.'],
        'focus_areas_name_check' => ['name', 'Give the area a name of up to 120 characters.'],
        '/area of its own base/' => ['focus_area', null],
    ];
}
