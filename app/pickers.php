<?php
declare(strict_types=1);

/**
 * app/pickers.php — the registry of what a form can pick (app/picker.php has the contract; design-system record-picker.md).
 * One entry per record kind, added by the slice whose form chooses it:
 *   key => ['title', 'noun', 'roles' => [Knowledge rights, any one admits — has_right()], 'params' => [filter keys the endpoint forwards, as integers],
 *           'search' => fn(PDO, string $q, array $params, int $page): array{rows, total, page, page_size, pages},   rows: [id, label, detail?, badge?, color?]
 *           'label'  => fn(PDO, int $id): ?string]
 * `search` reuses the feature's own list query (find_*() with ILIKE and paging), never a second one; `label` names the chosen record.
 *
 * Built:
 *   person   — slice 1: the people and agents a base is shared with (base-share's first form; never a guest), over mcp_members
 *   guest    — slice 1: the guests a base is shared with (base-share's guest form), over mcp_members
 * The slices add (see docs/knowledge-design.md and the ten slice specs):
 *   base     — a form that chooses a knowledge base (copy a guideline set from, move a source to, a key's base)
 *   source   — slice 3: a live source of the area's base not yet in it (area-view's Add a source; param `area`), over mcp_sources
 *   section  — slice 2/5, a section (pin an answer to, link a guideline to)
 *   guideline— slice 3, a guideline (copy from another base, compile)
 * A short enumeration (a kind, a status, a role, a guideline type) stays a <select>.
 * The roles: an owner who holds only Member shares their own base (bases.create admits them); a curator holds base.share.
 */
require_once __DIR__ . '/features/bases/queries.php';
require_once __DIR__ . '/features/areas/queries.php';

return [
    'person' => [
        'title' => 'A person or an agent', 'noun' => 'person', 'roles' => ['bases.create', 'base.share'], 'params' => [],
        'search' => static fn (PDO $pdo, string $q, array $params, int $page): array => search_share_principals($pdo, $q, false, $page),
        'label' => static fn (PDO $pdo, int $id): ?string => share_principal_label($pdo, $id, false),
    ],
    'guest' => [
        'title' => 'A guest', 'noun' => 'guest', 'roles' => ['bases.create', 'base.share'], 'params' => [],
        'search' => static fn (PDO $pdo, string $q, array $params, int $page): array => search_share_principals($pdo, $q, true, $page),
        'label' => static fn (PDO $pdo, int $id): ?string => share_principal_label($pdo, $id, true),
    ],
    'source' => [
        'title' => 'A source of this base', 'noun' => 'source', 'roles' => ['bases.create', 'base.manage'], 'params' => ['area'],
        'search' => static fn (PDO $pdo, string $q, array $params, int $page): array => search_area_candidates($pdo, (int) ($params['area'] ?? 0), $q, $page),
        'label' => static fn (PDO $pdo, int $id): ?string => ($v = one_value($pdo, 'SELECT title FROM mcp_sources WHERE source_id = :id', ['id' => $id])) === null ? null : (string) $v,
    ],
];
