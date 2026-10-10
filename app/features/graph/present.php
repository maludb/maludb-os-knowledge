<?php
declare(strict_types=1);

/**
 * The graph explorer — what a screen shows and what JSON answers (whitelists: names, kinds, ids, counts, section ids and heading paths —
 * never a section's text, never an engine document id, never a tenant-wide figure).
 */

const GRAPH_KINDS = [
    'subject'   => ['feather-circle', 'primary', 'subject'],
    'section'   => ['feather-file-text', 'secondary', 'section'],
    'statement' => ['feather-message-circle', 'info', 'statement'],
];

/**
 * This page's own address as a ?back= target: the path and query as requested, minus `back` (so exploring subject after subject does not nest every
 * earlier page inside the next link) and minus `fresh` (a curator's bypass is not a place to come back to).
 */
function graph_here(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
    parse_str((string) (parse_url($uri, PHP_URL_QUERY) ?? ''), $q);
    unset($q['back'], $q['fresh'], $q['notice']);
    return $path . ($q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '');
}

function graph_url(int $baseId, string $screen, array $q = []): string
{
    return '/bases/' . $baseId . '/' . $screen . ($q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '');
}

function graph_subject_url(int $baseId, string $name): string
{
    return graph_url($baseId, 'subject', ['name' => $name]);
}

function graph_ask_url(int $baseId, string $about): string
{
    return '/ask?' . http_build_query(['base' => $baseId, 'q' => 'What does this knowledge base say about ' . $about . '?'], '', '&', PHP_QUERY_RFC3986);
}

/** The sentence a region shows for an engine code (the module's own words; the invalid-search code has its own). */
function graph_error_words(string $code): string
{
    return $code === 'invalid_argument' ? 'Search with at least one word of two letters or more.' : kn_engine_error_message($code);
}

/** The icon, colour and word of a node kind. */
function graph_kind(string $kind): array
{
    return GRAPH_KINDS[$kind] ?? GRAPH_KINDS['subject'];
}

/** A section as JSON: where it is, never its words. */
function present_graph_section(array $s): array
{
    return ['section_id' => (int) $s['section_id'], 'heading_path' => (string) $s['heading_path'], 'source' => ['id' => (int) $s['source']['id'], 'title' => (string) $s['source']['title']],
            'page_no' => $s['page'] === null ? null : (int) $s['page'], 'url' => '/sections/' . (int) $s['section_id']];
}

function present_graph_subject_row(int $baseId, array $s): array
{
    return ['subject_id' => (int) $s['subject_id'], 'name' => (string) $s['name'], 'documents' => (int) ($s['documents'] ?? 0),
            'verb' => ($s['verb'] ?? null) === null ? null : (string) $s['verb'], 'url' => graph_subject_url($baseId, (string) $s['name'])];
}

/** A node of a list (god nodes, surprise ends, path steps): a subject, a section or a statement. */
function present_graph_node(int $baseId, array $n): array
{
    $out = ['kind' => $n['kind'], 'id' => (int) $n['id'], 'name' => (string) $n['name']];
    if ($n['kind'] === 'subject') {
        $out['url'] = graph_subject_url($baseId, (string) $n['name']);
    }
    if (isset($n['section']) && is_array($n['section'])) {
        $out['section'] = present_graph_section($n['section']);
        $out['url'] ??= '/sections/' . (int) $n['section']['section_id'];
    }
    foreach (['degree', 'verb'] as $k) {
        if (array_key_exists($k, $n) && $n[$k] !== null) {
            $out[$k] = $k === 'degree' ? (int) $n[$k] : (string) $n[$k];
        }
    }
    return $out;
}

function present_graph_overview(int $baseId, array $o, ?array $duplicates, string $q, ?array $search): array
{
    $out = [
        'size' => ['documents' => $o['size']['documents'], 'subjects' => $o['size']['subjects'], 'statements' => $o['size']['statements'], 'communities' => $o['size']['communities'],
                   'last_built_at' => json_ts($o['size']['last_built_at'])],
        'search' => $q === '' ? null : ['q' => $q, 'items' => array_map(static fn (array $i): array => present_graph_subject_row($baseId, $i) + ['seed' => (bool) $i['seed']], $search['items'] ?? []),
                                        'more' => (bool) ($search['more'] ?? false)],
        'communities' => array_map(static fn (array $c): array => ['community_id' => (int) $c['community_id'], 'label' => (string) $c['label'], 'size' => (int) $c['size'],
                                   'url' => graph_url($baseId, 'community', ['community' => (int) $c['community_id']])], $o['communities'] ?? []),
        'connected' => array_map(static fn (array $n): array => present_graph_node($baseId, $n), $o['connected'] ?? []),
        'god_nodes' => array_map(static fn (array $n): array => present_graph_node($baseId, $n), $o['god_nodes'] ?? []),
        'surprises' => array_map(static fn (array $s): array => ['from' => present_graph_node($baseId, $s['from']), 'to' => present_graph_node($baseId, $s['to']), 'verb' => $s['verb'] ?? null], $o['surprises'] ?? []),
        'errors' => (object) ($o['errors'] ?? []),
    ];
    if ($duplicates !== null) {
        $out['duplicates'] = present_graph_duplicates($baseId, $duplicates);
    }
    return $out;
}

function present_graph_duplicates(int $baseId, array $d): array
{
    return ['groups' => array_map(static fn (array $g): array => [
        'normalised' => (string) $g['normalised'], 'reason' => (string) $g['reason'], 'suggested' => (string) $g['suggested'],
        'subjects' => array_map(static fn (array $s): array => ['subject_id' => (int) $s['subject_id'], 'name' => (string) $s['name'], 'type' => $s['type'] ?? null, 'documents' => (int) $s['documents'],
                                                                'url' => graph_subject_url($baseId, (string) $s['name'])], $g['subjects']),
        'vocabulary_term_id' => $g['vocabulary_term_id'] ?? null, 'merge_url' => graph_merge_url($baseId, $g),
    ], $d['groups'] ?? []), 'documents_read' => (int) ($d['documents_read'] ?? 0), 'documents_total' => (int) ($d['documents_total'] ?? 0)];
}

/** The vocabulary form (slice 3) prefilled with the suggestion as the term and the others as its aliases. */
function graph_merge_url(int $baseId, array $group): string
{
    $others = array_values(array_filter(array_map(static fn (array $s): string => (string) $s['name'], $group['subjects']), static fn (string $n): bool => $n !== $group['suggested']));
    return '/vocabulary/new?' . http_build_query(['base' => $baseId, 'canonical_name' => $group['suggested'], 'aliases' => implode("\n", $others)], '', '&', PHP_QUERY_RFC3986);
}

function present_graph_subject(int $baseId, array $r): array
{
    $s = $r['subject'];
    return [
        'subject' => ['subject_id' => $s['subject_id'] === null ? null : (int) $s['subject_id'], 'name' => (string) $s['name'], 'type' => $s['type'] ?? null, 'description' => $s['description'] ?? null,
                      'url' => graph_subject_url($baseId, (string) $s['name'])],
        'aliases' => array_values($r['aliases']),
        'statements' => array_map(static fn (array $g): array => ['verb' => $g['verb'], 'statements' => array_map(static fn (array $x): array => ['statement_id' => (int) $x['statement_id'], 'text' => (string) $x['text'],
            'section' => isset($x['section']) ? present_graph_section($x['section']) : null], $g['statements'])], $r['statements']),
        'neighbours' => array_map(static fn (array $n): array => present_graph_subject_row($baseId, $n), $r['neighbours']),
        'neighbours_more' => (bool) $r['neighbours_more'],
        'sections' => array_map('present_graph_section', $r['sections']),
        'errors' => (object) $r['errors'],
        'ask_url' => graph_ask_url($baseId, (string) $s['name']),
    ];
}

function present_graph_community(int $baseId, array $c): array
{
    return [
        'community' => ['community_id' => (int) $c['community']['community_id'], 'label' => $c['community']['label'], 'size' => $c['community']['size']],
        'members' => array_map(static fn (array $n): array => present_graph_node($baseId, $n), $c['members']),
        'members_more' => (bool) $c['more'], 'errors' => (object) $c['errors'],
        'ask_url' => graph_ask_url($baseId, (string) ($c['community']['label'] ?? 'this community')),
    ];
}

function present_graph_path(int $baseId, array $p): array
{
    return ['from' => $p['from'], 'to' => $p['to'], 'found' => $p['path'] !== null, 'withheld' => (bool) $p['withheld'], 'error' => $p['error'] ?? null,
            'steps' => $p['path'] === null ? [] : array_map(static fn (array $n): array => present_graph_node($baseId, $n), $p['path'])];
}
