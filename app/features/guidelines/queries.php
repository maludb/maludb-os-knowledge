<?php
declare(strict_types=1);

/** Guidelines — the reads of slice 3, through the mcp_* views (a curator's: mcp_guidelines answers only on a curated base). */

/** A base's guidelines grouped by kind (kn_guideline_kinds() order), each kind's rows by position, id: [kind => rows]. */
function find_guidelines(PDO $pdo, int $baseId): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_guidelines WHERE base_id = :b ORDER BY position, guideline_id');
    $st->execute(['b' => $baseId]);
    $out = array_fill_keys(array_keys(GUIDELINE_KINDS), []);
    foreach ($st->fetchAll() as $g) {
        $out[$g['kind']][] = $g;
    }
    return $out;
}

function find_guideline(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_guidelines WHERE guideline_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** A guideline's versions, newest first. */
function guideline_versions(PDO $pdo, int $guidelineId): array
{
    $st = $pdo->prepare('SELECT v.*, m.display_name AS saved_by_name FROM mcp_guideline_versions v LEFT JOIN mcp_members m ON m.member_id = v.saved_by
                          WHERE v.guideline_id = :g ORDER BY v.version_no DESC');
    $st->execute(['g' => $guidelineId]);
    return $st->fetchAll();
}

/** What the graph is built under: the base's version, the live sections by extracted_version and status. */
function built_under(PDO $pdo, int $baseId): array
{
    $st = $pdo->prepare("SELECT x.extracted_version, x.status, count(*) AS n FROM mcp_sections x WHERE x.base_id = :b AND x.status <> 'forgotten'
                          GROUP BY x.extracted_version, x.status ORDER BY x.extracted_version NULLS FIRST, x.status");
    $st->execute(['b' => $baseId]);
    $rows = $st->fetchAll();
    $version = (int) one_value($pdo, 'SELECT guideline_version FROM mcp_bases WHERE base_id = :b', ['b' => $baseId]);
    $by = [];
    $stale = 0;
    $total = 0;
    foreach ($rows as $r) {
        $total += (int) $r['n'];
        if ($r['status'] === 'stale') {
            $stale += (int) $r['n'];
        }
        if ($r['extracted_version'] !== null) {
            $by[(int) $r['extracted_version']] = ($by[(int) $r['extracted_version']] ?? 0) + (int) $r['n'];
        }
    }
    krsort($by);
    $highest = $by === [] ? null : array_key_first($by);
    return ['version' => $version, 'by_version' => $by, 'stale' => $stale, 'total' => $total, 'built_version' => $highest,
            'queued' => array_sum(array_map(static fn ($r) => $r['status'] === 'queued' ? (int) $r['n'] : 0, $rows))];
}

/** The base's live sections for Preview (the first $limit by source and position): [section_id, heading_path, source_title]. */
function preview_sections(PDO $pdo, int $baseId, int $limit = 200): array
{
    $st = $pdo->prepare("SELECT x.section_id, x.heading_path, x.source_title FROM mcp_sections x JOIN mcp_sources s ON s.source_id = x.source_id
                          WHERE x.base_id = :b AND x.status <> 'forgotten' ORDER BY s.added_at, x.source_id, x.position LIMIT " . max(1, min($limit, 500)));
    $st->execute(['b' => $baseId]);
    return $st->fetchAll();
}

/** A guideline's history from the log, newest first. */
function guideline_timeline(PDO $pdo, int $id, int $limit = 30): array
{
    require_once APP_ROOT . '/app/features/activity/queries.php';
    $st = $pdo->prepare("SELECT * FROM mcp_activity_log WHERE entity_type = 'guideline' AND entity_id = :g ORDER BY occurred_at DESC, activity_id DESC LIMIT " . max(1, min($limit, 200)));
    $st->execute(['g' => $id]);
    return $st->fetchAll();
}
