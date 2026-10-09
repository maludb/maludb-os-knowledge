<?php
declare(strict_types=1);

/**
 * Bases — what a screen shows and what JSON answers (whitelists, never raw rows), the base's tabs, the notices.
 *
 * BASE_TABS: one row per tab — [label, path ({id} = the base), the controller under html/, the level that sees it]. A tab is shown
 * only when its controller exists and is not a placeholder (render_nav_stub), and the person's level allows it: a later slice
 * lights its tab by existing, and no tab ever 404s.
 */
const BASE_TABS = [
    'overview'   => ['Overview',   '/bases/{id}',            '/bases/view.php',       'read'],
    'ask'        => ['Ask',        '/ask?base={id}',         '/ask.php',              'read'],      // slice 5
    'sources'    => ['Sources',    '/bases/{id}/sources',    '/bases/sources.php',    'read'],      // slice 2
    'areas'      => ['Areas',      '/bases/{id}/areas',      '/bases/areas.php',      'read'],      // slice 3
    'guidelines' => ['Guidelines', '/bases/{id}/guidelines', '/bases/guidelines.php', 'curate'],    // slice 3
    'builds'     => ['Builds',     '/bases/{id}/builds',     '/bases/builds.php',     'curate'],    // slice 4
    'graph'      => ['Graph',      '/bases/{id}/graph',      '/bases/graph.php',      'read'],      // slice 6
    'questions'  => ['Questions',  '/bases/{id}/questions',  '/bases/questions.php',  'curate'],    // slice 5
    'keys'       => ['Keys',       '/bases/{id}/keys',       '/bases/keys.php',       'curate'],    // slice 7
    'share'      => ['Share',      '/bases/{id}/share',      '/bases/share.php',      'curate'],    // slice 1
    'settings'   => ['Settings',   '/bases/{id}/settings',   '/bases/settings.php',   'curate'],    // slice 1
];

/** Is a tab's controller built (present, and not the shell's placeholder)? */
function base_tab_built(string $file): bool
{
    static $cache = [];
    if (!array_key_exists($file, $cache)) {
        $path = APP_ROOT . '/html' . $file;
        $src = is_file($path) ? (string) file_get_contents($path) : null;
        $cache[$file] = $src !== null && !str_contains($src, 'render_nav_stub(');
    }
    return $cache[$file];
}

/** The tabs this person sees on this base: [key => [label, url]]. $level: own | curate | read. */
function base_tabs(int $baseId, string $level): array
{
    $tabs = [];
    foreach (BASE_TABS as $key => [$label, $path, $file, $who]) {
        if (!base_tab_built($file) || ($who === 'curate' && !in_array($level, ['own', 'curate'], true))) {
            continue;
        }
        $tabs[$key] = [$label, str_replace('{id}', (string) $baseId, $path)];
    }
    return $tabs;
}

/** The chip of a status / visibility / level: [words, colour]. */
function base_status_chip(string $status): array
{
    return [str_replace('_', ' ', $status === 'needs_rebuild' ? 'needs rebuild' : $status), BASE_STATUSES[$status] ?? 'secondary'];
}

function base_visibility_chip(string $v): array
{
    return [$v, $v === 'internal' ? 'light' : 'dark'];
}

function base_level_chip(string $level): array
{
    return [$level, BASE_LEVELS[$level] ?? 'light'];
}

/** The chip of a share row's principal: [words, colour]. */
function principal_chip(array $m): array
{
    if (!empty($m['is_guest'])) {
        return ['guest', 'warning'];
    }
    return match ($m['principal_kind']) {
        'member' => ['person', 'secondary'], 'agent' => ['agent', 'info'], 'department' => ['department', 'primary'],
        'application' => ['application', 'secondary'], default => ['everyone', 'light'],
    };
}

/** A badge from [words, colour]: soft background, and a light chip in dark text. */
function chip(array $c, string $id = ''): string
{
    [$words, $colour] = $c;
    $cls = in_array($colour, ['light', 'dark'], true) ? 'bg-' . $colour . ($colour === 'light' ? ' text-dark border' : ' text-white') : 'bg-soft-' . $colour . ' text-' . $colour;
    return '<span class="badge ' . $cls . '"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>' . e($words) . '</span>';
}

/** A base for JSON: the card's fields (mcp_bases), never more. */
function present_base(array $b): array
{
    return [
        'base_id' => (int) $b['base_id'], 'key' => $b['key'], 'name' => $b['name'], 'description' => $b['description'],
        'template' => $b['template'], 'visibility' => $b['visibility'], 'status' => $b['status'], 'my_level' => $b['my_level'],
        'owner_member_id' => (int) $b['owner_member_id'], 'owner_name' => $b['owner_name'], 'namespace' => $b['namespace'],
        'auto_extract' => (bool) $b['auto_extract'], 'sensitivity' => $b['sensitivity'], 'language' => $b['language'],
        'monthly_text_mb_limit' => $b['monthly_text_mb_limit'] === null ? null : (int) $b['monthly_text_mb_limit'],
        'guideline_version' => (int) $b['guideline_version'],
        'source_count' => (int) $b['source_count'], 'section_count' => (int) $b['section_count'], 'stale_count' => (int) $b['stale_count'],
        'area_count' => (int) $b['area_count'], 'running_build_id' => $b['running_build_id'] === null ? null : (int) $b['running_build_id'],
        'last_built_at' => json_ts($b['last_built_at']), 'archived_at' => json_ts($b['archived_at']),
        'created_at' => json_ts($b['created_at']), 'updated_at' => json_ts($b['updated_at']),
        'url' => '/bases/' . (int) $b['base_id'],
    ];
}

function present_base_member(array $m): array
{
    return [
        'base_member_id' => (int) $m['base_member_id'], 'principal_kind' => $m['principal_kind'],
        'principal_id' => $m['principal_id'] === null ? null : (int) $m['principal_id'], 'principal_ref' => $m['principal_ref'],
        'principal_name' => $m['principal_name'], 'is_guest' => (bool) ($m['is_guest'] ?? false), 'level' => $m['level'],
        'granted_by' => $m['granted_by'] === null ? null : (int) $m['granted_by'], 'granted_by_name' => $m['granted_by_name'] ?? null,
        'created_at' => json_ts($m['created_at']),
    ];
}

function present_template(array $t): array
{
    return [
        'template_key' => $t['template_key'], 'name' => $t['name'], 'description' => $t['description'], 'version' => (int) $t['version'],
        'guidelines' => array_map(static fn (array $g): array => ['kind' => $g['kind'], 'title' => $g['title'] ?? '', 'body' => $g['body']], $t['guidelines']),
        'subject_types' => $t['subject_types'], 'area_rule' => $t['area_rule'], 'is_active' => (bool) $t['is_active'],
    ];
}

/** The loggable state of a base: what base.create / base.update compare — never the description's words, only its length. */
function base_loggable(array $row): array
{
    return [
        'name' => $row['name'], 'key' => $row['key'], 'template' => $row['template'], 'description_chars' => mb_strlen((string) $row['description']),
        'visibility' => $row['visibility'],
    ];
}

/** The notice banners a base screen lands with. */
function base_notices(array $b): array
{
    $n = $b['name'];
    return [
        'created'    => ['success', 'Created ' . $n . '. You own it — add documents to it next.'],
        'saved'      => ['success', 'Saved ' . $n . '.'],
        'settings'   => ['success', 'Saved the settings of ' . $n . '.'],
        'visibility' => ['success', $n . ' is now ' . $b['visibility'] . '.'],
        'shared'     => ['success', 'Shared ' . $n . '.'],
        'changed'    => ['success', 'Changed the level.'],
        'removed'    => ['success', 'Removed from ' . $n . '.'],
        'archived'   => ['warning', $n . ' is archived. It stays readable; it takes no new source or build.'],
        'restored'   => ['success', $n . ' is back.'],
        'forgetting' => ['warning', $n . ' is being deleted: the engine forgets its sections first, then it disappears.'],
    ];
}
