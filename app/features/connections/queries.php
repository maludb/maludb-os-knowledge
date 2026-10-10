<?php
declare(strict_types=1);

/**
 * Connections — the reads of /admin/connections (slice 7): what this application declares as a provider (maludb-os.json shares[]) and as a consumer
 * (reads[]), which applications are named on which base (mcp_base_members), and the last share calls (mcp_activity_log, action share.read). Read-only:
 * approving a connection between applications is a super-admin's decision in the OS.
 */

/** maludb-os.json, decoded (the installer's manifest is the source of what is declared). */
function kn_manifest(): array
{
    static $m = null;
    return $m ??= (json_decode((string) @file_get_contents(APP_ROOT . '/maludb-os.json'), true) ?: []);
}

/** The shares this application declares: [{tool, description, document, timeout_seconds}]. */
function declared_shares(): array
{
    $out = [];
    foreach (kn_manifest()['shares'] ?? [] as $s) {
        $doc = preg_match('/\((os\.[a-z-]+\/\d+)\)\s*$/', (string) ($s['description'] ?? ''), $m) ? $m[1] : null;
        $out[] = ['tool' => (string) $s['tool'], 'description' => preg_replace('/\s*\(os\.[a-z-]+\/\d+\)\s*$/', '', (string) ($s['description'] ?? '')), 'document' => $doc,
                  'timeout_seconds' => isset($s['timeout_seconds']) ? (int) $s['timeout_seconds'] : null];
    }
    return $out;
}

/** The reads this application declares as a consumer: [{app, tool, why}]. */
function declared_reads(): array
{
    return array_map(static fn (array $r): array => ['app' => (string) $r['app'], 'tool' => (string) $r['tool'], 'why' => (string) ($r['why'] ?? '')], kn_manifest()['reads'] ?? []);
}

/** The applications named as principals on bases: [{application, bases: [{base_id, name, level, since, archived}]}] — mcp_base_members, so the bases the caller curates (the admin: all). */
function application_principals(PDO $pdo): array
{
    $rows = $pdo->query("SELECT m.principal_ref AS application, m.base_id, b.name, m.level, m.created_at AS since, b.archived_at IS NOT NULL AS archived
                           FROM mcp_base_members m JOIN mcp_bases b ON b.base_id = m.base_id
                          WHERE m.principal_kind = 'application' ORDER BY m.principal_ref, lower(b.name), m.base_id")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[$r['application']]['application'] = $r['application'];
        $out[$r['application']]['bases'][] = ['base_id' => (int) $r['base_id'], 'name' => $r['name'], 'level' => $r['level'], 'since' => $r['since'], 'archived' => filter_var($r['archived'], FILTER_VALIDATE_BOOLEAN)];
    }
    return array_values($out);
}

/** The last share calls (mcp_activity_log, action share.read), newest first: [{activity_id, occurred_at, base_id, base_name, application, tool, outcome, ms}]. */
function share_reads(PDO $pdo, int $limit = 50): array
{
    $st = $pdo->prepare("SELECT a.activity_id, a.occurred_at, a.base_id, b.name AS base_name, a.after FROM mcp_activity_log a LEFT JOIN mcp_bases b ON b.base_id = a.base_id
                          WHERE a.action = 'share.read' ORDER BY a.activity_id DESC LIMIT " . max(1, min($limit, 500)));
    $st->execute();
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $a = json_decode((string) $r['after'], true) ?: [];
        $out[] = ['activity_id' => (int) $r['activity_id'], 'occurred_at' => $r['occurred_at'], 'base_id' => $r['base_id'] === null ? null : (int) $r['base_id'], 'base_name' => $r['base_name'],
                  'application' => $a['application'] ?? null, 'tool' => (string) ($a['tool'] ?? ''), 'outcome' => (string) ($a['outcome'] ?? ''), 'ms' => isset($a['ms']) ? (int) $a['ms'] : null];
    }
    return $out;
}

/** The feeds that use a declared read (mcp_feeds: provider + tool): count and the latest status, per "app/tool". */
function read_feed_states(PDO $pdo): array
{
    try {
        $rows = $pdo->query("SELECT provider, tool, count(*) AS feeds, (array_agg(last_status ORDER BY last_sync_at DESC NULLS LAST))[1] AS last_status, max(last_sync_at) AS last_sync_at
                               FROM mcp_feeds GROUP BY provider, tool")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[$r['provider'] . '/' . $r['tool']] = ['feeds' => (int) $r['feeds'], 'last_status' => $r['last_status'], 'last_sync_at' => $r['last_sync_at']];
    }
    return $out;
}
