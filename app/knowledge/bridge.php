<?php
declare(strict_types=1);

/**
 * The engine bridge (docs/knowledge-mcp-tool-surface.md "The engine bridge"; slice 5 builds the PHP side): how the records MCP server reaches
 * kn_ask() and the engine without ever holding the MaluDB token. POST /internal/bridge.php on the INTERNAL port only, signed
 *   X-KN-Bridge: {unix_ts}.{hex hmac_sha256(ACTIONS_RELAY_KEY, "kn-bridge:" . unix_ts . "." . sha256_hex(body))}
 * with {op, caller: {kind: member, member_id} | {kind: application, application}, door, run_id, request_id, is_eval, args}. A member caller
 * acts with the screens' own gates (app.member_id set for the request); an application caller is honoured for the share ops only (slice 7).
 *   kn_bridge_verify()    401 unless loopback, ±30 s and the HMAC verifies — the server's REMOTE_ADDR, never a header
 *   kn_bridge_dispatch()  the op table: ask, search, suggest, admin_view here; slices 3, 6 and 7 add theirs (KN_BRIDGE_OPS)
 */
require_once APP_ROOT . '/app/knowledge/ask.php';
require_once APP_ROOT . '/app/features/ask/queries.php';

const KN_BRIDGE_SKEW = 30;
/** op => [handler function, the caller kinds it admits]. Later slices append. */
const KN_BRIDGE_OPS = [
    'ask'        => ['kn_bridge_ask', ['member']],
    'search'     => ['kn_bridge_search', ['member']],
    'suggest'    => ['kn_bridge_suggest', ['member']],
    'admin_view' => ['kn_bridge_admin_view', ['member']],
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
