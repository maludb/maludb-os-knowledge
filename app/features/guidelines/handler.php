<?php
declare(strict_types=1);

/**
 * The prelude of the guideline and vocabulary screens and handlers (slice 3): the feature's files and slice 1's base prelude, the curators'
 * gate for screens ("Guidelines and the vocabulary are the curators'"), the guideline a request names with its base, the log with base_id,
 * and the step every graph-shaping save shares — the version and stale rows logged, the compiled prompt sent best-effort.
 */
require_once dirname(__DIR__) . '/bases/handler.php';
require_once APP_ROOT . '/app/knowledge/compile.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/write.php';

/** A curator's screen: a base one may not read is a 404; a reader is told whose it is (403). */
function require_base_curator_screen(int $baseId): void
{
    require_login();
    $level = base_level($baseId);
    if ($level === 'none') {
        refuse(404, 'Knowledge base not found.');
    }
    if ($level === 'read') {
        refuse(403, 'Guidelines and the vocabulary are the curators\'.');
    }
}

/** The guideline the request names (`guideline` or `id`) and its base: [guideline, base]. 404 when unseen (mcp_guidelines is the curators'). */
function guideline_from_request(PDO $pdo, ?string $screen = null): array
{
    $id = request_integer('guideline') ?? request_integer('id');
    if ($id === null) {
        refuse(422, 'Say which guideline.');
    }
    $g = find_guideline($pdo, $id);
    if ($g === null) {
        $base = one_value($pdo, 'SELECT base_id FROM guidelines WHERE id = :id', ['id' => $id]);
        if ($base !== null && base_level((int) $base) === 'read') {
            refuse(403, 'Guidelines and the vocabulary are the curators\'.');
        }
        refuse(404, 'Guideline not found.');
    }
    $b = find_base($pdo, (int) $g['base_id']) ?? refuse(404, 'Guideline not found.');
    base_admin_view_log($pdo, $b, $screen ?? request_route());
    return [$g, $b];
}

/** A row of the trail about a guideline (or a term, an area): base_id always. */
function guideline_log(PDO $pdo, string $action, int $baseId, string $entityType, ?int $entityId, array $opts = []): void
{
    log_activity($pdo, $action, $entityType, $entityId, ['base_id' => $baseId] + $opts);
}

/** When the base's version moved: guideline.version and one section.stale row (entity base). Inside the caller's transaction. */
function log_version_move(PDO $pdo, int $baseId, array $r): void
{
    if ($r['base_version_after'] === $r['base_version_before']) {
        return;
    }
    guideline_log($pdo, 'guideline.version', $baseId, 'base', $baseId, ['before' => ['version' => $r['base_version_before']], 'after' => ['version' => $r['base_version_after']]]);
    guideline_log($pdo, 'section.stale', $baseId, 'base', $baseId, ['after' => ['count' => $r['stale_marked'], 'stale' => $r['stale_total'], 'version' => $r['base_version_after']]]);
}

/** After a graph-shaping change is committed: the compiled prompt sent best-effort (never refusing the save). Null when nothing moved. */
function send_compiled_after(PDO $pdo, int $baseId, array $r): ?array
{
    if ($r['base_version_after'] === $r['base_version_before']) {
        return null;
    }
    try {
        return kn_compile_send($pdo, $baseId);
    } catch (Throwable $e) {
        error_log('compile after save: ' . $e->getMessage());
        return ['ok' => false, 'code' => 'engine_error'];
    }
}

/** The database's refusals of a guideline, named on the field. */
function guideline_field_refusals(): array
{
    return [
        'guidelines_kind_check'  => ['kind', 'The kind is extraction, vocabulary, exclusion, answering or audience.'],
        'guidelines_title_check' => ['title', 'A title is up to 200 characters.'],
        'guidelines_body_check'  => ['body', 'Write the guideline: 1 to 20,000 characters.'],
        '/never moves between bases/' => ['base', null],
    ];
}

/** The term the request names (`term` or `id`) and its base: [term, base]. A reader of the base is told whose it is (403); unseen is 404. */
function term_from_request(PDO $pdo): array
{
    require_once APP_ROOT . '/app/features/vocabulary/queries.php';
    $id = request_integer('term') ?? request_integer('id');
    if ($id === null) {
        refuse(422, 'Say which term.');
    }
    $t = find_term($pdo, $id);
    if ($t === null) {
        $base = one_value($pdo, 'SELECT base_id FROM vocabulary_terms WHERE id = :id', ['id' => $id]);
        if ($base !== null && base_level((int) $base) === 'read') {
            refuse(403, 'Guidelines and the vocabulary are the curators\'.');
        }
        refuse(404, 'Term not found.');
    }
    $b = find_base($pdo, (int) $t['base_id']) ?? refuse(404, 'Term not found.');
    base_admin_view_log($pdo, $b, request_route());
    return [$t, $b];
}

/** The database's refusals of a term, named on the field. */
function term_field_refusals(string $name = ''): array
{
    return [
        'vocabulary_terms_one_name' => ['canonical_name', 'The base already has the term ' . ($name !== '' ? $name : 'you gave') . '.'],
        'vocabulary_terms_canonical_name_check' => ['canonical_name', 'A term is 1 to 200 characters.'],
        'vocabulary_terms_subject_type_check' => ['subject_type', 'A type is lower-case letters and underscores, starting with a letter, up to 40.'],
    ];
}
