<?php
/** Slice 7 proof — the K7 shares (api-keys-k7.md "Proof", K7): the share functions in-process and through the signed bridge. */
require __DIR__ . '/lib.php';

echo "10. K7\n";
$w = keys_world();
$m = $w['manuals'];
$ct = $w['contracts'];
kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [manual_script($w)], 'recall' => [], 'titles' => []]);
// an internal base nobody named to helpdesk, a guest's share and an agent's share on the private base
$io = make_base(CARA, 'SMOKE Internal Only', ['key' => 'smoke-internal-only']);
act(as_member(CARA), '/bases/visibility.php', ['base' => $io, 'visibility' => 'internal']);
act(as_member(CARA), '/sources/add.php', ['base' => $io, 'kind' => 'text', 'title' => 'Internal note', 'text' => "# Note\n\nAn internal note." . str_repeat(' Not for siblings.', 20)]);
worker_pass();
pdo()->exec("UPDATE sections SET status = 'extracted', extracted_version = 1, maludb_document_id = '901' WHERE base_id = $io");
act(as_member(CARA), '/bases/members/add-guest.php', ['base' => $ct, 'guest' => GUS]);
pdo()->exec("INSERT INTO base_members (base_id, principal_kind, principal_id, level) VALUES ($ct, 'agent', " . SEAMUS . ", 'read') ON CONFLICT DO NOTHING");
$since = last_activity_id();

$bi = share_call('kn_share_bases_index', 'helpdesk');
ok(($bi['schema'] ?? '') === 'os.knowledge-bases/1' && array_column($bi['bases'], 'key') === ['smoke-manuals'] && array_column($bi['bases'][0]['areas'], 'slug') === ['returns', 'warranty'], 'kn_share_bases_index(helpdesk) → Manuals only, with its areas');
$bj = json_encode($bi);
ok(!str_contains($bj, 'Marco') && !str_contains($bj, 'owner') && !str_contains($bj, 'curator') && !str_contains($bj, 'smoke-internal-only') && !str_contains($bj, 'smoke-contracts') && !array_key_exists('question_count', $bi['bases'][0])
    && array_keys($bi['bases'][0]) === ['key', 'name', 'description', 'template', 'language', 'status', 'last_built_at', 'source_count', 'section_count', 'areas'], 'no member, no guideline, no count of questions, no other base: the contract\'s fields and nothing else');
ok(share_call('kn_share_bases_index', 'projects')['bases'] === [], 'projects (shared nothing) → no bases');
$r = share_call('kn_share_ask', 'helpdesk', ['base' => 'smoke-manuals', 'question' => 'What does the warranty cover?']);
$qid = (int) ($r['question_id'] ?? 0);
$row = question_row($qid);
ok(($r['schema'] ?? '') === 'os.knowledge-answer/1' && $r['status'] === 'answered' && count($r['citations']) === 2 && !isset($r['error']) && !isset($r['ok']) && $r['base']['key'] === 'smoke-manuals', 'kn_share_ask(helpdesk, {base: smoke-manuals, question}) → the answer document');
ok($row['door'] === 'share' && $row['application_key'] === 'helpdesk' && $row['asked_by_member_id'] === null && $row['token_id'] === null, 'the question row: door share, application_key helpdesk, no member');
$ask = activity('question.ask', $since);
$la = json_decode(end($ask)['after'], true);
ok(end($ask)['source'] === 'application' && end($ask)['actor_member_id'] === null && $la['application'] === 'helpdesk' && $la['door'] === 'share', 'kn_ask() logged question.ask with source application and the application, no actor');
$r = share_call('kn_share_ask', 'helpdesk', ['base' => (string) $m, 'question' => 'What does the warranty cover? (by id)']);
ok($r['status'] === 'answered', 'the base by id works too');
$conv = $r['conversation_id'];
$r2 = share_call('kn_share_ask', 'helpdesk', ['base' => 'smoke-manuals', 'question' => 'And returns? warranty', 'conversation_id' => $conv]);
$r3 = share_call('kn_share_ask', 'other_app', ['base' => 'smoke-manuals', 'question' => 'warranty']);
ok($r2['turn_no'] === 2 && $r2['conversation_id'] === $conv, 'a consumer\'s conversation is its own and continues');
$notShared = [];
foreach ([[$ct, 'helpdesk'], ['smoke-contracts', 'helpdesk'], ['smoke-internal-only', 'helpdesk'], [$io, 'helpdesk'], [999999, 'helpdesk'], ['no-such-base', 'helpdesk'], ['smoke-manuals', 'projects']] as [$base, $app]) {
    $x = share_call('kn_share_ask', $app, ['base' => $base, 'question' => 'warranty?']);
    $notShared[] = json_encode($x['error'] ?? $x);
}
$want = json_encode(['code' => 'not_shared', 'message' => 'No such base is shared with this application.']);
ok(array_unique($notShared) === [$want], 'base 2, an internal base not named to helpdesk (a guest\'s and an agent\'s share counting for nothing), a made-up base and a base shared to nobody-else → not_shared, the same words each time');
$x = share_call('kn_share_ask', 'helpdesk', ['base' => $ct, 'question' => 'warranty?']);
ok(($x['schema'] ?? '') === 'os.knowledge-answer/1' && ($x['application'] ?? '') === 'knowledge' && !array_key_exists('question_id', $x), 'a refusal is a normal document: {schema, application, error: {code, message}}');
ok((int) one("SELECT count(*) FROM questions WHERE application_key IN ('projects', 'other_app')") === 0 && ($r3['error']['code'] ?? '') === 'not_shared', 'an application the base is not named to (other_app, projects) asks nothing and writes no question');
$n = questions_count();
$u = share_call('kn_share_ask', null, ['base' => 'smoke-manuals', 'question' => 'warranty?']);
$bad = share_call('kn_share_ask', 'Bad Key!', ['base' => 'smoke-manuals', 'question' => 'warranty?']);
ok($u['error']['code'] === 'consumer_unknown' && $u['error']['message'] === 'The kernel did not say which application is asking.' && questions_count() === $n && $bad['error']['code'] === 'invalid' && share_call('kn_share_bases_index', null)['error']['code'] === 'consumer_unknown',
    '$consumer = null → consumer_unknown and nothing shared; a catalog key that does not match → invalid');
$e = share_call('kn_share_ask', 'helpdesk', ['base' => 'smoke-manuals', 'question' => '']);
ok($e['error']['code'] === 'invalid' && isset($e['error']['fields']['question']), 'an empty question → invalid with the field');
$e = share_call('kn_share_ask', 'helpdesk', ['base' => 'smoke-manuals', 'question' => 'warranty?', 'focus_area' => 'nope']);
ok($e['error']['code'] === 'invalid' && isset($e['error']['fields']['focus_area']), 'an area the base does not have → invalid');
// search
kb_state(['recall' => [['chunk_id' => 91, 'document_id' => 701, 'statement_id' => 1, 'source_text' => 'The warranty covers defects in materials for two years.', 'similarity' => 0.78, 'subject_name' => 'Warranty', 'verb_name' => 'covers']], 'titles' => ['701' => 'Coverage']]);
$n = questions_count();
$s = share_call('kn_share_search', 'helpdesk', ['base' => 'smoke-manuals', 'query' => 'warranty coverage', 'limit' => 50]);
$rc = kb_log('/v1/memory/recall');
ok(($s['schema'] ?? '') === 'os.knowledge-evidence/1' && $s['status'] === 'evidence_only' && $s['evidence'][0]['section']['id'] === $w['cov'] && questions_count() === $n && end($rc)['body']['limit'] === 20, 'kn_share_search → os.knowledge-evidence/1 (limit clamped to 20), no question row');
ok(share_call('kn_share_search', 'helpdesk', ['base' => 'smoke-contracts', 'query' => 'x'])['error']['code'] === 'not_shared', 'search on an unshared base → not_shared');
// the time budget
$bud = static function (string $pre) {
    return trim((string) shell_exec('cd ' . escapeshellarg(APP_ROOT) . ' && ' . $pre . ' php -r ' . escapeshellarg('require "app/bootstrap.php"; require_once APP_ROOT . "/app/knowledge/share.php"; $in = kn_share_budget(fn () => (string) getenv("MALUDB_KB_API_TIMEOUT")); echo $in . "|" . var_export(getenv("MALUDB_KB_API_TIMEOUT"), true);') . ' 2>&1'));
};
ok($bud('env -u MALUDB_KB_API_TIMEOUT') === "50|false" && $bud('MALUDB_KB_API_TIMEOUT=3') === "3|'3'" && $bud('MALUDB_KB_API_TIMEOUT=120') === "50|'120'", 'the engine is given 50 s for a share (an environment override may only lower it), and the setting is restored');
kb_state(['answer_mode' => 'slow']);
$t = share_call('kn_share_ask', 'helpdesk', ['base' => 'smoke-manuals', 'question' => 'What does the warranty cover? (slow)']);
ok($t['status'] === 'failed' && $t['error_code'] === 'engine_timeout' && str_contains($t['message'], 'call search for the passages'), 'a slower M1 answer comes back failed, engine_timeout, "call search for the passages": ' . ($t['message'] ?? ''));
kb_state(['answer_mode' => 'ok']);
kb_stop();
$d = share_call('kn_share_ask', 'helpdesk', ['base' => 'smoke-manuals', 'question' => 'What does the warranty cover? (down)']);
kb_start();
ok($d['status'] === 'failed' && $d['error_code'] === 'engine_unavailable', 'the engine stopped → failed, engine_unavailable');
// the log
$reads = share_reads_since($since);
$outcomes = array_count_values(array_column($reads, 'outcome'));
$text = json_encode(array_map(static fn ($r) => $r, $reads));
ok(count($reads) >= 18 && ($outcomes['ok'] ?? 0) >= 6 && ($outcomes['not_shared'] ?? 0) >= 8 && ($outcomes['consumer_unknown'] ?? 0) === 2 && ($outcomes['invalid'] ?? 0) >= 3 && ($outcomes['engine_timeout'] ?? 0) === 1 && ($outcomes['engine_unavailable'] ?? 0) === 1,
    'every call logged share.read with its outcome: ' . json_encode($outcomes));
$keys = array_unique(array_merge(...array_map('array_keys', $reads)));
sort($keys);
ok($keys === ['actor', 'application', 'base_id', 'ms', 'outcome', 'source', 'tool'] && !str_contains($text, 'warranty') && !str_contains($text, 'Marco') && array_unique(array_column($reads, 'source')) === ['application'] && array_unique(array_column($reads, 'actor')) === [null],
    'share.read carries the consumer, tool, outcome and ms — source application, no actor, no question text');

echo "   through the signed bridge\n";
$internal = getenv('KN_APP') === 'apache' ? 'http://127.0.0.1:8507' : BASE;
$call = static fn (string $op, array $args, ?array $caller = ['kind' => 'application', 'application' => 'helpdesk']) => bridge(['op' => $op, 'caller' => $caller, 'door' => 'share', 'run_id' => null, 'request_id' => null, 'is_eval' => false, 'args' => $args], null, null, $internal);
[$c1, $d1] = $call('share_bases', []);
ok($c1 === 200 && array_column($d1['bases'], 'key') === ['smoke-manuals'] && array_keys($d1['bases'][0]) === array_keys($bi['bases'][0]), 'share_bases through the bridge → the same os.knowledge-bases/1');
[, $dq] = $call('share_bases', ['q' => 'nomatch']);
ok($dq['bases'] === [], 'share_bases with q filters by name');
[$c2, $d2] = $call('share_ask', ['base' => 'smoke-manuals', 'question' => 'What does the warranty cover? (bridge)']);
$bq = question_row((int) $d2['question_id']);
ok($c2 === 200 && $d2['schema'] === 'os.knowledge-answer/1' && $d2['status'] === 'answered' && array_keys($d2) === array_keys($r) && $bq['door'] === 'share' && $bq['application_key'] === 'helpdesk' && $bq['asked_by_member_id'] === null, 'share_ask through the bridge → the same answer document, door share, no member');
[$c3, $d3] = $call('share_search', ['base' => 'smoke-manuals', 'query' => 'warranty coverage']);
ok($c3 === 200 && $d3['schema'] === 'os.knowledge-evidence/1' && $d3['status'] === $s['status'], 'share_search through the bridge → the same os.knowledge-evidence/1');
[$c4, $d4] = $call('share_ask', ['base' => 'smoke-contracts', 'question' => 'x?']);
ok($c4 === 200 && $d4['error']['code'] === 'not_shared', 'not_shared through the bridge is a 200 document, not an error');
[$c5, $d5] = $call('share_ask', ['base' => 'smoke-manuals', 'question' => 'warranty?'], ['kind' => 'application']);
[$c6, $d6] = $call('share_bases', [], ['kind' => 'application', 'application' => '']);
ok($c5 === 200 && $d5['error']['code'] === 'consumer_unknown' && $c6 === 200 && $d6['error']['code'] === 'consumer_unknown', 'an application caller naming no consumer → consumer_unknown through the bridge');
[$c7, $d7] = $call('ask', ['base' => $m, 'question' => 'x?']);
[$c8, $d8] = $call('search', ['base' => $m, 'query' => 'x']);
[$c9, $d9] = $call('share_ask', ['base' => 'smoke-manuals', 'question' => 'x?'], ['kind' => 'member', 'member_id' => PRIYA]);
ok($c7 === 403 && $d7['error']['code'] === 'caller_not_allowed' && $c8 === 403 && $c9 === 403 && $d9['error']['code'] === 'caller_not_allowed', 'an application caller on ask / search is refused; a member caller on a share op is refused');
$n = count(share_reads_since($since));
ok($n >= count($reads) + 6, 'the bridge\'s share calls are logged share.read too');
if (getenv('KN_APP') === 'apache') {
    echo "   the vhosts\n";
    $body = json_encode(['op' => 'share_bases', 'caller' => ['kind' => 'application', 'application' => 'helpdesk'], 'door' => 'share', 'args' => []]);
    $ts = time();
    $sig = hash_hmac('sha256', 'kn-bridge:' . $ts . '.' . hash('sha256', $body), need('ACTIONS_RELAY_KEY'));
    $pub = req('POST', BASE . '/internal/bridge.php', ['headers' => ['Content-Type: application/json', 'X-KN-Bridge: ' . $ts . '.' . $sig], 'raw' => $body]);
    $int = req('POST', 'http://127.0.0.1:8507/internal/bridge.php', ['headers' => ['Content-Type: application/json', 'X-KN-Bridge: ' . $ts . '.' . $sig], 'raw' => $body]);
    ok($pub['code'] === 404 && $int['code'] === 200 && (json_decode($int['body'], true)['schema'] ?? '') === 'os.knowledge-bases/1', 'the share door answers on the internal vhost only: public 404, internal 200');
}
finish();
