<?php
declare(strict_types=1);

/**
 * The prelude of every source and section screen and handler (slice 2, THE FIRST EXEMPLAR): the feature's files and slice 1's base
 * prelude, the source a request names (through mcp_sources — 404 when unseen) with its base (the admin's look logged as slice 1 logs
 * it), the section likewise, and the one logger that stamps base_id on every row.
 */
require_once dirname(__DIR__) . '/bases/handler.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/write.php';

/** The source the request names (`source`, `source_id` or `id`) and its base: [source, base]. 404 "Source not found." when unseen. */
function source_from_request(PDO $pdo, ?string $screen = null): array
{
    $id = request_integer('source') ?? request_integer('source_id') ?? request_integer('id');
    if ($id === null) {
        refuse(422, 'Say which source.');
    }
    $s = find_source($pdo, $id) ?? refuse(404, 'Source not found.');
    $b = find_base($pdo, (int) $s['base_id']) ?? refuse(404, 'Source not found.');
    base_admin_view_log($pdo, $b, $screen ?? request_route());
    return [$s, $b];
}

/** The section the request names (`section` or `id`) and its base: [section, base]. 404 "Section not found." when unseen. */
function section_from_request(PDO $pdo, ?string $screen = null): array
{
    $id = request_integer('section') ?? request_integer('id');
    if ($id === null) {
        refuse(422, 'Say which section.');
    }
    $x = find_section($pdo, $id) ?? refuse(404, 'Section not found.');
    $b = find_base($pdo, (int) $x['base_id']) ?? refuse(404, 'Section not found.');
    base_admin_view_log($pdo, $b, $screen ?? request_route());
    return [$x, $b];
}

/** A row of the trail about a source: base_id on every one; entity source unless named. */
function source_log(PDO $pdo, string $action, int $baseId, int $sourceId, array $opts = []): void
{
    log_activity($pdo, $action, 'source', $sourceId, ['base_id' => $baseId] + $opts);
}

/** The database's refusals of a source's own fields, named on the field. */
function source_field_refusals(): array
{
    return [
        'sources_title_check'    => ['title', 'Give the source a title of up to 300 characters.'],
        'sources_language_check' => ['language', 'The language is an ISO code such as en, fr or pt-BR.'],
        '/archived base takes no sources/' => ['base', null],
        '/forgotten source stays forgotten/' => ['source', null],
    ];
}
