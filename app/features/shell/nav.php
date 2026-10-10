<?php
declare(strict_types=1);

/**
 * The shell's menu: ONE table — the sidebar and the phone's tab bar read it, so a menu item and the right that opens its
 * screen can never disagree (design §9). An item shows only when the person holds its right (several joined by "|": any one).
 * Home · Ask · Bases · Pinned · Admin (admins only); "Me" holds the person's own pages. The bases' own screens (Sources,
 * Areas, Graph, Guidelines, Builds, Questions, Keys, Share, Settings) are Phase 3's and hang under a base, not here.
 *   [id, url, icon, label, right]
 */
function nav_groups(): array
{
    return [
        'Knowledge' => [
            ['home',   '/',        'feather-home',      'Home',   'bases.read'],
            ['ask',    '/ask',     'feather-message-square', 'Ask', 'bases.read'],
            ['bases',  '/bases',   'feather-book-open', 'Bases',  'bases.read'],
            ['pinned', '/pinned',  'feather-bookmark',  'Pinned', 'bases.read'],
        ],
        'Me' => [
            ['my-settings',   '/settings/',        'feather-settings', 'My settings',   'bases.read'],
            ['notifications', '/notifications',    'feather-bell',     'Notifications', 'bases.read'],
            ['tokens',        '/settings/tokens/', 'feather-key',      'Tokens',        'bases.read'],
            ['activity',      '/activity',         'feather-list',     'My activity',   'bases.read'],
        ],
        'Admin' => [
            ['admin', '/admin/', 'feather-sliders', 'Admin', 'settings.manage'],
        ],
    ];
}

/** The phone's tabs (design §9): Home · Ask · Bases · Me. The sidebar opens from the header's menu button. */
function nav_tabs(): array
{
    return [
        ['home',        '/',          'feather-home',           'Home',  'bases.read'],
        ['ask',         '/ask',       'feather-message-square', 'Ask',   'bases.read'],
        ['bases',       '/bases',     'feather-book-open',      'Bases', 'bases.read'],
        ['my-settings', '/settings/', 'feather-user',           'Me',    'bases.read'],
    ];
}

function nav_item(string $id): ?array
{
    foreach (nav_groups() as $items) {
        foreach ($items as $i) {
            if ($i[0] === $id) {
                return $i;
            }
        }
    }
    return null;
}

/** A menu right may name several joined by "|": any one of them opens the item. */
function nav_has_right(string $spec): bool
{
    foreach (explode('|', $spec) as $right) {
        if (has_right($right)) {
            return true;
        }
    }
    return false;
}

/** The highest Knowledge role the member holds, in words — the header's badge (db/004). */
function role_badge(): string
{
    $m = current_member();
    if ($m === null) {
        return '';
    }
    if (($m['business_role'] ?? '') === 'super_admin') {
        return 'Super-admin';
    }
    $st = db()->prepare('SELECT roles FROM members WHERE id = :id');
    $st->execute(['id' => (int) $m['id']]);
    $held = pg_text_array((string) ($st->fetchColumn() ?: '{}'));
    if ($held === []) {
        return match ($m['capability'] ?? '') { 'admin' => 'Knowledge admin', 'read' => 'Reader', default => 'Member' };
    }
    foreach (['admin' => 'Knowledge admin', 'curator' => 'Curator', 'user' => 'Member', 'reader' => 'Reader'] as $key => $label) {
        if (in_array($key, $held, true)) {
            return $label;
        }
    }
    return 'Member';
}

/** A PostgreSQL text[] literal ({a,b}) as a PHP list; quoted elements are unquoted. */
function pg_text_array(string $literal): array
{
    $literal = trim($literal);
    if ($literal === '' || $literal === '{}') {
        return [];
    }
    $out = [];
    foreach (str_getcsv(substr($literal, 1, -1), ',', '"', '\\') as $v) {
        if ($v !== null && $v !== '') {
            $out[] = $v;
        }
    }
    return $out;
}

/** A link that navigates by HTMX into #page-content and still works as a plain link (progressive enhancement). $html is already escaped. */
function hx_link(string $url, string $html, string $class = '', string $extra = ''): string
{
    return '<a href="' . e($url) . '"' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ($extra !== '' ? ' ' . $extra : '')
        . ' hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="' . e($url) . '">' . $html . '</a>';
}

/** A path a page may send a person back to: local, no scheme, no protocol-relative — else null. */
function safe_local_path(?string $path): ?string
{
    if ($path === null || $path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x1f]/', $path)) {
        return null;
    }
    return $path;
}

/** "Back to …" from the ?back= a link carried (click-around rule): [url, label] or null. Later slices add their screens here. */
function back_link(): ?array
{
    $back = safe_local_path($_GET['back'] ?? null);
    if ($back === null) {
        return null;
    }
    $path = parse_url($back, PHP_URL_PATH) ?: '/';
    $labels = ['/' => 'Home', '/ask' => 'Ask', '/bases' => 'Bases', '/pinned' => 'Pinned', '/notifications' => 'Notifications', '/activity' => 'My activity',
               '/settings/' => 'My settings', '/settings/tokens/' => 'Tokens', '/admin/' => 'Admin', '/admin/settings' => 'Settings', '/admin/templates' => 'Templates',
               '/bases/new' => 'New base'];
    foreach ($labels as $p => $label) {
        if ($path === $p) {
            return [$back, $label];
        }
    }
    foreach (nav_groups() as $items) {
        foreach ($items as $i) {
            if ($i[1] === $path) {
                return [$back, $i[3]];
            }
        }
    }
    if (preg_match('#^/bases/\d+$#', $path)) {
        return [$back, 'the knowledge base'];
    }
    if (preg_match('#^/bases/\d+/(share|settings|edit|sources|guidelines|vocabulary|areas|compiled)$#', $path, $m)) {
        return [$back, ['share' => 'sharing', 'settings' => 'the base\'s settings', 'edit' => 'the edit', 'sources' => 'the sources', 'guidelines' => 'the guidelines',
                        'vocabulary' => 'the vocabulary', 'areas' => 'the areas', 'compiled' => 'what the engine is told'][$m[1]]];
    }
    if (preg_match('#^/(sources|sections|guidelines|areas)/\d+$#', $path, $m)) {
        return [$back, ['sources' => 'the source', 'sections' => 'the section', 'guidelines' => 'the guideline', 'areas' => 'the area'][$m[1]]];
    }
    return null;
}

/** The URL of this same page, for a ?back= (path and query as requested). */
function here_url(): string
{
    return (string) ($_SERVER['REQUEST_URI'] ?? '/');
}

function with_back(string $url, string $here): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . 'back=' . rawurlencode($here);
}

/** The slice that builds each not-yet-built menu item, in words — the placeholders and the registry read it. */
const NAV_SLICES = ['admin' => 'slice 9'];

/**
 * A screen of the manifest that its slice has not built yet (Phase 2): the shell, the page header, one card saying which
 * slice builds it — 200 after the right has been checked. bin/build_action_registry.php reads a controller that calls this
 * as unbuilt, so the voice surface never offers the screen. Removing the call is the slice's first step.
 */
function render_nav_stub(string $navId, string $slice, string $right): void
{
    $item = nav_item($navId);
    $title = $item[3] ?? ucfirst(str_replace('-', ' ', $navId));
    require_login();
    if (!nav_has_right($right)) {
        require_right(explode('|', $right)[0]);        // refuses in that right's sentence
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        emit_action_status(false, ['error' => 'This is built by ' . $slice . '.']);
        if (wants_json()) {
            json_error('not_built', 'This is built by ' . $slice . '.', 501);
        }
        http_response_code(501);
        echo view('shared/message.php', ['title' => 'Not built yet', 'message' => 'This is built by ' . $slice . '.']);
        exit;
    }
    log_screen_view(db(), $navId);
    if (wants_json()) {
        json_error('not_built', 'This screen is built by ' . $slice . '.', 501);
    }
    $html = view('shared/header.php', ['id' => $navId, 'title' => $title, 'crumbs' => [['Home', '/'], [$title, null]]])
        . '<div class="main-content" id="' . e($navId) . '-content"><div class="card coming-card" id="' . e($navId) . '-coming"><div class="card-body">'
        . '<div class="empty-state"><span class="avatar-text avatar-lg rounded"><i class="' . e($item[2] ?? 'feather-box') . '"></i></span>'
        . '<div><div class="fw-semibold">' . e($title) . ' is not built yet</div><div class="fs-12 text-muted">' . e($slice) . ' builds this screen. Nothing is lost: what belongs here will appear when it ships.</div></div></div>'
        . '</div></div></div>';
    render_screen($title, $html, ['activeNav' => $navId, 'screen' => $navId]);
}
