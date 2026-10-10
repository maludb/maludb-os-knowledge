<?php
declare(strict_types=1);

/**
 * The admin area — what a screen shows and what JSON answers (slice 9; whitelists, never a raw row; never a secret, an address or a body).
 */

/** The OS's page on the kernel's host (the launcher's with app. → os.); null when the launcher is not an address. */
function os_page_url(string $path): ?string
{
    $u = parse_url((string) env('OS_LAUNCHER_URL', ''));
    if (empty($u['host'])) {
        return null;
    }
    return ($u['scheme'] ?? 'https') . '://' . preg_replace('/^app\./', 'os.', (string) $u['host']) . (isset($u['port']) ? ':' . $u['port'] : '') . '/' . ltrim($path, '/');
}

/** Usage against the limit: ≥ 100 % danger, ≥ the warning percent warning, else success. */
function usage_chip(int $pct, int $warnAt): array
{
    return [$pct . ' %', $pct >= 100 ? 'danger' : ($pct >= $warnAt ? 'warning' : 'success')];
}

/** The banners the admin pages land with. */
function admin_notices(): array
{
    return [
        'purged'  => ['success', 'Purged.'],
        'model'   => ['success', 'Saved the model.'],
        'agents'  => ['success', 'Saved who the expert and the Archivist are.'],
        'revoked' => ['warning', 'Revoked — the key answers 401 from now on.'],
    ];
}

/** The admin index for JSON. */
function present_admin_index(array $pages): array
{
    return array_map(static fn (array $p): array => ['id' => $p['id'], 'title' => $p['title'], 'url' => $p['url'], 'allowed' => $p['allowed'], 'state' => $p['state']], $pages);
}

function present_usage(array $u): array
{
    return [
        'month' => $u['month'],
        'bases' => array_map(static fn (array $r): array => ['base_id' => $r['base_id'], 'name' => $r['name'], 'archived' => $r['archived'], 'limit_mb' => $r['limit_mb'], 'kinds' => $r['kinds'],
            'calls' => $r['calls'], 'input_tokens' => $r['input_tokens'], 'output_tokens' => $r['output_tokens'], 'chars' => $r['chars'], 'limit_chars' => $r['limit_chars'], 'pct' => $r['pct']], $u['rows']),
        'totals' => $u['totals'],
    ];
}

/** A base of the admin's list for JSON. */
function present_admin_base(array $b): array
{
    return ['base_id' => (int) $b['base_id'], 'name' => $b['name'], 'owner_name' => $b['owner_name'], 'visibility' => $b['visibility'], 'status' => $b['status'], 'source_count' => (int) $b['source_count'],
            'section_count' => (int) $b['section_count'], 'questions_30d' => (int) $b['questions_30d'], 'last_built_at' => json_ts($b['last_built_at']), 'archived' => $b['archived_at'] !== null, 'url' => '/bases/' . (int) $b['base_id']];
}

function present_agent_member(array $a): array
{
    return ['member_id' => (int) $a['member_id'], 'name' => $a['display_name'], 'roles' => $a['roles'], 'status' => $a['status'], 'last_active' => json_ts($a['last_active']), 'dispatches' => $a['dispatches']];
}

/** The home's admin region for JSON: counts and the few newest — no error text, no body. */
function present_admin_home(array $a): array
{
    return [
        'usage' => $a['usage'] === null ? null : present_usage(['month' => $a['usage']['month'], 'rows' => $a['usage']['rows'], 'totals' => $a['usage']['totals']]),
        'failed_builds' => array_map(static fn (array $b): array => ['build_id' => (int) $b['build_id'], 'base_id' => (int) $b['base_id'], 'base' => $b['base_name'], 'error' => $b['error'], 'finished_at' => json_ts($b['finished_at'])], $a['failed_builds']),
        'dispatches' => ['pending' => $a['dispatches']['pending'], 'failed' => $a['dispatches']['failed'],
                         'latest_failed' => array_map(static fn (array $d): array => ['dispatch_id' => (int) $d['dispatch_id'], 'agent' => $d['agent_name'], 'kind' => $d['kind'], 'status' => $d['status'], 'base_id' => $d['base_id'] === null ? null : (int) $d['base_id']], $a['dispatches']['latest_failed'])],
        'open_proposals' => ['count' => $a['open_proposals']['count'],
                             'latest' => array_map(static fn (array $p): array => ['proposal_id' => (int) $p['proposal_id'], 'base_id' => (int) $p['base_id'], 'base' => $p['base_name'], 'kind' => $p['kind'], 'title' => $p['title']], $a['open_proposals']['latest'])],
    ];
}
