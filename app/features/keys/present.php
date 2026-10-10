<?php
declare(strict_types=1);

/** Keys — what a screen shows and what the token API sends (slice 7). Nothing here ever holds a key's raw value or its hash. */

const KEY_STATUSES = ['live' => ['live', 'success'], 'expiring' => ['expiring', 'warning'], 'revoked' => ['revoked', 'dark'], 'expired' => ['expired', 'secondary']];

/** A key's state: live | expiring (rotated, inside the overlap: it has an end time still ahead) | revoked | expired. */
function key_status(array $k): string
{
    if ($k['revoked_at'] !== null) {
        return 'revoked';
    }
    if ($k['expires_at'] !== null) {
        return strtotime((string) $k['expires_at']) > time() ? 'expiring' : 'expired';
    }
    return 'live';
}

function key_status_chip(array $k): array
{
    return KEY_STATUSES[key_status($k)];
}

/** Today's count is shown in danger at 90 % of the day's limit. */
function key_count_near_limit(array $k): bool
{
    return (int) $k['rate_per_day'] > 0 && (int) $k['calls_today'] >= 0.9 * (int) $k['rate_per_day'];
}

/** A key for JSON: its facts, never its value or hash. */
function present_key(array $k): array
{
    return [
        'key_id' => (int) $k['key_id'], 'base_id' => (int) $k['base_id'], 'label' => $k['label'], 'focus_area_id' => $k['focus_area_id'] === null ? null : (int) $k['focus_area_id'],
        'area' => $k['area_name'] ?? null, 'rate_per_minute' => (int) $k['rate_per_minute'], 'rate_per_day' => (int) $k['rate_per_day'], 'calls_today' => (int) $k['calls_today'],
        'status' => key_status($k), 'is_live' => (bool) $k['is_live'], 'last_used_at' => json_ts($k['last_used_at']), 'expires_at' => json_ts($k['expires_at']), 'revoked_at' => json_ts($k['revoked_at']),
        'minted_by' => $k['minted_by_name'] ?? null, 'minted_at' => json_ts($k['created_at']), 'rotated_from' => $k['rotated_from'] === null ? null : (int) $k['rotated_from'],
    ];
}

/** The banners the key screens land with. */
function key_notices(): array
{
    return [
        'saved'   => ['success', 'Saved the key.'],
        'revoked' => ['warning', 'Revoked — the key answers 401 from now on.'],
    ];
}

/** The base's API URL, for the curl example on the keys screen. */
function key_api_url(string $path = '/api/v1/ask'): string
{
    return rtrim((string) env('APP_URL', ''), '/') . $path;
}

/**
 * The os.knowledge-bases/1 document from rows shaped as kn_application_bases() returns them (key, name, description, template, language, status,
 * last_built_at, source_count, section_count, areas[]). People-free: no owner, curator, member or key.
 */
function present_bases_document(array $rows): array
{
    $bases = [];
    foreach ($rows as $r) {
        $areas = $r['areas'];
        $areas = is_string($areas) ? (json_decode($areas, true) ?: []) : (array) $areas;
        $bases[] = ['key' => (string) $r['key'], 'name' => (string) $r['name'], 'description' => mb_substr((string) ($r['description'] ?? ''), 0, 500), 'template' => $r['template'] ?? null,
            'language' => $r['language'] ?? null, 'status' => (string) $r['status'], 'last_built_at' => isset($r['last_built_at']) ? json_ts((string) $r['last_built_at']) : null,
            'source_count' => (int) $r['source_count'], 'section_count' => (int) $r['section_count'],
            'areas' => array_map(static fn (array $a): array => ['slug' => (string) $a['slug'], 'name' => (string) $a['name'], 'description' => mb_substr((string) ($a['description'] ?? ''), 0, 300),
                'example_questions' => array_slice(array_values((array) ($a['example_questions'] ?? [])), 0, 5)], $areas)];
    }
    return ['schema' => 'os.knowledge-bases/1', 'generated_at' => gmdate('c'), 'application' => 'knowledge', 'bases' => $bases];
}
