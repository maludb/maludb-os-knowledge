<?php
declare(strict_types=1);

/** Connections — what the screen shows (slice 7). */

const SHARE_OUTCOMES = ['ok' => 'success', 'not_shared' => 'secondary', 'consumer_unknown' => 'warning', 'invalid' => 'dark', 'engine_unavailable' => 'danger', 'engine_timeout' => 'danger'];

function share_outcome_chip(string $outcome): array
{
    return [str_replace('_', ' ', $outcome), SHARE_OUTCOMES[$outcome] ?? 'secondary'];
}

/** The OS's applications page: the launcher's host with `app.` → `os.` (the kernel names the OS face os.<domain>); null when the launcher URL is not set. */
function os_applications_url(): ?string
{
    $u = parse_url((string) env('OS_LAUNCHER_URL', ''));
    if (empty($u['host'])) {
        return null;
    }
    $host = preg_replace('/^app\./', 'os.', (string) $u['host']);
    return ($u['scheme'] ?? 'https') . '://' . $host . (isset($u['port']) ? ':' . $u['port'] : '') . '/applications';
}

function present_share_read(array $r): array
{
    return ['activity_id' => $r['activity_id'], 'at' => json_ts($r['occurred_at']), 'application' => $r['application'], 'tool' => $r['tool'], 'base_id' => $r['base_id'], 'base' => $r['base_name'],
            'outcome' => $r['outcome'], 'ms' => $r['ms']];
}
