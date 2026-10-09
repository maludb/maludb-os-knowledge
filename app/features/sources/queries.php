<?php
declare(strict_types=1);

/**
 * Sources and sections — the reads of slice 2 (docs/build-specs/sources-sections.md "Query functions"). Through the mcp_* views and
 * the read functions only (kn_visible_base_ids() once per statement); the two duplicate checks read the base tables because they
 * answer a question about the base's own content, after the gate.
 */

/** A base's sources as cards. $f: kind, status (removed = forgotten, else live only), area (a focus area id), q (title). Newest first. */
function find_sources(PDO $pdo, int $baseId, array $f, int $limit = 200): array
{
    $where = ['s.base_id = :b'];
    $args = ['b' => $baseId];
    if (isset(SOURCE_KINDS[$f['kind'] ?? ''])) {
        $where[] = 's.kind = :kind';
        $args['kind'] = $f['kind'];
    }
    $status = (string) ($f['status'] ?? '');
    if ($status === 'removed' || $status === 'forgotten') {
        $where[] = "s.status = 'forgotten'";
    } else {
        $where[] = "s.status <> 'forgotten'";
        if (isset(SOURCE_STATUSES[$status])) {
            $where[] = 's.status = :status';
            $args['status'] = $status;
        }
    }
    if (!empty($f['area'])) {
        $where[] = 'EXISTS (SELECT 1 FROM mcp_source_focus_areas sfa WHERE sfa.source_id = s.source_id AND sfa.focus_area_id = :area)';
        $args['area'] = (int) $f['area'];
    }
    if (($f['q'] ?? '') !== '') {
        $where[] = 's.title ILIKE :q';
        $args['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $f['q']) . '%';
    }
    $st = $pdo->prepare('SELECT s.* FROM mcp_sources s WHERE ' . implode(' AND ', $where) . ' ORDER BY s.added_at DESC, s.source_id DESC LIMIT ' . max(1, min($limit, 1000)));
    $st->execute($args);
    $rows = $st->fetchAll();
    $areas = source_area_names($pdo, $baseId);
    foreach ($rows as &$r) {
        $r['areas'] = array_values(array_filter(array_map(static fn (int $a): ?array => $areas[$a] ?? null, pg_int_array($r['focus_area_ids'] ?? null))));
    }
    return $rows;
}

/** The base's focus areas by id: [id => [focus_area_id, name, status]]. */
function source_area_names(PDO $pdo, int $baseId): array
{
    $st = $pdo->prepare("SELECT focus_area_id, name, status FROM mcp_focus_areas WHERE base_id = :b AND status <> 'archived' ORDER BY position, name");
    $st->execute(['b' => $baseId]);
    $out = [];
    foreach ($st->fetchAll() as $a) {
        $out[(int) $a['focus_area_id']] = $a;
    }
    return $out;
}

function find_source(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_sources WHERE source_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $areas = source_area_names($pdo, (int) $r['base_id']);
    $r['areas'] = array_values(array_filter(array_map(static fn (int $a): ?array => $areas[$a] ?? null, pg_int_array($r['focus_area_ids'] ?? null))));
    return $r;
}

/** The sources still being read (uploaded, extracting_text) — the polling partial's question. */
function pending_sources(PDO $pdo, int $baseId): array
{
    $st = $pdo->prepare("SELECT source_id, title, status FROM mcp_sources WHERE base_id = :b AND status IN ('uploaded', 'extracting_text', 'sectioning') ORDER BY added_at, source_id");
    $st->execute(['b' => $baseId]);
    return $st->fetchAll();
}

/** kn_source_outline() nested by parent_id: each node carries 'children'. */
function source_outline(PDO $pdo, int $sourceId): array
{
    $st = $pdo->prepare('SELECT * FROM kn_source_outline(:s)');
    $st->execute(['s' => $sourceId]);
    $nodes = [];
    foreach ($st->fetchAll() as $r) {
        $r['children'] = [];
        $nodes[(int) $r['section_id']] = $r;
    }
    $roots = [];
    foreach (array_keys($nodes) as $id) {
        $p = $nodes[$id]['parent_id'] === null ? null : (int) $nodes[$id]['parent_id'];
        if ($p !== null && isset($nodes[$p])) {
            $nodes[$p]['children'][] = &$nodes[$id];
        } else {
            $roots[] = &$nodes[$id];
        }
    }
    return $roots;
}

/** The source's pages, each with the first live section on it (mcp_source_pages, mcp_sections). */
function source_pages(PDO $pdo, int $sourceId): array
{
    $st = $pdo->prepare("SELECT p.page_no, p.char_from, p.char_to,
                                (SELECT x.section_id FROM mcp_sections x WHERE x.source_id = p.source_id AND x.status <> 'forgotten'
                                    AND (x.page_no = p.page_no OR (x.char_from < p.char_to AND x.char_to > p.char_from)) ORDER BY x.position LIMIT 1) AS first_section_id
                           FROM mcp_source_pages p WHERE p.source_id = :s ORDER BY p.page_no");
    $st->execute(['s' => $sourceId]);
    return $st->fetchAll();
}

/** A source's history from the log, newest first (screen views left out). */
function source_timeline(PDO $pdo, int $sourceId, int $limit = 30): array
{
    require_once APP_ROOT . '/app/features/activity/queries.php';
    $st = $pdo->prepare("SELECT * FROM mcp_activity_log WHERE entity_type = 'source' AND entity_id = :s AND action <> 'screen.view'
                          ORDER BY occurred_at DESC, activity_id DESC LIMIT " . max(1, min($limit, 200)));
    $st->execute(['s' => $sourceId]);
    return $st->fetchAll();
}

function find_section(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_sections WHERE section_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The previous and the next live section of the same source, by position: ['prev' => ?id, 'next' => ?id]. */
function section_neighbours(PDO $pdo, int $sectionId): array
{
    $st = $pdo->prepare("SELECT (SELECT p.section_id FROM mcp_sections p WHERE p.source_id = x.source_id AND p.position < x.position AND p.status <> 'forgotten' ORDER BY p.position DESC LIMIT 1) AS prev,
                                (SELECT n.section_id FROM mcp_sections n WHERE n.source_id = x.source_id AND n.position > x.position AND n.status <> 'forgotten' ORDER BY n.position LIMIT 1) AS next
                           FROM mcp_sections x WHERE x.section_id = :id");
    $st->execute(['id' => $sectionId]);
    $r = $st->fetch() ?: ['prev' => null, 'next' => null];
    return ['prev' => $r['prev'] === null ? null : (int) $r['prev'], 'next' => $r['next'] === null ? null : (int) $r['next']];
}

/** The section's ancestors, the outermost first: [[section_id, heading]]. */
function section_ancestors(PDO $pdo, int $sectionId): array
{
    $out = [];
    $seen = [];
    $x = find_section($pdo, $sectionId);
    while ($x !== null && $x['parent_id'] !== null && !isset($seen[(int) $x['parent_id']]) && count($out) < 12) {
        $seen[(int) $x['parent_id']] = true;
        $x = find_section($pdo, (int) $x['parent_id']);
        if ($x !== null) {
            array_unshift($out, ['section_id' => (int) $x['section_id'], 'heading' => $x['heading'] !== '' ? $x['heading'] : $x['heading_path']]);
        }
    }
    return $out;
}

/**
 * The section's text, escaped, with the first occurrence of $quote marked (<mark id="quote">): exactly, else with the whitespace
 * collapsed (any run of spaces and line breaks in either matches any other). ['html', 'found' => exact|collapsed|none|''].
 */
function mark_quote(string $text, string $quote): array
{
    $quote = trim($quote);
    if ($quote === '') {
        return ['html' => e($text), 'found' => ''];
    }
    $at = strpos($text, $quote);
    $len = strlen($quote);
    $found = 'exact';
    if ($at === false) {
        $words = preg_split('/\s+/u', $quote, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $re = '/' . implode('\s+', array_map(static fn (string $w): string => preg_quote($w, '/'), $words)) . '/u';
        if ($words !== [] && preg_match($re, $text, $m, PREG_OFFSET_CAPTURE) === 1) {
            $at = (int) $m[0][1];
            $len = strlen($m[0][0]);
            $found = 'collapsed';
        } else {
            return ['html' => e($text), 'found' => 'none'];
        }
    }
    return ['html' => e(substr($text, 0, $at)) . '<mark id="quote">' . e(substr($text, $at, $len)) . '</mark>' . e(substr($text, $at + $len)), 'found' => $found];
}

/** The live source of the base already holding these bytes (an attachment with the same sha256), or null. */
function base_has_attachment_sha(PDO $pdo, int $baseId, string $sha256): ?array
{
    $st = $pdo->prepare("SELECT s.id AS source_id, s.title FROM attachments a JOIN sources s ON s.id = a.record_id
                          WHERE a.record_type = 'source' AND a.sha256 = :h AND s.base_id = :b AND s.status <> 'forgotten' ORDER BY s.id LIMIT 1");
    $st->execute(['h' => $sha256, 'b' => $baseId]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The live source of the base already reading this address, or null. */
function base_has_url(PDO $pdo, int $baseId, string $url): ?array
{
    $st = $pdo->prepare("SELECT id AS source_id, title FROM sources WHERE base_id = :b AND kind = 'url' AND origin = :u AND status <> 'forgotten' ORDER BY id LIMIT 1");
    $st->execute(['b' => $baseId, 'u' => $url]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}
