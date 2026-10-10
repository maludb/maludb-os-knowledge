<?php
declare(strict_types=1);

/**
 * The prelude of the proposal screens and handlers (slice 8): slice 1's base prelude and the features an accepted proposal's effect reaches (the
 * guidelines' and vocabulary's writers, the build's, the area's, a source's retry, a pin's review, a feed's sync), the proposal a request names, the
 * log with base_id.
 */
require_once dirname(__DIR__) . '/bases/handler.php';
require_once dirname(__DIR__) . '/guidelines/handler.php';
require_once dirname(__DIR__) . '/builds/handler.php';
require_once dirname(__DIR__) . '/areas/handler.php';
require_once dirname(__DIR__) . '/sources/handler.php';
require_once dirname(__DIR__) . '/vocabulary/queries.php';
require_once dirname(__DIR__) . '/vocabulary/write.php';
require_once dirname(__DIR__) . '/ask/write.php';
require_once dirname(__DIR__) . '/feeds/write.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';
require_once __DIR__ . '/effects.php';

/** The proposal the request names (`proposal` or `id`) and its base: [proposal, base]. A reader of the base is told whose it is (403); unseen is 404. */
function proposal_from_request(PDO $pdo, ?string $screen = null): array
{
    $id = request_integer('proposal') ?? request_integer('id');
    if ($id === null) {
        refuse(422, 'Say which proposal.');
    }
    $p = find_proposal($pdo, $id);
    if ($p === null) {
        $base = one_value($pdo, 'SELECT base_id FROM archivist_proposals WHERE id = :id', ['id' => $id]);
        if ($base !== null && base_level((int) $base) === 'read') {
            refuse(403, 'Proposals are the curators\'.');
        }
        refuse(404, 'Proposal not found.');
    }
    $b = find_base($pdo, (int) $p['base_id']) ?? refuse(404, 'Proposal not found.');
    base_admin_view_log($pdo, $b, $screen ?? request_route());
    return [$p, $b];
}

function proposal_log(PDO $pdo, string $action, int $baseId, int $proposalId, array $after): void
{
    log_activity($pdo, $action, 'archivist_proposal', $proposalId, ['base_id' => $baseId, 'after' => $after]);
}

/** Is the caller an agent (under a run token)? The Archivist proposes and sends the Monday note; its reach is the base's. */
function caller_is_agent(): bool
{
    return (current_member()['member_kind'] ?? '') === 'agent';
}

/** The area proposal's payload as the `cur` row area_fields_from_request() falls back to for a field the form left out. */
function proposal_area_cur(array $payload): array
{
    return ['name' => trim((string) ($payload['name'] ?? '')), 'description' => trim((string) ($payload['description'] ?? '')),
            'seed_terms_list' => array_values(array_map('strval', (array) ($payload['seed_terms'] ?? []))),
            'example_questions_list' => array_values(array_map('strval', (array) ($payload['example_questions'] ?? []))), 'position' => 0];
}
