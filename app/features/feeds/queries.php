<?php
declare(strict_types=1);

/** Feeds — the reads of slice 8 (mcp_feeds: the curators'; the admin's everywhere) and the index tools this application may follow. */

/**
 * The index tools a feed may follow: only what maludb-os.json reads[] declares. key => provider, tool, label, arguments (the form's fields), markdown (the second read that
 * brings a record's text when the index carries none: tool + the argument that names the record), noun (what an item is called).
 */
const FEED_TOOL_SPECS = [
    'spaces.pages_index' => ['provider' => 'spaces', 'tool' => 'pages_index', 'label' => 'Spaces — the pages of a space', 'noun' => 'page',
        'arguments' => [['name' => 'space', 'label' => 'Space', 'hint' => 'The space\'s key, as Spaces shows it (for example product).', 'required' => true]],
        'markdown' => ['tool' => 'page_markdown', 'argument' => 'page']],
    'helpdesk.articles_published' => ['provider' => 'helpdesk', 'tool' => 'articles_published', 'label' => 'Help Desk — the published articles', 'noun' => 'article',
        'arguments' => [['name' => 'category', 'label' => 'Category', 'hint' => 'Leave empty for every published article.', 'required' => false]], 'markdown' => null],
];

/** The followable tools that this application declares as reads: [key => spec]. */
function feed_tools(): array
{
    require_once APP_ROOT . '/app/features/connections/queries.php';
    $declared = [];
    foreach (declared_reads() as $r) {
        $declared[$r['app'] . '.' . $r['tool']] = true;
    }
    return array_filter(FEED_TOOL_SPECS, static fn (string $k): bool => isset($declared[$k]), ARRAY_FILTER_USE_KEY);
}

function feed_decode(array $f): array
{
    $f['arguments'] = is_array($f['arguments']) ? $f['arguments'] : (json_decode((string) $f['arguments'], true) ?: []);
    return $f;
}

/** A base's feeds, newest first, each with the area's name. */
function find_feeds(PDO $pdo, int $baseId): array
{
    $st = $pdo->prepare('SELECT f.*, a.name AS area_name FROM mcp_feeds f LEFT JOIN mcp_focus_areas a ON a.focus_area_id = f.focus_area_id WHERE f.base_id = :b ORDER BY f.created_at DESC, f.feed_id DESC');
    $st->execute(['b' => $baseId]);
    return array_map('feed_decode', $st->fetchAll());
}

function find_feed(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT f.*, a.name AS area_name FROM mcp_feeds f LEFT JOIN mcp_focus_areas a ON a.focus_area_id = f.focus_area_id WHERE f.feed_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : feed_decode($r);
}

/** The active areas of a base a feed may put its sources in. */
function feed_areas(PDO $pdo, int $baseId): array
{
    $st = $pdo->prepare("SELECT focus_area_id, name FROM mcp_focus_areas WHERE base_id = :b AND status = 'active' ORDER BY position, lower(name), focus_area_id");
    $st->execute(['b' => $baseId]);
    return $st->fetchAll();
}
