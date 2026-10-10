<?php
declare(strict_types=1);

/** Builds — the reads of slice 4 (mcp_builds, mcp_build_items, mcp_ai_usage: the curators'; kn_build_estimate(), kn_build_progress(), kn_usage()). */

function build_estimate(PDO $pdo, int $baseId, string $kind = 'extract'): ?array
{
    $j = one_value($pdo, 'SELECT kn_build_estimate(:b, :k)', ['b' => $baseId, 'k' => $kind === 'rebuild_all' ? 'rebuild_all' : 'extract']);
    return $j === null ? null : json_decode((string) $j, true);
}

/** About how many engine calls: one per 1,800 characters of each section (the route's 2,000-character chunks overlapping by 200). */
function build_call_estimate(PDO $pdo, int $baseId, string $kind = 'extract'): int
{
    $where = $kind === 'rebuild_all' ? "status <> 'forgotten'" : "status IN ('queued', 'stale', 'failed')";
    return (int) one_value($pdo, "SELECT COALESCE(sum(GREATEST(ceil(char_count / 1800.0), 1)), 0) FROM mcp_sections WHERE base_id = :b AND $where", ['b' => $baseId]);
}

function find_builds(PDO $pdo, int $baseId, int $limit = 50): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_builds WHERE base_id = :b ORDER BY requested_at DESC, build_id DESC LIMIT ' . max(1, min($limit, 500)));
    $st->execute(['b' => $baseId]);
    return $st->fetchAll();
}

function find_build(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_builds WHERE build_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

function build_progress(PDO $pdo, int $id): ?array
{
    $j = one_value($pdo, 'SELECT kn_build_progress(:id)', ['id' => $id]);
    return $j === null ? null : json_decode((string) $j, true);
}

/** A page of a build's items: [rows, total, page, pages]. */
function build_items(PDO $pdo, int $buildId, int $page = 1, int $per = 200): array
{
    $total = (int) one_value($pdo, 'SELECT count(*) FROM mcp_build_items WHERE build_id = :b', ['b' => $buildId]);
    $pages = max(1, (int) ceil($total / $per));
    $page = max(1, min($page, $pages));
    $st = $pdo->prepare('SELECT * FROM mcp_build_items WHERE build_id = :b ORDER BY build_item_id LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
    $st->execute(['b' => $buildId]);
    return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/** The build's log lines (builds.log — the first is the prompt's hash line). Read from the base table after the gate: mcp_builds does not carry it. */
function build_log_lines(PDO $pdo, int $id): array
{
    $log = (string) one_value($pdo, 'SELECT log FROM builds WHERE id = :id', ['id' => $id]);
    return $log === '' ? [] : explode("\n", rtrim($log, "\n"));
}

/** This month's engine use by the base: [kind => [calls, chars, input_tokens, output_tokens]]. */
function base_month_usage(PDO $pdo, int $baseId): array
{
    $st = $pdo->prepare('SELECT * FROM kn_usage(:b)');
    $st->execute(['b' => $baseId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['kind']] = ['calls' => (int) $r['calls'], 'chars' => (int) $r['chars'], 'input_tokens' => (int) $r['input_tokens'], 'output_tokens' => (int) $r['output_tokens']];
    }
    return $out;
}
