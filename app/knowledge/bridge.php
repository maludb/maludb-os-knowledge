<?php
declare(strict_types=1);

/**
 * The engine bridge (docs/knowledge-mcp-tool-surface.md "The engine bridge"; slice 5 builds the PHP side): how the records MCP server reaches
 * kn_ask() and the engine without ever holding the MaluDB token. POST /internal/bridge.php on the INTERNAL port only, signed
 *   X-KN-Bridge: {unix_ts}.{hex hmac_sha256(ACTIONS_RELAY_KEY, "kn-bridge:" . unix_ts . "." . sha256_hex(body))}
 * with {op, caller: {kind: member, member_id} | {kind: application, application}, door, run_id, request_id, is_eval, args}. A member caller
 * acts with the screens' own gates (app.member_id set for the request); an application caller is honoured for the share ops only (slice 7).
 *   kn_bridge_verify()    401 unless loopback, ±30 s and the HMAC verifies — the server's REMOTE_ADDR, never a header
 *   kn_bridge_dispatch()  the op table: ask, search, suggest, admin_view, and (slice 6) the graph ops; slices 3 and 7 add theirs (KN_BRIDGE_OPS)
 */
require_once APP_ROOT . '/app/knowledge/ask.php';
require_once APP_ROOT . '/app/features/ask/queries.php';
require_once APP_ROOT . '/app/features/sources/queries.php';
require_once APP_ROOT . '/app/features/graph/queries.php';

const KN_BRIDGE_SKEW = 30;
/** op => [handler function, the caller kinds it admits]. Later slices append. */
const KN_BRIDGE_OPS = [
    'ask'        => ['kn_bridge_ask', ['member']],
    'search'     => ['kn_bridge_search', ['member']],
    'suggest'    => ['kn_bridge_suggest', ['member']],
    'admin_view' => ['kn_bridge_admin_view', ['member']],
    // slice 6 — the graph explorer's reads (docs/knowledge-mcp-tool-surface.md K1–K7, S3, S4), each behind the reader gate (duplicate_subjects the curator's)
    'graph_stats'         => ['kn_bridge_graph_stats', ['member']],
    'communities'         => ['kn_bridge_communities', ['member']],
    'community_members'   => ['kn_bridge_community_members', ['member']],
    'subject'             => ['kn_bridge_subject', ['member']],
    'subject_neighbors'   => ['kn_bridge_subject_neighbors', ['member']],
    'subject_statements'  => ['kn_bridge_subject_statements', ['member']],
    'path'                => ['kn_bridge_path', ['member']],
    'god_nodes'           => ['kn_bridge_god_nodes', ['member']],
    'surprises'           => ['kn_bridge_surprises', ['member']],
    'graph_query'         => ['kn_bridge_graph_query', ['member']],
    'section_graph'       => ['kn_bridge_section_graph', ['member']],
    'source_graph'        => ['kn_bridge_source_graph', ['member']],
    'sections_for_subject' => ['kn_bridge_sections_for_subject', ['member']],
    'duplicate_subjects'  => ['kn_bridge_duplicate_subjects', ['member']],
];

/** Null when the request may pass; else [http, code, message]. $server: $_SERVER (REMOTE_ADDR, HTTP_X_KN_BRIDGE). */
function kn_bridge_verify(array $server, string $body, ?int $now = null): ?array
{
    $deny = ['401', 'unauthorized', 'The bridge answers a signed loopback call only.'];
    if (!in_array((string) ($server['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)) {
        return $deny;
    }
    $key = (string) env('ACTIONS_RELAY_KEY', '');
    $h = (string) ($server['HTTP_X_KN_BRIDGE'] ?? '');
    if (strlen($key) < 32 || !preg_match('/^(\d{9,12})\.([0-9a-f]{64})$/', $h, $m)) {
        return $deny;
    }
    if (abs(($now ?? time()) - (int) $m[1]) > KN_BRIDGE_SKEW) {
        return $deny;
    }
    $want = hash_hmac('sha256', 'kn-bridge:' . $m[1] . '.' . hash('sha256', $body), $key);
    return hash_equals($want, $m[2]) ? null : $deny;
}

/** The signature a caller sends (the proofs and the Python side compute the same). */
function kn_bridge_sign(string $body, ?int $ts = null): string
{
    $ts ??= time();
    return $ts . '.' . hash_hmac('sha256', 'kn-bridge:' . $ts . '.' . hash('sha256', $body), (string) env('ACTIONS_RELAY_KEY', ''));
}

/** One bridged call: [http, body]. The member's identity is set for this request only. */
function kn_bridge_dispatch(PDO $pdo, array $msg): array
{
    $op = (string) ($msg['op'] ?? '');
    if (!isset(KN_BRIDGE_OPS[$op])) {
        return [404, ['ok' => false, 'error' => ['code' => 'unknown_op', 'message' => 'No such bridge operation.']]];
    }
    [$fn, $kinds] = KN_BRIDGE_OPS[$op];
    $caller = is_array($msg['caller'] ?? null) ? $msg['caller'] : [];
    $kind = (string) ($caller['kind'] ?? '');
    if (!in_array($kind, $kinds, true)) {
        return [403, ['ok' => false, 'error' => ['code' => 'caller_not_allowed', 'message' => 'This operation is not offered to that caller.']]];
    }
    if ($kind === 'member') {
        $m = find_member_by_id($pdo, (int) ($caller['member_id'] ?? 0));
        if ($m === null || $m['status'] !== 'active' || $m['capability'] === null) {
            return [403, ['ok' => false, 'error' => ['code' => 'unknown_member', 'message' => 'That member is not admitted here.']]];
        }
        $_SESSION['member_id'] = (int) $m['id'];
        if (!empty($msg['run_id'])) {
            $GLOBALS['__agent_run_id'] = (int) $msg['run_id'];
        }
        db_apply_context($pdo);
    }
    return $fn($pdo, $msg, is_array($msg['args'] ?? null) ? $msg['args'] : []);
}

/** A base named by id or key → its id (0 when unknown — the gate then says "not found"). */
function kn_bridge_base(PDO $pdo, mixed $base): int
{
    if (is_int($base) || ctype_digit((string) $base)) {
        return (int) $base;
    }
    return (int) (one_value($pdo, 'SELECT id FROM knowledge_bases WHERE key = :k', ['k' => (string) $base]) ?? 0);
}

function kn_bridge_door(array $msg): string
{
    return (string) ($msg['door'] ?? '') === 'agent' || !empty($msg['run_id']) ? 'agent' : 'mcp';
}

/** A kn_ask()/kn_search() answer as the bridge's [http, body]: the document verbatim, or {ok: false, error, fields}. */
function kn_bridge_answer(array $r): array
{
    if (!$r['ok']) {
        return [$r['http'], ['ok' => false, 'error' => $r['error'] + ['fields' => (object) ($r['fields'] ?? [])]]];
    }
    unset($r['ok'], $r['http'], $r['fields']);
    return [200, $r];
}

function kn_bridge_ask(PDO $pdo, array $msg, array $a): array
{
    return kn_bridge_answer(kn_ask($pdo, ['base_id' => kn_bridge_base($pdo, $a['base'] ?? 0), 'question' => (string) ($a['question'] ?? ''), 'focus_area_id' => $a['focus_area'] ?? null,
        'conversation_id' => $a['conversation_id'] ?? null, 'door' => kn_bridge_door($msg), 'is_eval' => !empty($msg['is_eval'])]));
}

function kn_bridge_search(PDO $pdo, array $msg, array $a): array
{
    return kn_bridge_answer(kn_search($pdo, ['base_id' => kn_bridge_base($pdo, $a['base'] ?? 0), 'query' => (string) ($a['query'] ?? ''), 'focus_area_id' => $a['focus_area'] ?? null,
        'limit' => (int) ($a['limit'] ?? 8), 'door' => kn_bridge_door($msg)]));
}

function kn_bridge_suggest(PDO $pdo, array $msg, array $a): array
{
    $bid = kn_bridge_base($pdo, $a['base'] ?? 0);
    if ($bid === 0 || !db_bool($pdo, 'SELECT kn_can_read_base(:b)', ['b' => $bid])) {
        return [404, ['ok' => false, 'error' => ['code' => 'not_found', 'message' => 'Knowledge base not found.']]];
    }
    $area = isset($a['focus_area']) && ctype_digit((string) $a['focus_area']) ? (int) $a['focus_area'] : null;
    return [200, ['questions' => suggested_questions($pdo, $bid, $area, max(1, min(20, (int) ($a['limit'] ?? 6))))]];
}

/** An admin reading a private base they hold no share in: base.admin_view, once per base per caller per hour. Answers {admin_view}. */
function kn_bridge_admin_view(PDO $pdo, array $msg, array $a): array
{
    $bid = kn_bridge_base($pdo, $a['base'] ?? 0);
    $b = $bid ? one_value($pdo, 'SELECT visibility FROM knowledge_bases WHERE id = :b', ['b' => $bid]) : null;
    $me = (int) current_member_id();
    $view = $b === 'private' && is_kn_admin() && !base_reach_without_admin($pdo, $bid, $me);
    if ($view && one_value($pdo, "SELECT 1 FROM activity_log WHERE action = 'base.admin_view' AND base_id = :b AND actor_member_id = :m AND created_at > now() - interval '1 hour' LIMIT 1", ['b' => $bid, 'm' => $me]) === null) {
        log_activity($pdo, 'base.admin_view', 'base', $bid, ['base_id' => $bid, 'source' => ask_source(kn_bridge_door($msg)), 'actor_member_id' => $me, 'after' => ['base_id' => $bid, 'tool' => (string) ($a['tool'] ?? '')]]);
    }
    return [200, ['admin_view' => $view]];
}

// ---------------------------------------------------------------------------------------------------------------------
// The graph ops (slice 6). Each one: the reader's gate (kn_can_read_base — the screens' own; a base one may not read is "not found", the same words
// as a base that does not exist; duplicate_subjects the curator's), then this slice's query function, then the tool surface's shape. A document node
// is replaced by its section {section_id, heading_path, source: {id, title}, page_no}; a document with no section is dropped; no tenant-wide count.
// ---------------------------------------------------------------------------------------------------------------------

function kn_bridge_not_found(string $what = 'Knowledge base not found.'): array
{
    return [404, ['ok' => false, 'error' => ['code' => 'not_found', 'message' => $what]]];
}

/** The engine's failure as the bridge's: unavailable and timeout keep their codes, anything else is engine_error with the engine's code as `detail`. */
function kn_bridge_engine_error(string $code): array
{
    $c = in_array($code, ['engine_unavailable', 'engine_timeout'], true) ? $code : 'engine_error';
    return [$c === 'engine_timeout' ? 504 : ($c === 'engine_unavailable' ? 503 : 502), ['ok' => false, 'error' => ['code' => $c, 'message' => kn_engine_error_message($code), 'detail' => $code]]];
}

/** The base a graph op names, or the refusal: [base row, null] | [null, [http, body]]. $curate: a curator's op (403 `curators_only`). */
function kn_bridge_graph_base(PDO $pdo, array $a, bool $curate = false): array
{
    $bid = kn_bridge_base($pdo, $a['base'] ?? 0);
    $b = $bid > 0 ? find_base($pdo, $bid) : null;
    if ($b === null || !db_bool($pdo, 'SELECT kn_can_read_base(:b)', ['b' => $bid])) {
        return [null, kn_bridge_not_found()];
    }
    if ($curate && !db_bool($pdo, 'SELECT kn_can_curate_base(:b)', ['b' => $bid])) {
        return [null, [403, ['ok' => false, 'error' => ['code' => 'curators_only', 'message' => 'This tool is for the base\'s curators.']]]];
    }
    graph_fresh(false);
    return [$b, null];
}

function kn_bridge_graph_limit(array $a, string $key, int $default, int $max): int
{
    return max(1, min($max, isset($a[$key]) && is_numeric($a[$key]) ? (int) $a[$key] : $default));
}

/** A section as the tools show it. */
function kn_bridge_section(array $s): array
{
    return ['section_id' => (int) $s['section_id'], 'heading_path' => (string) $s['heading_path'], 'source' => ['id' => (int) $s['source']['id'], 'title' => (string) $s['source']['title']], 'page_no' => $s['page'] === null ? null : (int) $s['page']];
}

/** A subject named by id or name → [subject row {subject_id, name, type, description}, null] | [null, [http, body]]; not in the base is `not_in_base`. */
function kn_bridge_subject_ref(PDO $pdo, int $baseId, mixed $ref): array
{
    $ref = is_string($ref) ? trim($ref) : $ref;
    if ($ref === '' || $ref === null || is_array($ref)) {
        return [null, [422, ['ok' => false, 'error' => ['code' => 'invalid_argument', 'message' => 'Name the subject (its name or its id).', 'fields' => ['subject' => 'Required.']]]]];
    }
    $f = null;
    if (is_int($ref) || ctype_digit((string) $ref)) {
        $f = graph_find_subject_by_id((int) $ref);
        if ($f['ok'] && $f['subject'] === null) {
            $f = graph_find_subject((string) $ref);
        }
    } else {
        $f = graph_find_subject((string) $ref);
    }
    if (!$f['ok']) {
        return [null, kn_bridge_engine_error($f['code'])];
    }
    $code = null;
    if ($f['subject'] === null || graph_subject_in_base(graph_ns($pdo, $baseId), $f['subject'], $code) === null) {
        return [null, $code !== null ? kn_bridge_engine_error($code) : [404, ['ok' => false, 'error' => ['code' => 'not_in_base', 'message' => 'That subject is not in this knowledge base.']]]];
    }
    return [$f['subject'], null];
}

/** graph_subject() for a ref: the subject's cached, confined page, or the refusal. [data, null] | [null, [http, body]]. */
function kn_bridge_subject_data(PDO $pdo, int $baseId, mixed $ref): array
{
    [$s, $err] = kn_bridge_subject_ref($pdo, $baseId, $ref);
    if ($err !== null) {
        return [null, $err];
    }
    $r = graph_subject($pdo, $baseId, (string) $s['name']);
    if ($r === null) {
        return [null, [404, ['ok' => false, 'error' => ['code' => 'not_in_base', 'message' => 'That subject is not in this knowledge base.']]]];
    }
    if (isset($r['errors']['engine'])) {
        return [null, kn_bridge_engine_error($r['errors']['engine'])];
    }
    return [$r, null];
}

function kn_bridge_graph_stats(PDO $pdo, array $msg, array $a): array
{
    [$b, $err] = kn_bridge_graph_base($pdo, $a);
    if ($err !== null) {
        return $err;
    }
    $id = (int) $b['base_id'];
    $out = ['base_id' => $id, 'documents' => null, 'documents_cached' => (int) $b['documents_count'], 'subjects_count' => (int) $b['subjects_count'], 'statements_count' => (int) $b['statements_count'],
            'communities_count' => (int) $b['communities_count'], 'last_built_at' => json_ts($b['last_built_at']), 'top_subjects' => [], 'top_verbs' => []];
    $size = graph_region_size($pdo, $id);
    $god = $size['ok'] ? graph_region_god_nodes($pdo, $id, 10, true) : $size;
    if (!$size['ok'] || !$god['ok']) {
        return [200, $out + ['engine' => 'unavailable']];
    }
    $out['documents'] = (int) $size['documents'];
    $out['top_subjects'] = array_map(static fn (array $n): array => ['subject_id' => (int) $n['id'], 'name' => $n['name'], 'degree' => (int) ($n['degree'] ?? 0)], $god['nodes']);
    $conf = graph_confine_nodes(graph_ns($pdo, $id), 'subject', array_map(static fn (array $n): array => ['id' => $n['id'], 'name' => $n['name']], $god['nodes']));
    if ($conf['ok']) {
        $verbs = [];
        foreach ($conf['nodes'] as $n) {
            foreach ($n['rels'] as $v => $c) {
                $verbs[$v] = ($verbs[$v] ?? 0) + $c;
            }
        }
        arsort($verbs);
        $out['top_verbs'] = array_map(static fn (string $v, int $c): array => ['verb' => $v, 'count' => $c], array_keys(array_slice($verbs, 0, 10, true)), array_values(array_slice($verbs, 0, 10, true)));
    }
    return [200, $out];
}

function kn_bridge_communities(PDO $pdo, array $msg, array $a): array
{
    [$b, $err] = kn_bridge_graph_base($pdo, $a);
    if ($err !== null) {
        return $err;
    }
    $r = graph_region_communities($pdo, (int) $b['base_id']);
    if (!$r['ok']) {
        return kn_bridge_engine_error($r['code']);
    }
    $list = array_slice($r['communities'], 0, kn_bridge_graph_limit($a, 'limit', 50, 200));
    return [200, ['communities' => array_map(static fn (array $c): array => ['community_id' => $c['community_id'], 'label' => $c['label'], 'size' => $c['size']], $list),
                  'note' => $list === [] ? 'MaluDB has not grouped this base\'s subjects into communities yet; god_nodes shows the most connected subjects.' : '']];
}

function kn_bridge_community_members(PDO $pdo, array $msg, array $a): array
{
    [$b, $err] = kn_bridge_graph_base($pdo, $a);
    if ($err !== null) {
        return $err;
    }
    $cid = (int) ($a['community'] ?? 0);
    $c = $cid > 0 ? graph_community($pdo, (int) $b['base_id'], $cid) : null;
    if ($c === null) {
        return [404, ['ok' => false, 'error' => ['code' => 'not_in_base', 'message' => 'Community not found.']]];
    }
    if (isset($c['errors']['engine'])) {
        return kn_bridge_engine_error($c['errors']['engine']);
    }
    $limit = kn_bridge_graph_limit($a, 'limit', 200, 200);
    return [200, ['community_id' => $cid, 'members' => array_map(static fn (array $n): array => ['kind' => $n['kind'], 'id' => (int) $n['id'], 'name' => $n['name']]
        + (isset($n['section']) ? ['section' => kn_bridge_section($n['section'])] : []), array_slice($c['members'], 0, $limit))]];
}

function kn_bridge_subject(PDO $pdo, array $msg, array $a): array
{
    [$b, $err] = kn_bridge_graph_base($pdo, $a);
    if ($err !== null) {
        return $err;
    }
    [$r, $err] = kn_bridge_subject_data($pdo, (int) $b['base_id'], $a['subject'] ?? null);
    if ($err !== null) {
        return $err;
    }
    $statements = 0;
    foreach ($r['statements'] as $g) {
        $statements += count($g['statements']);
    }
    return [200, ['subject_id' => $r['subject']['subject_id'], 'name' => $r['subject']['name'], 'type' => $r['subject']['type'], 'description' => $r['subject']['description'], 'aliases' => $r['aliases'],
                  'statements' => $statements, 'neighbours' => count($r['neighbours']), 'sections' => array_map('kn_bridge_section', array_slice($r['sections'], 0, 20))]];
}

function kn_bridge_subject_neighbors(PDO $pdo, array $msg, array $a): array
{
    [$b, $err] = kn_bridge_graph_base($pdo, $a);
    if ($err !== null) {
        return $err;
    }
    [$r, $err] = kn_bridge_subject_data($pdo, (int) $b['base_id'], $a['subject'] ?? null);
    if ($err !== null) {
        return $err;
    }
    $want = (string) ($a['kind'] ?? '');
    if ($want !== '' && !in_array($want, ['subject', 'document', 'statement'], true)) {
        return [422, ['ok' => false, 'error' => ['code' => 'invalid_argument', 'message' => 'kind is subject, document or statement.', 'fields' => ['kind' => 'subject, document or statement.']]]];
    }
    $rows = [];
    foreach ($r['neighbours'] as $n) {
        $rows[] = ['kind' => 'subject', 'id' => $n['subject_id'], 'name' => $n['name']] + ($n['verb'] !== null ? ['verb' => $n['verb']] : []);
    }
    foreach ($r['sections'] as $s) {
        $rows[] = ['kind' => 'section', 'id' => (int) $s['section_id'], 'name' => $s['heading_path'], 'section' => kn_bridge_section($s)];
    }
    foreach ($r['statements'] as $g) {
        foreach ($g['statements'] as $x) {
            $rows[] = ['kind' => 'statement', 'id' => $x['statement_id'], 'name' => $x['text']] + ($g['verb'] !== null ? ['verb' => $g['verb']] : []) + (isset($x['section']) ? ['section' => kn_bridge_section($x['section'])] : []);
        }
    }
    if ($want !== '') {                                                  // a document is shown as its section
        $rows = array_values(array_filter($rows, static fn (array $x): bool => $x['kind'] === ($want === 'document' ? 'section' : $want)));
    }
    return [200, ['subject' => ['subject_id' => $r['subject']['subject_id'], 'name' => $r['subject']['name']], 'neighbours' => array_slice($rows, 0, kn_bridge_graph_limit($a, 'limit', 50, 200))]];
}

function kn_bridge_subject_statements(PDO $pdo, array $msg, array $a): array
{
    [$b, $err] = kn_bridge_graph_base($pdo, $a);
    if ($err !== null) {
        return $err;
    }
    [$r, $err] = kn_bridge_subject_data($pdo, (int) $b['base_id'], $a['subject'] ?? null);
    if ($err !== null) {
        return $err;
    }
    $verb = trim((string) ($a['verb'] ?? ''));
    $left = kn_bridge_graph_limit($a, 'limit', 50, 200);
    $by = [];
    foreach ($r['statements'] as $g) {
        if ($verb !== '' && mb_strtolower((string) $g['verb']) !== mb_strtolower($verb)) {
            continue;
        }
        $items = [];
        foreach ($g['statements'] as $x) {
            if ($left-- <= 0) {
                break;
            }
            $items[] = ['statement_id' => $x['statement_id'], 'text' => $x['text'], 'object' => null, 'section' => isset($x['section']) ? kn_bridge_section($x['section']) : null];
        }
        if ($items !== []) {
            $by[] = ['verb' => $g['verb'], 'statements' => $items];
        }
    }
    return [200, ['subject' => ['subject_id' => $r['subject']['subject_id'], 'name' => $r['subject']['name']], 'by_verb' => $by]];
}

function kn_bridge_path(PDO $pdo, array $msg, array $a): array
{
    [$b, $err] = kn_bridge_graph_base($pdo, $a);
    if ($err !== null) {
        return $err;
    }
    $id = (int) $b['base_id'];
    $names = [];
    foreach (['from', 'to'] as $k) {
        $ref = $a[$k] ?? null;
        if (is_int($ref) || (is_string($ref) && ctype_digit(trim($ref)) && trim($ref) !== '')) {
            $f = graph_find_subject_by_id((int) $ref);
            if (!$f['ok']) {
                return kn_bridge_engine_error($f['code']);
            }
            $ref = $f['subject']['name'] ?? (string) $ref;
        }
        if (!is_string($ref) || trim($ref) === '') {
            return [422, ['ok' => false, 'error' => ['code' => 'invalid_argument', 'message' => 'Name both subjects.', 'fields' => [$k => 'Required.']]]];
        }
        $names[$k] = trim($ref);
    }
    $p = graph_path($pdo, $id, $names['from'], $names['to']);
    if ($p['error'] !== null) {
        return kn_bridge_engine_error($p['error']);
    }
    if ($p['withheld']) {
        return [404, ['ok' => false, 'error' => ['code' => 'no_path_in_base', 'message' => 'There is no path between these inside this base.']]];
    }
    $steps = [];
    foreach ($p['path'] ?? [] as $n) {
        $steps[] = ['kind' => $n['kind'], 'id' => (int) $n['id'], 'name' => $n['name']] + ($n['verb'] !== null ? ['verb' => $n['verb']] : [])
            + (isset($n['section']) ? ['section' => kn_bridge_section($n['section'])] : []);
    }
    return [200, ['found' => $p['path'] !== null, 'steps' => $steps]];
}

function kn_bridge_god_nodes(PDO $pdo, array $msg, array $a): array
{
    [$b, $err] = kn_bridge_graph_base($pdo, $a);
    if ($err !== null) {
        return $err;
    }
    $limit = kn_bridge_graph_limit($a, 'limit', 10, 50);
    $r = graph_region_god_nodes($pdo, (int) $b['base_id'], $limit);
    if (!$r['ok']) {
        return kn_bridge_engine_error($r['code']);
    }
    return [200, ['god_nodes' => array_map(static fn (array $n): array => ['kind' => $n['kind'], 'id' => (int) $n['id'], 'name' => $n['name'], 'degree' => (int) ($n['degree'] ?? 0)]
        + (isset($n['section']) ? ['section' => kn_bridge_section($n['section'])] : []), array_slice($r['nodes'], 0, $limit))]];
}

function kn_bridge_surprises(PDO $pdo, array $msg, array $a): array
{
    [$b, $err] = kn_bridge_graph_base($pdo, $a);
    if ($err !== null) {
        return $err;
    }
    $r = graph_region_surprises($pdo, (int) $b['base_id']);
    if (!$r['ok']) {
        return kn_bridge_engine_error($r['code']);
    }
    $end = static fn (array $n): array => ['kind' => $n['kind'], 'id' => (int) $n['id'], 'name' => $n['name']];
    return [200, ['surprises' => array_map(static fn (array $s): array => ['from' => $end($s['from']), 'to' => $end($s['to']), 'verb' => $s['verb'], 'score' => (int) $s['pair_edges'],
        'sections' => array_values(array_map('kn_bridge_section', array_filter([$s['from']['section'] ?? null, $s['to']['section'] ?? null])))], array_slice($r['surprises'], 0, kn_bridge_graph_limit($a, 'limit', 25, 50)))]];
}

function kn_bridge_graph_query(PDO $pdo, array $msg, array $a): array
{
    [$b, $err] = kn_bridge_graph_base($pdo, $a);
    if ($err !== null) {
        return $err;
    }
    $text = trim((string) ($a['text'] ?? ''));
    if (mb_strlen($text) < 2 || mb_strlen($text) > 400) {
        return [422, ['ok' => false, 'error' => ['code' => 'invalid_argument', 'message' => 'text is 2 to 400 characters.', 'fields' => ['text' => '2 to 400 characters.']]]];
    }
    $id = (int) $b['base_id'];
    $ns = graph_ns($pdo, $id);
    $depth = kn_bridge_graph_limit($a, 'depth', 2, 3);
    $max = kn_bridge_graph_limit($a, 'max_nodes', 50, 100);
    $r = graph_cache($id, 'graph_query|' . $text . '|' . $depth . '|' . $max, GRAPH_TTL_READ, static function () use ($ns, $text, $depth, $max): array {
        $q = kn_engine_graph_query($text, $ns, $depth, $max);
        return $q['ok'] ? ['ok' => true, 'seeds' => $q['data']['seeds'] ?? [], 'nodes' => $q['data']['nodes'] ?? [], 'edges' => $q['data']['edges'] ?? []] : graph_fail($q);
    });
    if (!$r['ok']) {
        return kn_bridge_engine_error($r['code']);
    }
    $docs = array_map(static fn (array $n): int => (int) $n['object_id'], array_filter($r['nodes'], static fn (array $n): bool => ($n['object_kind'] ?? '') === 'document'));
    $sections = graph_sections_by_documents($pdo, $id, $docs);
    $drop = [];
    $nodes = [];
    foreach ($r['nodes'] as $n) {
        if (($n['object_kind'] ?? '') === 'document') {
            if (!isset($sections[(int) $n['object_id']])) {
                $drop['document:' . (int) $n['object_id']] = true;
                continue;
            }
            $nodes[] = ['kind' => 'section', 'id' => $sections[(int) $n['object_id']]['section_id'], 'name' => $sections[(int) $n['object_id']]['heading_path'], 'section' => kn_bridge_section($sections[(int) $n['object_id']])];
        } else {
            $nodes[] = ['kind' => (string) $n['object_kind'], 'id' => (int) $n['object_id'], 'name' => (string) $n['label']];
        }
    }
    $key = static fn (string $k, int $i): string => $k === 'document' && isset($sections[$i]) ? 'section:' . $sections[$i]['section_id'] : $k . ':' . $i;
    $edges = [];
    foreach ($r['edges'] as $e) {
        if (isset($drop['document:' . (int) $e['source_id']]) && $e['source_kind'] === 'document' || isset($drop['document:' . (int) $e['target_id']]) && $e['target_kind'] === 'document') {
            continue;
        }
        $edges[] = ['from' => $key((string) $e['source_kind'], (int) $e['source_id']), 'to' => $key((string) $e['target_kind'], (int) $e['target_id']), 'verb' => $e['rel'] ?? null];
    }
    return [200, ['seeds' => array_map(static fn (array $s): array => ['subject_id' => (int) $s['subject_id'], 'name' => (string) $s['canonical_name']], $r['seeds']), 'nodes' => $nodes, 'edges' => $edges]];
}

/** The section a graph op names, readable by the caller (mcp_sections is the gate): [section, base, null] | [null, null, refusal]. */
function kn_bridge_section_ref(PDO $pdo, mixed $ref): array
{
    $x = is_numeric($ref) ? find_section($pdo, (int) $ref) : null;
    $b = $x === null ? null : find_base($pdo, (int) $x['base_id']);
    return $x === null || $b === null ? [null, null, kn_bridge_not_found('Section not found.')] : [$x, $b, null];
}

function kn_bridge_section_graph(PDO $pdo, array $msg, array $a): array
{
    [$x, $b, $err] = kn_bridge_section_ref($pdo, $a['section'] ?? null);
    if ($err !== null) {
        return $err;
    }
    $t = section_taught($pdo, (int) $x['section_id']);
    if (!$t['ok']) {
        return kn_bridge_engine_error($t['code']);
    }
    $subjects = array_map(static fn (array $s): array => ['subject_id' => (int) $s['subject_id'], 'name' => $s['name'], 'type' => null], $t['subjects']);
    $about = $t['subjects'][0]['name'] ?? '';
    return [200, ['section_id' => (int) $x['section_id'], 'extracted_version' => $x['extracted_version'] === null ? null : (int) $x['extracted_version'], 'extracted' => (bool) $t['built'],
                  'subjects' => $subjects, 'statements' => array_map(static fn (array $s): array => ['subject' => $about, 'verb' => $s['verb'], 'object' => null, 'statement_id' => (int) $s['statement_id'], 'text' => $s['text']], $t['statements'])]];
}

function kn_bridge_source_graph(PDO $pdo, array $msg, array $a): array
{
    $s = is_numeric($a['source'] ?? null) ? find_source($pdo, (int) $a['source']) : null;
    if ($s === null) {
        return kn_bridge_not_found('Source not found.');
    }
    $t = source_taught($pdo, (int) $s['source_id']);
    if (!$t['ok']) {
        return kn_bridge_engine_error($t['code']);
    }
    $limit = kn_bridge_graph_limit($a, 'limit', 100, 100);
    return [200, ['source_id' => (int) $s['source_id'], 'sections_read' => (int) ($t['sections_read'] ?? 0), 'sections_total' => (int) ($t['sections_total'] ?? 0),
                  'subjects' => array_map(static fn (array $x): array => ['subject_id' => (int) $x['subject_id'], 'name' => $x['name'], 'type' => null, 'sections' => (int) $x['sections']], array_slice($t['subjects'], 0, $limit))]];
}

function kn_bridge_sections_for_subject(PDO $pdo, array $msg, array $a): array
{
    [$b, $err] = kn_bridge_graph_base($pdo, $a);
    if ($err !== null) {
        return $err;
    }
    [$r, $err] = kn_bridge_subject_data($pdo, (int) $b['base_id'], $a['subject'] ?? null);
    if ($err !== null) {
        return $err;
    }
    return [200, ['subject' => ['subject_id' => $r['subject']['subject_id'], 'name' => $r['subject']['name']], 'sections' => array_map('kn_bridge_section', array_slice($r['sections'], 0, kn_bridge_graph_limit($a, 'limit', 20, 100)))]];
}

function kn_bridge_duplicate_subjects(PDO $pdo, array $msg, array $a): array
{
    [$b, $err] = kn_bridge_graph_base($pdo, $a, true);
    if ($err !== null) {
        return $err;
    }
    $d = duplicate_subjects($pdo, (int) $b['base_id'], graph_ns($pdo, (int) $b['base_id']));
    if (!$d['ok']) {
        return kn_bridge_engine_error($d['code']);
    }
    return [200, ['groups' => array_map(static fn (array $g): array => ['normalised' => $g['normalised'], 'subjects' => array_map(static fn (array $s): array => ['subject_id' => (int) $s['subject_id'], 'name' => $s['name'], 'type' => $s['type'] ?? null], $g['subjects'])]
        + (isset($g['vocabulary_term_id']) ? ['vocabulary_term_id' => (int) $g['vocabulary_term_id']] : []), array_slice($d['groups'], 0, kn_bridge_graph_limit($a, 'limit', 50, 50))),
        'documents_read' => (int) $d['documents_read'], 'documents_total' => (int) $d['documents_total']]];
}
