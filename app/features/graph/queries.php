<?php
declare(strict_types=1);

/**
 * The graph explorer's reads (slice 6, docs/build-specs/graph-explorer.md "Query functions"). Read-only: nothing here writes to the record
 * database or to the engine. THE BASE IS THE WALL — subjects, projects and the graph routes are shared by every base of the memory
 * database (design §0), so every subject, document, path and neighbour this file returns has first been CONFINED to the base:
 *   - a document is the base's only when kn_engine_documents_in($ns, …) says so, and it is shown as its section (mcp_sections by
 *     maludb_document_id; a document with no section — a seed document — is dropped);
 *   - a subject (or a statement) is the base's only when one of its neighbours is such a document;
 *   - nothing is a tenant-wide figure: the size is the base's own document count from the engine and the counts cached on knowledge_bases.
 * Every engine read goes through graph_cache() (60 s; duplicate_subjects 600 s); a failure is never cached and never throws — a result is
 * ['ok' => false, 'code' => <engine code>] and the screen shows kn_engine_error_message() in its own region while the others still render.
 * The caller has passed the base's gate (base_from_request() / the bridge's reader gate) before any of this runs.
 */
require_once APP_ROOT . '/app/knowledge/engine.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/svg.php';

/** Subjects checked per confinement call; the rest are dropped and the region says "and more". */
const GRAPH_CAP = 40;
const GRAPH_ENGINE_DOWN = ['engine_unavailable', 'engine_timeout', 'engine_auth', 'engine_forbidden'];

function graph_ns(PDO $pdo, int $baseId): string
{
    return (string) (one_value($pdo, 'SELECT namespace FROM knowledge_bases WHERE id = :b', ['b' => $baseId]) ?? ('kb:' . $baseId));
}

/** A failed engine result as the explorer's own: ['ok' => false, 'code' => …]. */
function graph_fail(array $r): array
{
    return ['ok' => false, 'code' => (string) ($r['code'] ?? 'engine_error')];
}

/** The base's curators (and its owner / the admin) — what the vocabulary's aliases and the duplicates are shown to. */
function graph_curates(int $baseId): bool
{
    return in_array(base_level($baseId), ['own', 'curate'], true);
}

// ---------------------------------------------------------------------------------------------------------------------
// The engine, once per request
// ---------------------------------------------------------------------------------------------------------------------

/** kn_engine_neighbors() remembered for the request (several regions ask the same node); a failure is not remembered. @return array the module's result */
function graph_neighbors(string $kind, int $id): array
{
    static $memo = [];
    $k = $kind . ':' . $id;
    if (!isset($memo[$k])) {
        $r = kn_engine_neighbors($kind, $id);
        if (!$r['ok']) {
            return $r;
        }
        $memo[$k] = $r;
    }
    return $memo[$k];
}

/** The subject whose canonical name is exactly $name: ['ok' => true, 'subject' => ?['subject_id', 'name', 'type', 'description']]. */
function graph_find_subject(string $name): array
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 300) {
        return ['ok' => true, 'subject' => null];
    }
    $r = kn_engine_find_subject($name);
    if (!$r['ok']) {
        return graph_fail($r);
    }
    $s = $r['data']['subject'] ?? null;
    return ['ok' => true, 'subject' => $s === null ? null : ['subject_id' => (int) $s['id'], 'name' => (string) $s['label'], 'type' => $s['type'] ?? null, 'description' => $s['description'] ?? null]];
}

/** A subject by its engine id (the bridge's tools hand ids around; the engine's list route filters on id). */
function graph_find_subject_by_id(int $id): array
{
    if ($id <= 0) {
        return ['ok' => true, 'subject' => null];
    }
    $r = kn_engine_request('GET', '/v1/subjects', ['id' => 'eq.' . $id, 'limit' => 1]);
    if (!$r['ok']) {
        return graph_fail($r);
    }
    $s = $r['data']['subjects'][0] ?? null;
    return ['ok' => true, 'subject' => $s === null ? null : ['subject_id' => (int) $s['id'], 'name' => (string) $s['label'], 'type' => $s['type'] ?? null, 'description' => $s['description'] ?? null]];
}

// ---------------------------------------------------------------------------------------------------------------------
// Confinement
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Of these documents (engine ids) the sections of THIS base that hold them, by document id: [doc id => {section_id, heading_path, heading,
 * source: {id, title}, page, document_id}], in reading order. A document with no live section here is absent.
 * @param list<int> $docIds
 */
function graph_sections_by_documents(PDO $pdo, int $baseId, array $docIds): array
{
    $docIds = array_values(array_unique(array_filter(array_map('intval', $docIds), static fn (int $i): bool => $i > 0)));
    if ($docIds === []) {
        return [];
    }
    $st = $pdo->prepare("SELECT section_id, maludb_document_id, heading_path, heading, source_id, source_title, page_no FROM mcp_sections
                          WHERE base_id = :b AND status <> 'forgotten' AND maludb_document_id = ANY(:ids::text[]) ORDER BY source_id, position, section_id");
    $st->execute(['b' => $baseId, 'ids' => pg_array_literal(array_map('strval', $docIds))]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int) $r['maludb_document_id']] ??= graph_section_row($r, (int) $r['maludb_document_id']);
    }
    return $out;
}

function graph_section_row(array $r, int $docId): array
{
    return ['section_id' => (int) $r['section_id'], 'heading_path' => (string) $r['heading_path'], 'heading' => (string) $r['heading'],
            'source' => ['id' => (int) $r['source_id'], 'title' => (string) $r['source_title']], 'page' => $r['page_no'] === null ? null : (int) $r['page_no'], 'document_id' => $docId];
}

/** A section as a list node: the last part of its heading path is its name. */
function graph_section_node(array $s): array
{
    $name = $s['heading'] !== '' ? $s['heading'] : (string) (preg_split('/\s›\s/u', (string) $s['heading_path'])[count(preg_split('/\s›\s/u', (string) $s['heading_path'])) - 1] ?? '');
    return ['kind' => 'section', 'id' => (int) $s['section_id'], 'name' => $name !== '' ? $name : (string) $s['source']['title'], 'section' => $s];
}

/**
 * Of these nodes ([id, name, …] of one $kind) the ones that touch the base: each one's neighbours (kn_engine_neighbors), the document ones
 * checked with kn_engine_documents_in($ns, …). At most GRAPH_CAP are checked; ['ok' => true, 'nodes' => the survivors with `documents` (how many
 * of the base's documents touch it), `degree` (all its neighbours), `doc_ids` and `rels` (verb => count), 'more' => bool].
 * @param list<array{id: int, name?: string}> $nodes
 */
function graph_confine_nodes(string $ns, string $kind, array $nodes): array
{
    $seen = [];
    $list = [];
    foreach ($nodes as $n) {
        $id = (int) ($n['id'] ?? 0);
        if ($id > 0 && !isset($seen[$id])) {
            $seen[$id] = true;
            $list[] = $n;
        }
    }
    $more = count($list) > GRAPH_CAP;
    $list = array_slice($list, 0, GRAPH_CAP);
    $rowsBy = [];
    $docIds = [];
    foreach ($list as $n) {
        $r = graph_neighbors($kind, (int) $n['id']);
        if (!$r['ok']) {
            return graph_fail($r);
        }
        $rowsBy[(int) $n['id']] = $r['data']['neighbors'] ?? [];
        foreach ($rowsBy[(int) $n['id']] as $x) {
            if (($x['neighbor_kind'] ?? '') === 'document') {
                $docIds[] = (int) $x['neighbor_id'];
            }
        }
    }
    $in = $docIds === [] ? [] : kn_engine_documents_in($ns, $docIds);
    if ($in === null) {
        return ['ok' => false, 'code' => 'engine_error'];
    }
    $kept = [];
    foreach ($list as $n) {
        $mine = [];
        $rels = [];
        foreach ($rowsBy[(int) $n['id']] as $x) {
            if (($x['neighbor_kind'] ?? '') === 'document' && isset($in[(int) $x['neighbor_id']])) {
                $mine[(int) $x['neighbor_id']] = true;
            }
            if (($x['rel'] ?? null) !== null && $x['rel'] !== '') {
                $rels[(string) $x['rel']] = ($rels[(string) $x['rel']] ?? 0) + 1;
            }
        }
        if ($mine !== []) {
            $kept[] = $n + ['documents' => count($mine), 'degree' => count($rowsBy[(int) $n['id']]), 'doc_ids' => array_keys($mine), 'rels' => $rels];
        }
    }
    return ['ok' => true, 'nodes' => $kept, 'more' => $more];
}

/**
 * The subjects among $subjects ([id, name]) that touch the base, each with its document count — at most 40 are checked per call (the rest are
 * dropped: 'more' says so). ['ok' => true, 'subjects' => …, 'more' => bool] or ['ok' => false, 'code'].
 */
function graph_confine_subjects(string $ns, array $subjects): array
{
    $r = graph_confine_nodes($ns, 'subject', $subjects);
    return $r['ok'] ? ['ok' => true, 'subjects' => $r['nodes'], 'more' => $r['more']] : $r;
}

/** How many of the base's documents touch this subject ([id, name]), or null when none does — then the subject is not the base's. $code: the engine's, on a failure. */
function graph_subject_in_base(string $ns, array $subject, ?string &$code = null): ?int
{
    $r = graph_confine_nodes($ns, 'subject', [['id' => (int) ($subject['id'] ?? $subject['subject_id'] ?? 0), 'name' => (string) ($subject['name'] ?? '')]]);
    if (!$r['ok']) {
        $code = $r['code'];
        return null;
    }
    return $r['nodes'] === [] ? null : (int) $r['nodes'][0]['documents'];
}

// ---------------------------------------------------------------------------------------------------------------------
// Names
// ---------------------------------------------------------------------------------------------------------------------

/**
 * The name a duplicate is judged by: lower case, unaccented, punctuation and the words "the", "a", "an" dropped, whitespace collapsed, a
 * trailing "s" dropped from a word of more than four letters.
 */
function graph_normal_name(string $name): string
{
    static $fold = null;
    $fold ??= ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a', 'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ď' => 'd', 'đ' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e', 'ğ' => 'g', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'į' => 'i', 'ı' => 'i',
        'ł' => 'l', 'ñ' => 'n', 'ń' => 'n', 'ň' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ő' => 'o', 'ō' => 'o', 'ř' => 'r', 'ś' => 's', 'š' => 's', 'ş' => 's',
        'ť' => 't', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
        'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe'];
    $s = strtr(mb_strtolower(trim($name)), $fold);
    $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;
    $words = [];
    foreach (preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
        if (in_array($w, ['the', 'a', 'an'], true)) {
            continue;
        }
        if (mb_strlen($w) > 4 && str_ends_with($w, 's')) {
            $w = mb_substr($w, 0, -1);
        }
        $words[] = $w;
    }
    return implode(' ', $words);
}

/** The aliases a subject's description carries (a seeded term keeps them there: "Also called: a; b"). @return list<string> */
function graph_description_aliases(?string $description): array
{
    if ($description !== null && preg_match('/^\s*Also called:\s*(.+)$/mi', $description, $m)) {
        return array_values(array_filter(array_map('trim', explode(';', $m[1])), static fn (string $a): bool => $a !== ''));
    }
    return [];
}

/** The description without its "Also called" line (the aliases are shown as chips). */
function graph_description_text(?string $description): string
{
    return trim((string) preg_replace('/^\s*Also called:.*$/mi', '', (string) $description));
}

/** The vocabulary's names for a subject (curators only): the aliases of the term it is, or the term and its other aliases when it is an alias. @return list<string> */
function graph_vocabulary_aliases(PDO $pdo, int $baseId, string $name): array
{
    $st = $pdo->prepare('SELECT canonical_name, aliases FROM mcp_vocabulary_terms WHERE base_id = :b');
    $st->execute(['b' => $baseId]);
    $want = mb_strtolower($name);
    $out = [];
    foreach ($st->fetchAll() as $t) {
        $aliases = pg_text_array((string) $t['aliases']);
        if (mb_strtolower((string) $t['canonical_name']) === $want) {
            $out = array_merge($out, $aliases);
        } elseif (in_array($want, array_map('mb_strtolower', $aliases), true)) {
            $out = array_merge($out, [(string) $t['canonical_name']], array_filter($aliases, static fn (string $a): bool => mb_strtolower($a) !== $want));
        }
    }
    return array_values(array_unique($out));
}

// ---------------------------------------------------------------------------------------------------------------------
// The graph page's regions (each from the cache)
// ---------------------------------------------------------------------------------------------------------------------

/** The base's documents in the graph, from the engine: ['ok' => true, 'documents' => int]. (The engine's own stats are tenant-wide and are never kept.) */
function graph_region_size(PDO $pdo, int $baseId): array
{
    $ns = graph_ns($pdo, $baseId);
    return graph_cache($baseId, 'size', GRAPH_TTL_READ, static function () use ($ns): array {
        $r = kn_engine_stats($ns);
        return $r['ok'] ? ['ok' => true, 'documents' => (int) ($r['data']['documents'] ?? 0)] : graph_fail($r);
    });
}

/** The communities MaluDB holds for the base (none today — M6): ['ok' => true, 'communities' => [{community_id, label, size}]]. Shown as they come. */
function graph_region_communities(PDO $pdo, int $baseId): array
{
    $ns = graph_ns($pdo, $baseId);
    return graph_cache($baseId, 'communities', GRAPH_TTL_READ, static function () use ($ns): array {
        $r = kn_engine_communities($ns);
        if (!$r['ok']) {
            return graph_fail($r);
        }
        $list = array_map(static fn (array $c): array => ['community_id' => (int) $c['community_id'], 'label' => (string) ($c['label'] ?? ''), 'size' => (int) ($c['member_count'] ?? 0)], $r['data']['communities'] ?? []);
        usort($list, static fn (array $a, array $b): int => [$b['size'], $a['community_id']] <=> [$a['size'], $b['community_id']]);
        return ['ok' => true, 'communities' => $list];
    });
}

/**
 * The most connected nodes of the base (the engine confines them: its documents, and subjects one of them touches): a subject as
 * {kind: subject, id, name, degree}, a document as its section {kind: section, id: section id, name, section, degree}; a document with
 * no section is dropped. $subjectsOnly keeps the subjects.
 */
function graph_region_god_nodes(PDO $pdo, int $baseId, int $limit, bool $subjectsOnly = false): array
{
    $ns = graph_ns($pdo, $baseId);
    return graph_cache($baseId, 'god|' . $limit . '|' . ($subjectsOnly ? 's' : 'a'), GRAPH_TTL_READ, static function () use ($pdo, $baseId, $ns, $limit, $subjectsOnly): array {
        $r = kn_engine_god_nodes($ns, $limit);
        if (!$r['ok']) {
            return graph_fail($r);
        }
        $rows = $r['data']['god_nodes'] ?? [];
        $sections = $subjectsOnly ? [] : graph_sections_by_documents($pdo, $baseId, array_map(static fn (array $n): int => (int) $n['object_id'], array_filter($rows, static fn (array $n): bool => ($n['object_kind'] ?? '') === 'document')));
        $nodes = [];
        foreach ($rows as $n) {
            $kind = (string) ($n['object_kind'] ?? '');
            if ($kind === 'subject') {
                $nodes[] = ['kind' => 'subject', 'id' => (int) $n['object_id'], 'name' => (string) $n['label'], 'degree' => (int) ($n['degree_total'] ?? 0)];
            } elseif ($kind === 'document' && isset($sections[(int) $n['object_id']])) {
                $nodes[] = graph_section_node($sections[(int) $n['object_id']]) + ['degree' => (int) ($n['degree_total'] ?? 0)];
            }
        }
        return ['ok' => true, 'nodes' => $nodes];
    });
}

/** A surprise's end as a list node (a subject, or a document as its section), or null when it is not the base's. */
function graph_surprise_end(string $kind, int $id, string $label, array $subjectsIn, array $docsIn, array $sections): ?array
{
    if ($kind === 'subject' && isset($subjectsIn[$id])) {
        return ['kind' => 'subject', 'id' => $id, 'name' => $label];
    }
    if ($kind === 'document' && isset($docsIn[$id]) && isset($sections[$id])) {
        return graph_section_node($sections[$id]);
    }
    return null;
}

/** The surprising links, both ends confined: [{from, to, verb, pair_edges}], with 'more' when subjects beyond the cap were not checked. */
function graph_region_surprises(PDO $pdo, int $baseId): array
{
    $ns = graph_ns($pdo, $baseId);
    return graph_cache($baseId, 'surprises', GRAPH_TTL_READ, static function () use ($pdo, $baseId, $ns): array {
        $r = kn_engine_surprises($ns, 25);
        if (!$r['ok']) {
            return graph_fail($r);
        }
        $rows = $r['data']['surprises'] ?? [];
        $subjects = [];
        $docs = [];
        foreach ($rows as $s) {
            foreach (['source', 'target'] as $end) {
                $k = (string) ($s[$end . '_kind'] ?? '');
                $i = (int) ($s[$end . '_id'] ?? 0);
                if ($k === 'subject') {
                    $subjects[] = ['id' => $i, 'name' => (string) ($s[$end . '_label'] ?? '')];
                } elseif ($k === 'document') {
                    $docs[] = $i;
                }
            }
        }
        $conf = graph_confine_nodes($ns, 'subject', $subjects);
        if (!$conf['ok']) {
            return $conf;
        }
        $subjectsIn = array_column($conf['nodes'], null, 'id');
        $docsIn = $docs === [] ? [] : kn_engine_documents_in($ns, $docs);
        if ($docsIn === null) {
            return ['ok' => false, 'code' => 'engine_error'];
        }
        $sections = graph_sections_by_documents($pdo, $baseId, array_keys($docsIn));
        $out = [];
        foreach ($rows as $s) {
            $from = graph_surprise_end((string) ($s['source_kind'] ?? ''), (int) ($s['source_id'] ?? 0), (string) ($s['source_label'] ?? ''), $subjectsIn, $docsIn, $sections);
            $to = graph_surprise_end((string) ($s['target_kind'] ?? ''), (int) ($s['target_id'] ?? 0), (string) ($s['target_label'] ?? ''), $subjectsIn, $docsIn, $sections);
            if ($from !== null && $to !== null) {
                $out[] = ['from' => $from, 'to' => $to, 'verb' => ($s['rel'] ?? null) === null ? null : (string) $s['rel'], 'pair_edges' => (int) ($s['community_pair_edges'] ?? 0)];
            }
        }
        return ['ok' => true, 'surprises' => $out, 'more' => $conf['more']];
    });
}

/**
 * The graph page: ['size' => {documents (the engine's, for this base), subjects, statements, communities, last_built_at (cached on the base)},
 * 'communities' => […], 'connected' => the connected subjects (only while there are no communities), 'god_nodes', 'surprises', 'surprises_more',
 * 'errors' => [region => code]]. A region that failed is empty and named in `errors`; the others stand. When the engine cannot be reached the first
 * failure is every remaining region's (no waiting out each one).
 */
function graph_overview(PDO $pdo, int $baseId): array
{
    $b = find_base($pdo, $baseId) ?? throw new RuntimeException('graph_overview: no such base');
    $o = ['size' => ['documents' => null, 'subjects' => (int) $b['subjects_count'], 'statements' => (int) $b['statements_count'], 'communities' => (int) $b['communities_count'], 'last_built_at' => $b['last_built_at']],
          'communities' => [], 'connected' => [], 'god_nodes' => [], 'surprises' => [], 'surprises_more' => false, 'errors' => []];
    $down = null;
    $take = static function (string $region, callable $fn) use (&$down, &$o): ?array {
        if ($down !== null) {
            $o['errors'][$region] = $down;
            return null;
        }
        $r = $fn();
        if (!$r['ok']) {
            $o['errors'][$region] = $r['code'];
            $down = in_array($r['code'], GRAPH_ENGINE_DOWN, true) ? $r['code'] : null;
            return null;
        }
        return $r;
    };
    if (($r = $take('size', static fn (): array => graph_region_size($pdo, $baseId))) !== null) {
        $o['size']['documents'] = (int) $r['documents'];
    }
    if (($r = $take('communities', static fn (): array => graph_region_communities($pdo, $baseId))) !== null) {
        $o['communities'] = $r['communities'];
        if ($r['communities'] === [] && ($c = $take('connected', static fn (): array => graph_region_god_nodes($pdo, $baseId, 20, true))) !== null) {
            $o['connected'] = $c['nodes'];
        }
    }
    if (($r = $take('god_nodes', static fn (): array => graph_region_god_nodes($pdo, $baseId, 10))) !== null) {
        $o['god_nodes'] = $r['nodes'];
    }
    if (($r = $take('surprises', static fn (): array => graph_region_surprises($pdo, $baseId))) !== null) {
        $o['surprises'] = $r['surprises'];
        $o['surprises_more'] = (bool) $r['more'];
    }
    return $o;
}

/**
 * The search: the question's seeds and their neighbourhood, already confined to the base by kn_engine_graph_query(). A list of subjects (seeds
 * first) with how many of the base's documents touch each. ['ok' => true, 'items' => [{subject_id, name, seed, documents}], 'more'] or ['ok' => false, 'code'].
 */
function graph_search(PDO $pdo, int $baseId, string $q): array
{
    $q = trim($q);
    $ns = graph_ns($pdo, $baseId);
    return graph_cache($baseId, 'search|' . $q, GRAPH_TTL_READ, static function () use ($ns, $q): array {
        $r = kn_engine_graph_query($q, $ns, 2, 50);
        if (!$r['ok']) {
            return graph_fail($r);
        }
        $seedIds = [];
        foreach ($r['data']['seeds'] ?? [] as $s) {
            $seedIds[(int) $s['subject_id']] = (string) $s['canonical_name'];
        }
        $docsOf = [];
        foreach ($r['data']['edges'] ?? [] as $e) {
            foreach ([['source', 'target'], ['target', 'source']] as [$a, $b]) {
                if (($e[$a . '_kind'] ?? '') === 'subject' && ($e[$b . '_kind'] ?? '') === 'document') {
                    $docsOf[(int) $e[$a . '_id']][(int) $e[$b . '_id']] = true;
                }
            }
        }
        $items = [];
        foreach ($r['data']['nodes'] ?? [] as $n) {
            if (($n['object_kind'] ?? '') === 'subject') {
                $id = (int) $n['object_id'];
                $items[] = ['subject_id' => $id, 'name' => $seedIds[$id] ?? (string) $n['label'], 'seed' => isset($seedIds[$id]), 'documents' => count($docsOf[$id] ?? [])];
            }
        }
        usort($items, static fn (array $a, array $b): int => [(int) !$a['seed'], -$a['documents'], mb_strtolower($a['name'])] <=> [(int) !$b['seed'], -$b['documents'], mb_strtolower($b['name'])]);
        return ['ok' => true, 'items' => $items, 'more' => count($r['data']['nodes'] ?? []) >= 50];
    });
}

// ---------------------------------------------------------------------------------------------------------------------
// A subject
// ---------------------------------------------------------------------------------------------------------------------

/** The drawing's nodes of a subject: its sections first, then its subjects by their degree, then its statements; at most 24. */
function graph_svg_nodes(int $baseId, array $sections, array $neighbours, array $statements): array
{
    $nodes = [];
    foreach ($sections as $s) {
        $nodes[] = ['kind' => 'section', 'name' => graph_section_node($s)['name'], 'title' => $s['heading_path'], 'url' => '/sections/' . (int) $s['section_id'], 'verb' => null];
    }
    usort($neighbours, static fn (array $a, array $b): int => [-($a['degree'] ?? 0), mb_strtolower($a['name'])] <=> [-($b['degree'] ?? 0), mb_strtolower($b['name'])]);
    foreach ($neighbours as $n) {
        $nodes[] = ['kind' => 'subject', 'name' => $n['name'], 'title' => $n['name'], 'url' => graph_subject_url($baseId, $n['name']), 'verb' => $n['verb'] ?? null];
    }
    foreach ($statements as $x) {
        if (isset($x['section'])) {
            $nodes[] = ['kind' => 'statement', 'name' => $x['text'], 'title' => $x['text'], 'url' => '/sections/' . (int) $x['section']['section_id'], 'verb' => $x['verb'] ?? null];
        }
    }
    return array_slice($nodes, 0, GRAPH_SVG_MAX_NODES);
}

/** What the engine says about a subject, confined (cached): ['ok' => true, 'found' => bool, …] — found false when no document of the base touches it. */
function graph_subject_build(PDO $pdo, int $baseId, string $ns, array $subject): array
{
    $sid = (int) $subject['subject_id'];
    $nb = graph_neighbors('subject', $sid);
    if (!$nb['ok']) {
        return graph_fail($nb);
    }
    $docIds = [];
    $subjectRows = [];
    $statementRows = [];
    foreach ($nb['data']['neighbors'] ?? [] as $x) {
        $kind = (string) ($x['neighbor_kind'] ?? '');
        $id = (int) ($x['neighbor_id'] ?? 0);
        if ($kind === 'document') {
            $docIds[] = $id;
        } elseif ($kind === 'subject' && $id !== $sid) {
            $subjectRows[$id] ??= ['id' => $id, 'name' => (string) ($x['label'] ?? ''), 'verb' => ($x['rel'] ?? null) === null ? null : (string) $x['rel']];
        } elseif ($kind === 'statement') {
            $statementRows[$id] ??= ['statement_id' => $id, 'text' => (string) ($x['label'] ?? ''), 'verb' => ($x['rel'] ?? null) === null ? null : (string) $x['rel']];
        }
    }
    $in = $docIds === [] ? [] : kn_engine_documents_in($ns, $docIds);
    if ($in === null) {
        return ['ok' => false, 'code' => 'engine_error'];
    }
    if ($in === []) {
        return ['ok' => true, 'found' => false, 'nocache' => true];
    }
    $docIn = array_keys($in);
    sort($docIn);
    $secMap = graph_sections_by_documents($pdo, $baseId, $docIn);
    $sections = [];
    foreach ($secMap as $s) {
        $sections[(int) $s['section_id']] = $s;
    }
    $sections = array_values($sections);
    $conf = graph_confine_nodes($ns, 'subject', array_values($subjectRows));
    if (!$conf['ok']) {
        return $conf;
    }
    $neighbours = [];
    foreach ($conf['nodes'] as $n) {
        $neighbours[] = ['subject_id' => (int) $n['id'], 'name' => $n['name'], 'verb' => $n['verb'], 'documents' => (int) $n['documents'], 'degree' => (int) $n['degree']];
    }
    usort($neighbours, static fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['subject_id']] <=> [mb_strtolower($b['name']), $b['subject_id']]);
    $statements = [];
    foreach (array_slice($docIn, 0, 20) as $docId) {
        $dn = graph_neighbors('document', $docId);
        if (!$dn['ok']) {
            return graph_fail($dn);
        }
        foreach ($dn['data']['neighbors'] ?? [] as $x) {
            $id = (int) ($x['neighbor_id'] ?? 0);
            if (($x['neighbor_kind'] ?? '') === 'statement' && isset($statementRows[$id]) && !isset($statements[$id])) {
                $statements[$id] = $statementRows[$id] + ['section' => $secMap[$docId] ?? null];
            }
        }
    }
    ksort($statements);
    $statements = array_values($statements);
    return [
        'ok' => true, 'found' => true,
        'subject' => $subject, 'engine_aliases' => graph_description_aliases($subject['description'] ?? null),
        'statements' => $statements, 'neighbours' => $neighbours, 'neighbours_more' => $conf['more'] || count($subjectRows) > GRAPH_CAP,
        'sections' => $sections, 'svg_nodes' => graph_svg_nodes($baseId, $sections, $neighbours, $statements),
    ];
}

/** Statements grouped by their verb, in order of first appearance; those with none under "Statements". @return list<array{verb: ?string, statements: list<array>}> */
function graph_group_statements(array $statements): array
{
    $by = [];
    foreach ($statements as $x) {
        $v = ($x['verb'] ?? null) === null || $x['verb'] === '' ? null : (string) $x['verb'];
        $by[$v ?? "\0"]['verb'] = $v;
        $by[$v ?? "\0"]['statements'][] = $x;
    }
    return array_values($by);
}

/**
 * One subject of the base, by its canonical name: ['subject' => {subject_id, name, type, description}, 'aliases', 'statements' (grouped by verb),
 * 'neighbours', 'neighbours_more', 'sections', 'svg_nodes', 'errors']. null = not in the base (a name the engine does not know and a subject the
 * base's documents never touch alike). On an engine failure the subject is only its name and errors['engine'] says why — the page says so, it is not a 404.
 */
function graph_subject(PDO $pdo, int $baseId, string $name): ?array
{
    $ns = graph_ns($pdo, $baseId);
    $name = trim($name);
    $res = graph_cache($baseId, 'subject|' . $name, GRAPH_TTL_READ, static function () use ($pdo, $baseId, $ns, $name): array {
        $f = graph_find_subject($name);
        if (!$f['ok']) {
            return $f;
        }
        if ($f['subject'] === null) {
            return ['ok' => true, 'found' => false, 'nocache' => true];
        }
        return graph_subject_build($pdo, $baseId, $ns, $f['subject']);
    });
    if (!$res['ok']) {
        return ['subject' => ['subject_id' => null, 'name' => $name, 'type' => null, 'description' => null], 'aliases' => [], 'statements' => [], 'neighbours' => [], 'neighbours_more' => false,
                'sections' => [], 'svg_nodes' => [], 'errors' => ['engine' => $res['code']]];
    }
    if (!$res['found']) {
        return null;
    }
    $aliases = $res['engine_aliases'];
    if (graph_curates($baseId)) {
        $aliases = array_merge($aliases, graph_vocabulary_aliases($pdo, $baseId, (string) $res['subject']['name']));
    }
    $subject = $res['subject'];
    $subject['description'] = graph_description_text($subject['description'] ?? null);
    return ['subject' => $subject, 'aliases' => array_values(array_unique($aliases)), 'statements' => graph_group_statements($res['statements']), 'neighbours' => $res['neighbours'],
            'neighbours_more' => (bool) $res['neighbours_more'], 'sections' => $res['sections'], 'svg_nodes' => $res['svg_nodes'], 'errors' => []];
}

// ---------------------------------------------------------------------------------------------------------------------
// A community
// ---------------------------------------------------------------------------------------------------------------------

/**
 * One community, verified to be one of the base's (kn_engine_communities): ['community' => {community_id, label, size}, 'members' => list nodes
 * (subjects confined, documents as sections), 'more', 'errors']. null = not one of the base's. An engine failure answers errors['engine'].
 */
function graph_community(PDO $pdo, int $baseId, int $communityId): ?array
{
    $list = graph_region_communities($pdo, $baseId);
    if (!$list['ok']) {
        return ['community' => ['community_id' => $communityId, 'label' => null, 'size' => null], 'members' => [], 'more' => false, 'errors' => ['engine' => $list['code']]];
    }
    $mine = null;
    foreach ($list['communities'] as $c) {
        if ($c['community_id'] === $communityId) {
            $mine = $c;
        }
    }
    if ($mine === null) {
        return null;
    }
    $ns = graph_ns($pdo, $baseId);
    $res = graph_cache($baseId, 'community|' . $communityId, GRAPH_TTL_READ, static function () use ($pdo, $baseId, $ns, $communityId): array {
        $r = kn_engine_community_members($communityId, 200);
        if (!$r['ok']) {
            return graph_fail($r);
        }
        $rows = $r['data']['members'] ?? [];
        $subjects = [];
        $docs = [];
        foreach ($rows as $m) {
            if (($m['object_kind'] ?? '') === 'subject') {
                $subjects[] = ['id' => (int) $m['object_id'], 'name' => (string) ($m['canonical_name'] ?? $m['label'] ?? '')];
            } elseif (($m['object_kind'] ?? '') === 'document') {
                $docs[] = (int) $m['object_id'];
            }
        }
        $conf = graph_confine_nodes($ns, 'subject', $subjects);
        if (!$conf['ok']) {
            return $conf;
        }
        $docsIn = $docs === [] ? [] : kn_engine_documents_in($ns, $docs);
        if ($docsIn === null) {
            return ['ok' => false, 'code' => 'engine_error'];
        }
        $sections = graph_sections_by_documents($pdo, $baseId, array_keys($docsIn));
        $members = [];
        foreach ($conf['nodes'] as $n) {
            $members[] = ['kind' => 'subject', 'id' => (int) $n['id'], 'name' => $n['name'], 'degree' => (int) $n['degree']];
        }
        foreach ($sections as $s) {
            $members[] = graph_section_node($s);
        }
        return ['ok' => true, 'members' => $members, 'more' => $conf['more'] || count($subjects) > GRAPH_CAP];
    });
    if (!$res['ok']) {
        return ['community' => $mine, 'members' => [], 'more' => false, 'errors' => ['engine' => $res['code']]];
    }
    return ['community' => $mine, 'members' => $res['members'], 'more' => (bool) $res['more'], 'errors' => []];
}

// ---------------------------------------------------------------------------------------------------------------------
// A path
// ---------------------------------------------------------------------------------------------------------------------

/** "subject:12" → ['subject', 12], or null. */
function graph_node_ref(string $ref): ?array
{
    return preg_match('/^([a-z_]{1,40}):([0-9]{1,18})$/', $ref, $m) === 1 ? [$m[1], (int) $m[2]] : null;
}

/** The chain of one engine path if EVERY node of it is the base's, else null. Nodes: {kind, id, name, verb?, section?}. $names: ref → known name. */
function graph_path_nodes(PDO $pdo, int $baseId, string $ns, array $refs, array $names): ?array
{
    $parsed = [];
    foreach ($refs as $ref) {
        $p = graph_node_ref((string) $ref);
        if ($p === null) {
            return null;
        }
        $parsed[] = $p;
    }
    $docs = array_values(array_unique(array_map(static fn (array $p): int => $p[1], array_filter($parsed, static fn (array $p): bool => $p[0] === 'document'))));
    $docsIn = $docs === [] ? [] : kn_engine_documents_in($ns, $docs);
    if ($docsIn === null) {
        throw new RuntimeException('engine_error');
    }
    foreach ($docs as $d) {
        if (!isset($docsIn[$d])) {
            return null;
        }
    }
    foreach (['subject', 'statement'] as $kind) {
        $of = array_values(array_unique(array_map(static fn (array $p): int => $p[1], array_filter($parsed, static fn (array $p): bool => $p[0] === $kind))));
        if ($of !== []) {
            $c = graph_confine_nodes($ns, $kind, array_map(static fn (int $i): array => ['id' => $i], $of));
            if (!$c['ok']) {
                throw new RuntimeException($c['code']);
            }
            if (count($c['nodes']) !== count($of)) {
                return null;
            }
        }
    }
    foreach ($parsed as [$kind, $id]) {
        if (!in_array($kind, ['subject', 'statement', 'document'], true)) {
            return null;
        }
        $r = graph_neighbors($kind, $id);
        if (!$r['ok']) {
            throw new RuntimeException($r['code']);
        }
    }
    $sections = graph_sections_by_documents($pdo, $baseId, $docs);
    $label = static function (string $kind, int $id) use ($names, $parsed): string {
        if (isset($names[$kind . ':' . $id])) {
            return $names[$kind . ':' . $id];
        }
        foreach ($parsed as [$k, $i]) {
            foreach (graph_neighbors($k, $i)['data']['neighbors'] ?? [] as $x) {
                if (($x['neighbor_kind'] ?? '') === $kind && (int) $x['neighbor_id'] === $id && ($x['label'] ?? '') !== '') {
                    return (string) $x['label'];
                }
            }
        }
        return ucfirst($kind) . ' ' . $id;
    };
    $nodes = [];
    foreach ($parsed as $i => [$kind, $id]) {
        $verb = null;
        if (isset($parsed[$i + 1])) {
            [$nk, $ni] = $parsed[$i + 1];
            foreach (graph_neighbors($kind, $id)['data']['neighbors'] ?? [] as $x) {
                if (($x['neighbor_kind'] ?? '') === $nk && (int) $x['neighbor_id'] === $ni && ($x['rel'] ?? null) !== null && $x['rel'] !== '') {
                    $verb = (string) $x['rel'];
                    break;
                }
            }
        }
        if ($kind === 'document') {
            $node = isset($sections[$id]) ? graph_section_node($sections[$id]) : ['kind' => 'section', 'id' => 0, 'name' => 'A document of this base'];
        } else {
            $node = ['kind' => $kind, 'id' => $id, 'name' => $label($kind, $id)];
        }
        $nodes[] = $node + ['verb' => $verb];
    }
    return $nodes;
}

/**
 * How two subjects of the base are related, inside the base: ['from', 'to', 'path' => the chain of nodes or null, 'withheld' => bool (a node
 * of the path is another base's — or an end is not this base's: the same words, no node named), 'error' => ?engine code]. The shortest path
 * wholly inside the base wins; none at all is path null and withheld false.
 */
function graph_path(PDO $pdo, int $baseId, string $from, string $to): array
{
    $from = trim($from);
    $to = trim($to);
    $out = ['from' => $from, 'to' => $to, 'path' => null, 'withheld' => false, 'error' => null];
    if ($from === '' || $to === '') {
        return $out;
    }
    $ns = graph_ns($pdo, $baseId);
    $res = graph_cache($baseId, 'path|' . $from . '|' . $to, GRAPH_TTL_READ, static function () use ($pdo, $baseId, $ns, $from, $to): array {
        $ends = [];
        foreach ([$from, $to] as $name) {
            $f = graph_find_subject($name);
            if (!$f['ok']) {
                return $f;
            }
            $code = null;
            if ($f['subject'] === null || graph_subject_in_base($ns, $f['subject'], $code) === null) {
                return $code !== null ? ['ok' => false, 'code' => $code] : ['ok' => true, 'path' => null, 'withheld' => true];
            }
            $ends[] = $f['subject'];
        }
        if ($ends[0]['subject_id'] === $ends[1]['subject_id']) {
            return ['ok' => true, 'path' => [['kind' => 'subject', 'id' => $ends[0]['subject_id'], 'name' => $ends[0]['name'], 'verb' => null]], 'withheld' => false];
        }
        $r = kn_engine_path(['subject', $ends[0]['subject_id']], ['subject', $ends[1]['subject_id']], 6);
        if (!$r['ok']) {
            return graph_fail($r);
        }
        $paths = array_slice($r['data']['paths'] ?? [], 0, 8);
        $names = ['subject:' . $ends[0]['subject_id'] => $ends[0]['name'], 'subject:' . $ends[1]['subject_id'] => $ends[1]['name']];
        try {
            foreach ($paths as $p) {
                $nodes = graph_path_nodes($pdo, $baseId, $ns, (array) ($p['path'] ?? []), $names);
                if ($nodes !== null) {
                    return ['ok' => true, 'path' => $nodes, 'withheld' => false];
                }
            }
        } catch (RuntimeException $e) {
            return ['ok' => false, 'code' => $e->getMessage()];
        }
        return ['ok' => true, 'path' => null, 'withheld' => $paths !== []];
    });
    if (!$res['ok']) {
        return ['error' => $res['code']] + $out;
    }
    return ['path' => $res['path'], 'withheld' => (bool) $res['withheld']] + $out;
}

// ---------------------------------------------------------------------------------------------------------------------
// Duplicates (curators)
// ---------------------------------------------------------------------------------------------------------------------

/**
 * The subjects met on the base's own documents (no engine route lists a namespace's subjects: up to 200 documents, each one's subject
 * neighbours), grouped when two names normalise alike or when the vocabulary makes one the other's alias while both still exist; a group the
 * vocabulary already folds into one seeded term is left out. Cached 10 minutes. ['ok' => true, 'groups' => [{normalised, reason, suggested,
 * subjects: [{subject_id, name, type, documents}], vocabulary_term_id?}], 'documents_read', 'documents_total'].
 */
function duplicate_subjects(PDO $pdo, int $baseId, string $ns): array
{
    return graph_cache($baseId, 'duplicates', GRAPH_TTL_DUPLICATES, static function () use ($pdo, $baseId, $ns): array {
        $d = kn_engine_documents($ns, 200);
        if (!$d['ok']) {
            return graph_fail($d);
        }
        $docs = array_map(static fn (array $x): int => (int) $x['id'], $d['data']['documents'] ?? []);
        $total = (int) ($d['data']['_total'] ?? count($docs));
        $met = [];
        foreach ($docs as $docId) {
            $nb = graph_neighbors('document', $docId);
            if (!$nb['ok']) {
                return graph_fail($nb);
            }
            foreach ($nb['data']['neighbors'] ?? [] as $x) {
                if (($x['neighbor_kind'] ?? '') === 'subject') {
                    $id = (int) $x['neighbor_id'];
                    $met[$id] ??= ['subject_id' => $id, 'name' => (string) ($x['label'] ?? ''), 'type' => null, 'docs' => []];
                    $met[$id]['docs'][$docId] = true;
                }
            }
        }
        $subjects = [];
        foreach ($met as $m) {
            $subjects[] = ['subject_id' => $m['subject_id'], 'name' => $m['name'], 'type' => $m['type'], 'documents' => count($m['docs'])];
        }
        usort($subjects, static fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['subject_id']] <=> [mb_strtolower($b['name']), $b['subject_id']]);
        $terms = [];
        $st = $pdo->prepare('SELECT term_id, canonical_name, aliases, maludb_subject_id FROM mcp_vocabulary_terms WHERE base_id = :b ORDER BY term_id');
        $st->execute(['b' => $baseId]);
        foreach ($st->fetchAll() as $t) {
            $terms[] = ['term_id' => (int) $t['term_id'], 'canonical' => (string) $t['canonical_name'], 'aliases' => pg_text_array((string) $t['aliases']), 'seeded' => $t['maludb_subject_id'] !== null];
        }
        return ['ok' => true, 'groups' => graph_duplicate_groups($subjects, $terms), 'documents_read' => count($docs), 'documents_total' => $total];
    });
}

/**
 * The groups of a set of subjects. $terms: [{term_id, canonical, aliases, seeded}]. Pure — the proof calls it with what it likes.
 * @param list<array{subject_id: int, name: string, type: ?string, documents: int}> $subjects
 */
function graph_duplicate_groups(array $subjects, array $terms): array
{
    $byNorm = [];
    foreach ($subjects as $s) {
        $n = graph_normal_name($s['name']);
        if ($n !== '') {
            $byNorm[$n][] = $s;
        }
    }
    $raw = [];
    foreach ($byNorm as $norm => $list) {
        if (count($list) > 1) {
            $raw[] = ['normalised' => $norm, 'reason' => 'alike', 'subjects' => $list, 'term' => null];
        }
    }
    $byLower = [];
    foreach ($subjects as $s) {
        $byLower[mb_strtolower($s['name'])] = $s;
    }
    foreach ($terms as $t) {
        $met = [];
        foreach (array_merge([$t['canonical']], $t['aliases']) as $name) {
            if (isset($byLower[mb_strtolower($name)])) {
                $met[mb_strtolower($name)] = $byLower[mb_strtolower($name)];
            }
        }
        $ids = array_map(static fn (array $m): int => $m['subject_id'], $met);
        sort($ids);
        $same = array_filter($raw, static function (array $g) use ($ids): bool {
            $have = array_map(static fn (array $m): int => $m['subject_id'], $g['subjects']);
            sort($have);
            return $have === $ids;
        });
        if (count($met) > 1 && isset($byLower[mb_strtolower($t['canonical'])]) && $same === []) {
            $raw[] = ['normalised' => graph_normal_name($t['canonical']), 'reason' => 'vocabulary', 'subjects' => array_values($met), 'term' => $t];
        }
    }
    $groups = [];
    foreach ($raw as $g) {
        $names = array_map(static fn (array $s): string => mb_strtolower($s['name']), $g['subjects']);
        $folded = false;
        foreach ($terms as $t) {
            if (!$t['seeded']) {
                continue;
            }
            $covers = array_map('mb_strtolower', array_merge([$t['canonical']], $t['aliases']));
            if (array_diff($names, $covers) === []) {
                $folded = true;
            }
        }
        if ($folded) {
            continue;
        }
        $subs = $g['subjects'];
        usort($subs, static fn (array $a, array $b): int => [-$a['documents'], mb_strlen($a['name']), mb_strtolower($a['name'])] <=> [-$b['documents'], mb_strlen($b['name']), mb_strtolower($b['name'])]);
        $suggested = $g['term'] !== null ? $g['term']['canonical'] : $subs[0]['name'];          // the vocabulary already says which name is the canonical one
        $groups[] = ['normalised' => $g['normalised'], 'reason' => $g['reason'], 'suggested' => $suggested, 'subjects' => $subs] + ($g['term'] !== null ? ['vocabulary_term_id' => $g['term']['term_id']] : []);
    }
    return $groups;
}

// ---------------------------------------------------------------------------------------------------------------------
// What a section / a source taught the graph (slice 2's screens)
// ---------------------------------------------------------------------------------------------------------------------

/** The subjects and statements from the neighbour rows of documents: [{subject_id, name}] and [{statement_id, text, verb}]. */
function graph_taught_rows(array $rows): array
{
    $subjects = [];
    $statements = [];
    foreach ($rows as $x) {
        $id = (int) ($x['neighbor_id'] ?? 0);
        if (($x['neighbor_kind'] ?? '') === 'subject') {
            $subjects[$id] ??= ['subject_id' => $id, 'name' => (string) ($x['label'] ?? '')];
        } elseif (($x['neighbor_kind'] ?? '') === 'statement') {
            $statements[$id] ??= ['statement_id' => $id, 'text' => (string) ($x['label'] ?? ''), 'verb' => ($x['rel'] ?? null) === null ? null : (string) $x['rel']];
        }
    }
    return [$subjects, $statements];
}

/**
 * What one section contributed to the graph: its document's neighbours (confined by being the base's own document's — a subject touching only
 * this document is still the base's). ['ok' => true, 'built' => bool (false: "Not in the graph yet."), 'subjects', 'statements'] or ['ok' => false, 'code'].
 */
function section_taught(PDO $pdo, int $sectionId): array
{
    $x = find_section($pdo, $sectionId);
    if ($x === null || $x['status'] === 'forgotten') {
        return ['ok' => true, 'built' => false, 'subjects' => [], 'statements' => []];
    }
    $doc = $x['maludb_document_id'] === null ? 0 : (int) $x['maludb_document_id'];
    if ($doc <= 0) {
        return ['ok' => true, 'built' => false, 'subjects' => [], 'statements' => []];
    }
    $baseId = (int) $x['base_id'];
    return graph_cache($baseId, 'taught|' . $sectionId . '|' . $doc, GRAPH_TTL_READ, static function () use ($doc): array {
        $r = graph_neighbors('document', $doc);
        if (!$r['ok']) {
            return graph_fail($r);
        }
        [$subjects, $statements] = graph_taught_rows($r['data']['neighbors'] ?? []);
        return ['ok' => true, 'built' => true, 'subjects' => array_values($subjects), 'statements' => array_values($statements)];
    });
}

/** The union of what a source's live, built sections taught (at most 40 sections asked): subjects with how many of those sections touch them. */
function source_taught(PDO $pdo, int $sourceId): array
{
    $s = find_source($pdo, $sourceId);
    if ($s === null) {
        return ['ok' => true, 'built' => false, 'subjects' => [], 'statements' => [], 'sections_read' => 0, 'sections_total' => 0];
    }
    $baseId = (int) $s['base_id'];
    $st = $pdo->prepare("SELECT section_id, maludb_document_id FROM mcp_sections WHERE source_id = :s AND status <> 'forgotten' ORDER BY position, section_id");
    $st->execute(['s' => $sourceId]);
    $all = $st->fetchAll();
    $built = array_values(array_filter($all, static fn (array $r): bool => $r['maludb_document_id'] !== null && (int) $r['maludb_document_id'] > 0));
    if ($built === []) {
        return ['ok' => true, 'built' => false, 'subjects' => [], 'statements' => [], 'sections_read' => 0, 'sections_total' => count($all)];
    }
    $read = array_slice($built, 0, 40);
    $key = 'source-taught|' . $sourceId . '|' . implode(',', array_map(static fn (array $r): string => $r['section_id'] . ':' . $r['maludb_document_id'], $read));
    $res = graph_cache($baseId, $key, GRAPH_TTL_READ, static function () use ($read): array {
        $subjects = [];
        $statements = [];
        foreach ($read as $r) {
            $nb = graph_neighbors('document', (int) $r['maludb_document_id']);
            if (!$nb['ok']) {
                return graph_fail($nb);
            }
            [$subs, $stms] = graph_taught_rows($nb['data']['neighbors'] ?? []);
            foreach ($subs as $id => $sub) {
                $subjects[$id] ??= $sub + ['sections' => 0];
                $subjects[$id]['sections']++;
            }
            foreach ($stms as $id => $stm) {
                $statements[$id] ??= $stm;
            }
        }
        $list = array_values($subjects);
        usort($list, static fn (array $a, array $b): int => [-$a['sections'], mb_strtolower($a['name'])] <=> [-$b['sections'], mb_strtolower($b['name'])]);
        return ['ok' => true, 'subjects' => $list, 'statements' => array_values($statements)];
    });
    if (!$res['ok']) {
        return $res;
    }
    return ['ok' => true, 'built' => true, 'subjects' => $res['subjects'], 'statements' => $res['statements'], 'sections_read' => count($read), 'sections_total' => count($all)];
}
