<?php
declare(strict_types=1);

/** Focus areas — the reads of slice 3 (mcp_focus_areas: everyone who reads the base; the unassigned and empty lists: the curators'). */

/** The base's areas; $f: status (active | proposed | archived; default active and proposed). Each with seed terms and example questions as lists. */
function find_areas(PDO $pdo, int $baseId, array $f = []): array
{
    $status = in_array($f['status'] ?? '', ['active', 'proposed', 'archived'], true) ? [$f['status']] : (!empty($f['archived']) ? ['active', 'proposed', 'archived'] : ['active', 'proposed']);
    $st = $pdo->prepare('SELECT * FROM mcp_focus_areas WHERE base_id = :b AND status = ANY (:s::text[]) ORDER BY status = \'proposed\' DESC, position, lower(name), focus_area_id');
    $st->execute(['b' => $baseId, 's' => pg_array_literal($status)]);
    return array_map('area_decode', $st->fetchAll());
}

function find_area(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_focus_areas WHERE focus_area_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : area_decode($r);
}

function area_decode(array $a): array
{
    $a['seed_terms_list'] = pg_text_array((string) $a['seed_terms']);
    $a['example_questions_list'] = pg_text_array((string) $a['example_questions']);
    return $a;
}

/** The live sources in an area. */
function area_sources(PDO $pdo, int $areaId): array
{
    $st = $pdo->prepare("SELECT s.*, sfa.created_at AS assigned_at FROM mcp_source_focus_areas sfa JOIN mcp_sources s ON s.source_id = sfa.source_id
                          WHERE sfa.focus_area_id = :a AND s.status <> 'forgotten' ORDER BY lower(s.title), s.source_id");
    $st->execute(['a' => $areaId]);
    return $st->fetchAll();
}

/** Sections of the area's sources built before the source joined the area — rebuild to tag them with its project. */
function area_sections_built_before(PDO $pdo, int $areaId): int
{
    return (int) one_value($pdo, "SELECT count(*) FROM mcp_source_focus_areas sfa JOIN mcp_sections x ON x.source_id = sfa.source_id
                                   WHERE sfa.focus_area_id = :a AND x.status <> 'forgotten' AND x.extracted_at IS NOT NULL AND x.extracted_at < sfa.created_at", ['a' => $areaId]);
}

function unassigned_sources(PDO $pdo, int $baseId): array
{
    $st = $pdo->prepare('SELECT * FROM kn_unassigned_sources(:b)');
    $st->execute(['b' => $baseId]);
    return $st->fetchAll();
}

function empty_areas(PDO $pdo, int $baseId): array
{
    $st = $pdo->prepare('SELECT * FROM kn_empty_areas(:b)');
    $st->execute(['b' => $baseId]);
    return $st->fetchAll();
}

function area_timeline(PDO $pdo, int $areaId, int $limit = 30): array
{
    require_once APP_ROOT . '/app/features/activity/queries.php';
    $st = $pdo->prepare("SELECT * FROM mcp_activity_log WHERE entity_type = 'focus_area' AND entity_id = :a AND action <> 'screen.view' ORDER BY occurred_at DESC, activity_id DESC LIMIT " . max(1, min($limit, 200)));
    $st->execute(['a' => $areaId]);
    return $st->fetchAll();
}

/** The base's active areas, for a source's assignment form. */
function active_areas(PDO $pdo, int $baseId): array
{
    return find_areas($pdo, $baseId, ['status' => 'active']);
}

/** One page of the base's live sources not in an area, for the record picker (source `source`, param `area`). */
function search_area_candidates(PDO $pdo, int $areaId, string $q, int $page, int $pageSize = 25): array
{
    $area = find_area($pdo, $areaId);
    if ($area === null) {
        return ['rows' => [], 'total' => 0, 'page' => 1, 'page_size' => $pageSize, 'pages' => 1];
    }
    $where = "s.base_id = :b AND s.status <> 'forgotten' AND NOT EXISTS (SELECT 1 FROM mcp_source_focus_areas sfa WHERE sfa.source_id = s.source_id AND sfa.focus_area_id = :a)";
    $args = ['b' => (int) $area['base_id'], 'a' => $areaId];
    if ($q !== '') {
        $where .= ' AND s.title ILIKE :q';
        $args['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    }
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_sources s WHERE $where", $args);
    $pages = max(1, (int) ceil($total / $pageSize));
    $page = max(1, min($page, $pages));
    $st = $pdo->prepare("SELECT s.source_id, s.title, s.kind, s.sections_count FROM mcp_sources s WHERE $where ORDER BY lower(s.title), s.source_id LIMIT $pageSize OFFSET " . (($page - 1) * $pageSize));
    $st->execute($args);
    $rows = array_map(static fn (array $r): array => ['id' => (int) $r['source_id'], 'label' => $r['title'], 'detail' => (int) $r['sections_count'] . ' sections', 'badge' => $r['kind'], 'color' => 'secondary'], $st->fetchAll());
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'page_size' => $pageSize, 'pages' => $pages];
}
