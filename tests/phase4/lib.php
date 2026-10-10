<?php
/**
 * Helpers for the Phase 4 proofs (docs/knowledge-mcp-tool-surface.md). Run through tests/phase4/run.sh: a SCRATCH database (kn_dev_p4 — never the installed one), the application on :8501, a fake kernel on :8502 (run-facts, K6, K7),
 * THE FAKE ENGINE on :8503 (tests/fake_kb_engine.php — $FAKE_KB_STATE drives it), a fake MaluDB + MaluMail on :8506, and the TWO MCP SERVERS on :8504 (records) and :8505 (activity); the records server reaches the application's
 * own door (the bridge) on the internal port. Everything a proof makes is named "SMOKE …" or "p4 …". Builds on slice 6's and slice 7's helpers (so 5's … 1's and Phase 2's): the cast is the admin (1), Priya (26, a Member),
 * Marco (27, a Curator — Cara in the slice 5 world — owner of every base), Bea (28, a Member with no share to the private bases), Ann (29, a guest), Reid (32, a Reader), Seamus (40, the expert agent), the Watcher (41, an agent
 * the kernel never vouched for). The worlds are slice 5's Handbook (ask_world), slice 6's Pumps (graph_world) and slice 7's Manuals (keys_world, shared with the application `helpdesk`), plus p4_world()'s additions.
 */
require_once dirname(__DIR__) . '/phase3/slice6/lib.php';
require_once dirname(__DIR__) . '/phase3/slice7/lib.php';

const REC = 8504;
const ACT = 8505;
/** The tools that take no arguments (the client sends no `params`). */
const NO_ARGS = ['app_roles', 'get_settings'];

/** A person's own mcp_ token (the servers resolve it through mcp_resolve_token). Cached per member. */
function ptoken(int $member): string
{
    static $t = [];
    if (isset($t[$member])) { return $t[$member]; }
    $raw = 'mcp_' . bin2hex(random_bytes(24));
    q("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (:m, 'SMOKE p4', :h, 'mcp')", ['m' => $member, 'h' => hash('sha256', $raw)]);
    return $t[$member] = $raw;
}
/** An API KEY (scope api) — the token API's, refused by the MCP servers. It must be bound to a base. */
function ktoken_api(int $member, int $base): string
{
    $raw = 'knk_' . bin2hex(random_bytes(24));
    q("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, base_id) VALUES (:m, 'SMOKE p4 key', :h, 'api', :b)", ['m' => $member, 'h' => hash('sha256', $raw), 'b' => $base]);
    return $raw;
}
/** The tenant's signed RUN token an agent presents ('mid.exp.run.hmac' over run:mid.exp.run). */
function rtoken(int $member, int $run, int $ttl = 300): string { return run_token($member, $run, $ttl); }
/** A person's action token (the command bar's): 'mid.exp.hmac'. */
function atoken(int $member, int $ttl = 300): string { return person_token($member, $ttl); }
/** The kernel's own 60-second token: 'kernel.exp.app.nonce.hmac' over kernel:exp.app.nonce. */
function kernel_token(string $app = 'knowledge', int $ttl = 60): string
{
    $p = (time() + $ttl) . '.' . $app . '.' . bin2hex(random_bytes(16));
    return 'kernel.' . $p . '.' . hash_hmac('sha256', 'kernel:' . $p, need('ACTION_TOKEN_KEY'));
}
/** A run id for this execution of a proof (the server caches a run's facts for 300 s, so a rerun must not reuse one). */
function rid(int $n): int { static $b = null; $b ??= (time() % 100000) * 100; return $b + $n; }
/** Tell the fake kernel what the run-facts call answers for a run id. $endpoints: [name => [tool => constraints]] or [] for another application's. */
function facts(int $run, int $member, array $endpoints, array $o = []): void
{
    kernel_state(function ($s) use ($run, $member, $endpoints, $o) {
        $eps = [];
        foreach ($endpoints as $name => $tools) { $eps[] = ['id' => 1, 'name' => $name, 'tools' => (object) $tools]; }
        $s['facts'][(string) $run] = ['valid' => $o['valid'] ?? true, 'is_agent' => $o['is_agent'] ?? true, 'member_id' => $member, 'run_id' => $run, 'request_id' => "req-run-$run",
            'trigger' => $o['trigger'] ?? 'chat', 'is_eval' => ($o['trigger'] ?? '') === 'eval', 'endpoints' => $eps];
        return $s;
    });
}

// ---- the MCP client (the kernel's own: initialize, initialized, then the call) ------------------------------------
function mcp_post(int $port, string $token, array $msg, ?string $session = null, string $path = '/mcp', array $extra = []): array
{
    $h = array_merge(['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: 2025-06-18'], $extra);
    if ($token !== '') { $h[] = 'Authorization: Bearer ' . $token; }
    if ($session) { $h[] = 'Mcp-Session-Id: ' . $session; }
    $sess = $session;
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($msg), CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$sess) { if (stripos($line, 'mcp-session-id:') === 0) { $sess = trim(substr($line, 15)); } return strlen($line); }]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    if (!is_string($body) || $body === '') { return [null, $sess, $status]; }
    if (stripos($type, 'text/event-stream') !== false) {
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (str_starts_with($line, 'data:')) { $m = json_decode(trim(substr($line, 5)), true); if (is_array($m) && ($m['id'] ?? null) === ($msg['id'] ?? null)) { return [$m, $sess, $status]; } }
        }
        return [null, $sess, $status];
    }
    return [json_decode($body, true), $sess, $status];
}
function mcp_open(int $port, string $token, string $path = '/mcp', array $extra = []): array
{
    [$init, $sess, $st] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'p4', 'version' => '1']]], null, $path, $extra);
    if ($st === 200) { mcp_post($port, $token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sess, $path, $extra); }
    return [$sess, $st, $init];
}
/** The tool names a bearer is offered, or null when the server refused it (401). */
function mcp_tools(int $port, string $token, array $extra = []): ?array
{
    [$sess, $st] = mcp_open($port, $token, '/mcp', $extra);
    if ($st !== 200) { return null; }
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sess, '/mcp', $extra);
    $names = array_map(fn ($t) => $t['name'], $a['result']['tools'] ?? []);
    sort($names);
    return $names;
}
/** The full tools/list answer (names, descriptions, annotations, input schemas). */
function mcp_tool_defs(int $port, string $token): array
{
    [$sess] = mcp_open($port, $token);
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sess);
    return $a['result']['tools'] ?? [];
}
/**
 * Call a tool as a bearer. Answers ['http', 'error' => bool (the tool refused), 'text', 'data' => decoded JSON or null, 'code' => the error code when the tool answered {ok:false,error:{code}}]. Arguments are wrapped as
 * {"params": ...} the way FastMCP's models take them, except the kernel's flat call ($flat) and the tools that take none (app_roles, get_settings).
 */
function mcp_tool(int $port, string $token, string $tool, array $args = [], bool $flat = false, array $extra = []): array
{
    @file_put_contents(need('KN_DEV_STATE') . '/p4-called.txt', $tool . "\n", FILE_APPEND);
    [$sess, $st] = mcp_open($port, $token, '/mcp', $extra);
    if ($st !== 200) { return ['http' => $st, 'error' => true, 'text' => '', 'data' => null, 'code' => null]; }
    $arguments = $flat || ($args === [] && in_array($tool, NO_ARGS, true)) ? (object) $args : ['params' => (object) $args];
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]], $sess, '/mcp', $extra);
    $res = $a['result'] ?? [];
    $text = (string) ($res['content'][0]['text'] ?? ($a['error']['message'] ?? ''));
    $data = json_decode($text, true);
    $code = is_array($data) && ($data['ok'] ?? null) === false ? ($data['error']['code'] ?? null) : null;
    return ['http' => 200, 'error' => !empty($res['isError']) || isset($a['error']) || $code !== null, 'text' => $text, 'data' => is_array($data) ? $data : null, 'code' => $code];
}
/** A tool as a person (their own token): the whole answer, or just the decoded data. */
function tool(int $member, string $name, array $args = [], int $port = REC): array { return mcp_tool($port, ptoken($member), $name, $args); }
function tdata(int $member, string $name, array $args = [], int $port = REC) { return tool($member, $name, $args, $port)['data']; }
/** Every key anywhere in a decoded answer. */
function has_key($data, string $key): bool
{
    if (!is_array($data)) { return false; }
    foreach ($data as $k => $v) { if ($k === $key || has_key($v, $key)) { return true; } }
    return false;
}
function find_row(?array $rows, string $key, $value): ?array { foreach ($rows ?? [] as $r) { if ((string) ($r[$key] ?? '') === (string) $value) { return $r; } } return null; }
function ids(?array $rows, string $key): array { return array_values(array_map(fn ($r) => $r[$key], $rows ?? [])); }
/** SQL over the views AS a member (the same rows the server hands that member). */
function sql_as(int $member, string $sql, array $args = []): array
{
    pdo()->exec("SELECT set_config('app.member_id', '$member', false)");
    $rows = q($sql, $args);
    pdo()->exec("SELECT set_config('app.member_id', '', false)");
    return $rows;
}
function val_as(int $member, string $sql, array $args = []) { $r = sql_as($member, $sql, $args); return $r ? array_values($r[0])[0] : null; }

/**
 * The Phase 4 world — slice 5's Handbook, slice 6's Pumps/Valves and slice 7's Manuals/Contracts, plus (made once per run, named p4):
 *   a question of Marco's on the private base "SMOKE Private Notes" (answered, with a citation) and one of Priya's on the Handbook, a rated one, a pinned answer, a gap (no_evidence), a failed build with an item,
 *   a key bound to the Manuals, a note on the Handbook, an activity row of share.read for helpdesk, a proposal and a dispatch of the Archivist.
 */
function p4_world(): array
{
    static $w = null;
    if ($w !== null) { return $w; }
    $file = (string) getenv('KN_DEV_STATE') . '/world-p4.json';
    if (is_file($file)) { $w = json_decode((string) file_get_contents($file), true); p4_more($w); return $w; }
    $a = ask_world();
    $g = graph_world();
    $k = keys_world();
    $p = pdo();
    $hb = $a['handbook'];
    // Priya's answered question on the Handbook, with its two citations (through the real ask path, scripted two-citation answer)
    kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [carry_script($a)]]);
    [, $b1] = ask_as(PRIYA, ['base' => $hb, 'question' => 'p4 How does carry-over work?']);
    // a no_evidence one (a gap) by Priya, and a private question of Marco's
    kb_state(['scripts' => [['match' => 'unicorn', 'answer' => m1('no_evidence', [], [], null, 0, 0)]]]);
    [, $b2] = ask_as(PRIYA, ['base' => $hb, 'question' => 'p4 Do unicorn leave days exist?']);
    $pvEvid = [ev(1, 601, 'The private plan for next year.', ['base' => $a['private'], 'section' => $a['private_section'], 'page' => 1])];
    kb_state(['scripts' => [['match' => 'private plan', 'answer' => m1('answered', $pvEvid, [cite(1, 'The private plan for next year', 601)], 'The private plan is for next year [1].')]]]);
    [, $b3] = ask_as(MARCO, ['base' => $a['private'], 'question' => 'p4 What is the private plan?']);
    $p->exec("INSERT INTO question_feedback (question_id, member_id, rating, comment) VALUES (" . (int) $b1['question_id'] . ", " . PRIYA . ", 'up', 'p4 thanks')");
    $p->exec("INSERT INTO notes (record_type, record_id, member_id, body) VALUES ('base', $hb, " . MARCO . ", 'p4 note on the handbook')");
    $pin = (int) one("INSERT INTO pinned_answers (base_id, question_id, title, question_text, answer_md, owner_member_id, review_until, status) VALUES (:b, :q, 'p4 Carry-over', 'How does carry-over work?', 'Five days carry over.', :m, current_date - 3, 'active') RETURNING id",
        ['b' => $hb, 'q' => (int) $b1['question_id'], 'm' => MARCO]);
    $key = (int) one("INSERT INTO mcp_access_tokens (member_id, token_hash, label, scope, base_id) VALUES (:m, :h, 'p4 manuals key', 'api', :b) RETURNING id", ['m' => MARCO, 'h' => hash('sha256', 'p4-' . bin2hex(random_bytes(8))), 'b' => $k['manuals']]);
    $build = (int) one("INSERT INTO builds (base_id, kind, started_by) VALUES (:b, 'extract', :m) RETURNING id", ['b' => $hb, 'm' => MARCO]);
    $p->exec("UPDATE builds SET status = 'running' WHERE id = $build");
    $p->exec("INSERT INTO build_items (build_id, section_id, status, chars, error) VALUES ($build, {$a['carry']}, 'failed', 90, 'p4 engine said no')");
    $p->exec("UPDATE builds SET status = 'failed', error = 'p4 failed build' WHERE id = $build");
    $p->exec("INSERT INTO activity_log (actor_member_id, source, action, entity_type, base_id, after) VALUES (NULL, 'application', 'share.read', NULL, " . $k['manuals'] . ", '{\"application\":\"helpdesk\",\"tool\":\"ask\",\"outcome\":\"ok\",\"ms\":12}'),
                                                                                                          (NULL, 'application', 'share.read', NULL, NULL, '{\"application\":\"helpdesk\",\"tool\":\"search\",\"outcome\":\"not_shared\",\"ms\":3}')");
    $prop = (int) one("INSERT INTO archivist_proposals (base_id, kind, title, reason, payload, proposed_by) VALUES (:b, 'gap', 'p4 Add a source on unicorn leave', 'Two questions had no evidence.', '{}', :m) RETURNING id", ['b' => $hb, 'm' => SEAMUS]);
    $disp = (int) one("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, status, base_id) VALUES ('question', :q, :a, 'ask', 'chat', :m, 'answered', :b) RETURNING id",
        ['q' => (int) $b1['question_id'], 'a' => SEAMUS, 'm' => PRIYA, 'b' => $hb]);
    $w = ['ask' => $a, 'graph' => $g, 'keys' => $k, 'q_carry' => (int) $b1['question_id'], 'q_gap' => (int) $b2['question_id'], 'q_private' => (int) $b3['question_id'], 'pin' => $pin, 'key' => $key, 'build' => $build,
          'proposal' => $prop, 'dispatch' => $disp];
    p4_more($w);
    file_put_contents($file, json_encode($w));
    return $w;
}
/** What the later proofs need that the world above does not make (idempotent): the Handbook's pages (a paged source) and a feed. */
function p4_more(array $w): void
{
    if ((int) one('SELECT count(*) FROM feeds') > 0) { return; }
    $a = $w['ask'];
    $r = q('SELECT id, char_from, char_to FROM sections WHERE id IN (:c, :s)', ['c' => $a['carry'], 's' => $a['sick']]);
    $by = array_column($r, null, 'id');
    pdo()->exec("INSERT INTO source_pages (source_id, page_no, char_from, char_to) VALUES ({$a['source']}, 3, {$by[$a['carry']]['char_from']}, {$by[$a['carry']]['char_to']}), ({$a['source']}, 4, {$by[$a['sick']]['char_from']}, {$by[$a['sick']]['char_to']})");
    pdo()->exec("UPDATE sources SET page_count = 4 WHERE id = {$a['source']}");
    pdo()->exec("INSERT INTO feeds (base_id, provider, tool, arguments, every_minutes, enabled, last_status, last_error, created_by) VALUES ({$a['handbook']}, 'spaces', 'pages_index', '{}', 60, true, 'no_connection', 'p4 no approved connection', " . MARCO . ")");
}
/** The tool surface (docs/knowledge-mcp-tool-surface.md) as the proofs read it: the named tools of each server, sorted. */
function surface(): array
{
    static $s = null;
    if ($s !== null) { return $s; }
    $t = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/knowledge-mcp-tool-surface.md');
    $a = strpos($t, '## Records server'); $b = strpos($t, '## The answer, the evidence'); $c = strpos($t, '## Activity server'); $d = strpos($t, '### The activity vocabulary');
    preg_match_all('/^\| `([a-z_]+)`/m', substr($t, $a, $b - $a), $rec);
    preg_match_all('/^\| `([a-z_]+)`/m', substr($t, $c, $d - $c), $act);
    $words = ['answered', 'evidence_only', 'no_evidence', 'unverified', 'refused', 'failed'];       // the status table of `ask in full`, not tools
    $shares = ['ask', 'search', 'bases_index'];
    $records = array_values(array_unique(array_merge(array_diff($rec[1], $words), ['app_roles', 'records_search'], $shares))); sort($records);
    $activity = $act[1]; sort($activity);
    return ['records' => $records, 'activity' => $activity, 'shares' => $shares, 'kernel_only' => ['bases_index']];
}
/** An agent's grants as maludb-os.json declares them: ['Records MCP' => [tool => []], 'Activity MCP' => ..., 'Actions MCP' => ...]. */
function agent_grants(string $key): array
{
    $m = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/maludb-os.json'), true);
    foreach ($m['agents'] as $a) { if ($a['key'] === $key) { return array_map(fn ($tools) => array_fill_keys($tools, []), $a['tool_grants']); } }
    return [];
}
/** The signed bridge request, as the records server makes it (or a wrong one): POST /internal/bridge.php on the internal port. */
function bridge_post(array $body, array $o = []): array
{
    $json = json_encode($body, JSON_UNESCAPED_SLASHES);
    $ts = $o['ts'] ?? time();
    $key = $o['key'] ?? need('ACTIONS_RELAY_KEY');
    $sig = hash_hmac('sha256', 'kn-bridge:' . $ts . '.' . hash('sha256', $o['signed'] ?? $json), $key);
    $port = (int) need('APP_INTERNAL_PORT');
    $ch = curl_init("http://127.0.0.1:$port/internal/bridge.php");
    $h = ['Content-Type: application/json'];
    if (!isset($o['no_header'])) { $h[] = 'X-KN-Bridge: ' . $ts . '.' . $sig; }
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $b = (string) curl_exec($ch);
    return ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'json' => json_decode($b, true) ?: [], 'body' => $b];
}
/** The newest activity row of an action (any actor), or null. */
function last_event(string $action): ?array { $r = q('SELECT * FROM activity_log WHERE action = :a ORDER BY id DESC LIMIT 1', ['a' => $action]); return $r[0] ?? null; }
