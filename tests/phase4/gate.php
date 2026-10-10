<?php
/**
 * Proof — the gate: the three token shapes and what each is offered (docs/knowledge-mcp-tool-surface.md "Who may call"). A person's own mcp_ token sees every tool; a KEY (scope api) is refused; the tenant's signed
 * action/run tokens name a member the mirror admits; an agent's run token is offered exactly the tools the (fake) kernel's run-facts names for THIS endpoint, fail closed when the kernel is down or does not vouch; the
 * kernel's own token reaches app_roles and the three shares only; an evaluation run reads and `ask` persists nothing for it.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$S = surface();
$hb = $w['ask']['handbook'];

echo "1. The surface: every tool the document names, offered to a person, annotated read-only\n";
$all = mcp_tools(REC, ptoken(PRIYA));
$acts = mcp_tools(ACT, ptoken(PRIYA));
$perPerson = array_values(array_diff($S['records'], $S['kernel_only']));
sort($perPerson);
ok(count($S['records']) === 61 && count($S['activity']) === 6, 'the surface names 61 records tools (53 + app_roles, records_search, bases_index + 5 support) and 6 activity tools');
ok($all !== null && $all === $perPerson, 'a person is offered the records tools of the surface (' . count($perPerson) . ': every one but the one that answers only as an application principal)');
ok($acts === $S['activity'] && count($acts) === 6, 'and the six activity tools');
$defs = mcp_tool_defs(REC, ptoken(PRIYA));
$ro = array_filter($defs, fn ($t) => ($t['annotations']['readOnlyHint'] ?? false) === true && ($t['annotations']['openWorldHint'] ?? true) === false);
ok(count($ro) === count($defs), 'every records tool carries readOnlyHint true and openWorldHint false');
$noDesc = array_filter($defs, fn ($t) => strlen((string) ($t['description'] ?? '')) < 40);
ok($noDesc === [], 'every tool says what it is for (a description an agent can choose by)');
$byName = array_column($defs, null, 'name');
ok(isset($byName['find_bases']['inputSchema']['properties']['params']) || isset($byName['find_bases']['inputSchema']['$defs']), 'a tool takes one `params` object');
$undescribed = [];
foreach ($defs as $t) {
    foreach (($t['inputSchema']['$defs'] ?? []) as $name => $d) {
        foreach (($d['properties'] ?? []) as $pn => $pv) { if (empty($pv['description'])) { $undescribed[] = "$name.$pn"; } }
    }
}
ok($undescribed === [], 'every field of every parameter model has a description' . ($undescribed ? ': ' . implode(', ', array_slice($undescribed, 0, 5)) : ''));
$noExtra = array_filter($defs, fn ($t) => ($t['name'] !== 'get_settings' && $t['name'] !== 'app_roles') && !array_filter($t['inputSchema']['$defs'] ?? [], fn ($d) => ($d['additionalProperties'] ?? true) === false));
ok($noExtra === [], 'every parameter model forbids extra fields (extra="forbid")');

$bad = tool(PRIYA, 'find_bases', ['limit' => 0, 'bogus' => 'SECRET-ECHO-TEST']);
ok($bad['code'] === 'invalid_argument' && $bad['data']['error']['retry'] === false && in_array('limit', $bad['data']['error']['fields'], true) && in_array('bogus', $bad['data']['error']['fields'], true) && !str_contains($bad['text'], 'SECRET-ECHO-TEST'),
    'a parameter that fails validation is an ANSWER: invalid_argument naming the fields (limit, bogus), and what was sent is not echoed');

echo "2. Who is refused\n";
ok(mcp_tools(REC, '') === null, 'no bearer: 401');
ok(mcp_tools(REC, 'mcp_' . str_repeat('0', 48)) === null, 'a token nobody minted: 401');
ok(mcp_tools(REC, ktoken_api(PRIYA, $hb)) === null, 'an API key (scope api) is not a token for the MCP servers: 401');
ok(mcp_tools(REC, atoken(PRIYA, -5)) === null, 'an expired action token: 401');
$p = PRIYA . '.' . (time() + 300);
ok(mcp_tools(REC, $p . '.' . hash_hmac('sha256', $p, 'not-the-key')) === null, 'an action token signed with another key: 401');
ok(mcp_tools(REC, atoken(999)) === null, 'an action token for a member the mirror does not know: 401 (an unknown id is refused, never created)');
ok((int) one('SELECT count(*) FROM members WHERE id = 999') === 0, '...and was not created');
ok(mcp_tools(REC, kernel_token('someoneelse')) === null, 'a kernel token bound to another application: 401');
ok(mcp_tools(ACT, ptoken(PRIYA) . 'x') === null, 'a token with a letter changed: 401');

echo "3. A person: their own token, or the signed action token of the command bar\n";
ok(mcp_tools(REC, atoken(PRIYA)) === $perPerson, 'Priya\'s action token is offered the same tools');
ok(mcp_tools(REC, ptoken(ANN)) === $perPerson, 'the guest Ann\'s token is offered them too (rows decide what she sees, not the list)');
$me = tdata(PRIYA, 'get_settings');
ok(is_array($me) && isset($me['max_question_chars']) && ($me['model_alias'] ?? null) === null && ($me['embedding_model'] ?? null) === null, 'get_settings as a member: the model fields are blank');
q("UPDATE kn_settings SET model_alias = 'p4-answer', embedding_model = 'p4-embed' WHERE id = 1");
$adm = tdata(OWNER, 'get_settings');
$mem = tdata(PRIYA, 'get_settings');
ok(is_array($adm) && $adm['model_alias'] === 'p4-answer' && $adm['embedding_model'] === 'p4-embed' && array_key_exists('model_alias', $mem) && $mem['model_alias'] === null, 'and the admin\'s answer carries them (the member\'s stays blank)');
$roles = tool(PRIYA, 'app_roles');
ok($roles['data']['schema'] === 'os.app-roles/1' && $roles['data']['application'] === 'knowledge' && count($roles['data']['roles']) === 4 && count($roles['data']['rights']) === 20, 'app_roles answers os.app-roles/1: 4 roles, 20 rights');
ok(array_column($roles['data']['roles'], 'key') === ['reader', 'user', 'curator', 'admin'] && count(array_filter($roles['data']['roles'], fn ($r) => $r['is_admin'])) === 1, 'Reader, Member (user), Curator, Knowledge admin — one is_admin');
$mf = json_decode((string) file_get_contents(APP_ROOT . '/maludb-os.json'), true);
$mfRoles = array_column($mf['roles'], null, 'key');
$same = true;
foreach ($roles['data']['roles'] as $r) { $m = $mfRoles[$r['key']] ?? null; if ($m === null || $m['capability'] !== $r['capability'] || array_diff($r['rights'], $m['rights']) !== []) { $same = false; } }
ok($same, 'app_roles agrees with maludb-os.json roles[] (capability, and every right it names the manifest names)');

echo "4. An agent: only what the kernel's run-facts names for THIS endpoint\n";
facts(rid(1), SEAMUS, ['Records MCP' => ['find_bases' => [], 'search' => [], 'get_section' => []], 'Activity MCP' => ['recent_activity' => []]]);
$t = rtoken(SEAMUS, rid(1));
ok(mcp_tools(REC, $t) === ['find_bases', 'get_section', 'search'], 'Seamus (run 1) is offered exactly the three Records tools the kernel granted');
ok(mcp_tools(ACT, $t) === ['recent_activity'], '...and on the Activity server exactly its one');
$ok = mcp_tool(REC, $t, 'find_bases', ['q' => 'smoke-handbook']);
ok(!$ok['error'] && count($ok['data']) >= 1, 'a granted tool answers');
$no = mcp_tool(REC, $t, 'get_base', ['base' => $hb]);
ok($no['error'] && str_contains($no['text'], 'not among the tools this agent was granted'), 'a tool he was not granted is refused in words');
$no2 = mcp_tool(REC, $t, 'app_roles');
ok($no2['error'], 'app_roles was not granted either: refused');
$ko = mcp_tool(REC, $t, 'bases_index', [], true);
ok($ko['error'], 'bases_index (the kernel\'s share) is not callable by an agent');
facts(rid(2), SEAMUS, ['Records MCP' => ['find_bases' => []]], ['valid' => false]);
ok(mcp_tools(REC, rtoken(SEAMUS, rid(2))) === [], 'the kernel says valid:false for run 2: nothing is offered (fail closed)');
ok(mcp_tool(REC, rtoken(SEAMUS, rid(2)), 'find_bases', ['q' => 'x'])['error'], '...and nothing can be called');
ok(mcp_tools(REC, rtoken(SEAMUS, rid(9))) === [], 'a run the kernel has never heard of (9): nothing is offered');
facts(rid(3), SEAMUS, ['Another MCP' => ['find_bases' => []]]);
ok(mcp_tools(REC, rtoken(SEAMUS, rid(3))) === [], 'grants on another endpoint are not grants here');

echo "5. First contact: an agent the mirror has but has not admitted\n";
ok((string) one('SELECT capability FROM members WHERE id = :m', ['m' => WATCHER]) === '', 'the Watcher is not admitted yet');
ok(mcp_tools(REC, rtoken(WATCHER, rid(10))) === null, 'a run token the kernel does not vouch for: 401');
facts(rid(11), WATCHER, []);
ok(mcp_tools(REC, rtoken(WATCHER, rid(11))) === null, 'vouched but with no endpoints of this application: still 401');
ok((string) one('SELECT capability FROM members WHERE id = :m', ['m' => WATCHER]) === '', '...and not admitted');
facts(rid(12), WATCHER, ['Records MCP' => ['find_bases' => []]]);
ok(mcp_tools(REC, rtoken(WATCHER, rid(12))) === ['find_bases'], 'vouched with endpoints here: admitted on first contact and offered its one tool');
ok((string) one('SELECT capability FROM members WHERE id = :m', ['m' => WATCHER]) === 'write', 'the mirror now admits the Watcher (mcp_admit_agent)');
q('UPDATE members SET capability = NULL WHERE id = :m', ['m' => WATCHER]);

echo "6. The kernel down: fail closed\n";
$pid = (int) trim((string) @file_get_contents(need('KN_DEV_STATE') . '/kernel.pid'));
facts(rid(20), SEAMUS, ['Records MCP' => ['find_bases' => []]]);
shell_exec('kill ' . $pid . ' 2>/dev/null; sleep 0.5');
ok(mcp_tools(REC, rtoken(SEAMUS, rid(20))) === [], 'with the kernel unreachable an agent run is offered nothing');
ok(mcp_tool(REC, rtoken(SEAMUS, rid(20)), 'find_bases', ['q' => 'x'])['error'], '...and can call nothing');
ok(mcp_tools(REC, rtoken(WATCHER, rid(21))) === null, 'a first contact cannot be vouched for: 401');
ok(mcp_tools(REC, ptoken(PRIYA)) === $perPerson, 'a PERSON is not the kernel\'s to judge: their token still works');
shell_exec('cd ' . escapeshellarg(APP_ROOT) . ' && (set -a; . ' . escapeshellarg(need('KN_DEV_ENV')) . '; set +a; FAKE_KERNEL_STATE=' . escapeshellarg(need('FAKE_KERNEL_STATE')) . ' php -S 127.0.0.1:8502 tests/fake_kernel.php >> ' . escapeshellarg(need('KN_DEV_STATE') . '/kernel.log') . ' 2>&1 & echo $! > ' . escapeshellarg(need('KN_DEV_STATE') . '/kernel.pid') . ')');
for ($i = 0; $i < 30; $i++) { $c = curl_init('http://127.0.0.1:8502/x'); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]); curl_exec($c); if (curl_getinfo($c, CURLINFO_HTTP_CODE) > 0) { break; } usleep(200000); }
ok(mcp_tools(REC, rtoken(SEAMUS, rid(20))) === ['find_bases'], 'the kernel back: the same run token is offered its tool again (the failure was not cached)');

echo "7. An evaluation run reads, and `ask` persists nothing for it\n";
$ask = carry_script($w['ask']);
kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [$ask]]);
facts(rid(30), SEAMUS, ['Records MCP' => ['ask' => [], 'find_bases' => []]], ['trigger' => 'eval']);
facts(rid(31), SEAMUS, ['Records MCP' => ['ask' => []]]);
$nq = (int) one('SELECT count(*) FROM questions');
$nc = (int) one('SELECT count(*) FROM citations');
$nu = (int) one("SELECT COALESCE(sum(calls), 0) FROM ai_usage WHERE kind = 'answer'");
$e = mcp_tool(REC, rtoken(SEAMUS, rid(30)), 'ask', ['base' => $hb, 'question' => 'How does carry-over work (eval)?']);
ok(!$e['error'] && $e['data']['status'] === 'answered' && $e['data']['question_id'] === null && count($e['data']['citations']) === 2, 'ask under an eval run answers, with two citations and question_id null');
ok((int) one('SELECT count(*) FROM questions') === $nq && (int) one('SELECT count(*) FROM citations') === $nc && (int) one("SELECT COALESCE(sum(calls), 0) FROM ai_usage WHERE kind = 'answer'") === $nu, '...and writes no question, no citation and no usage row');
$ev = last_event('question.ask');
ok($ev !== null && (int) $ev['actor_member_id'] === SEAMUS && str_contains((string) $ev['after'], '"eval"'), '...only the question.ask line, marked eval');
$n = mcp_tool(REC, rtoken(SEAMUS, rid(31)), 'ask', ['base' => $hb, 'question' => 'How does carry-over work (normal)?']);
ok(!$n['error'] && (int) one('SELECT count(*) FROM questions') === $nq + 1 && $n['data']['question_id'] > 0, 'the same ask in a normal run stores one question');
$row = q('SELECT door, asked_by_member_id FROM questions ORDER BY id DESC LIMIT 1')[0];
ok($row['door'] === 'agent' && (int) $row['asked_by_member_id'] === SEAMUS, '...door agent, asked by Seamus');
$ev = last_event('question.ask');
ok($ev['source'] === 'agent' && (int) $ev['agent_run_id'] === rid(31), 'logged as the agent\'s own call: source agent, the run id');

echo "8. The kernel's own token: app_roles and the three shares, nothing else\n";
$kt = kernel_token();
ok(mcp_tools(REC, $kt) === ['app_roles', 'ask', 'bases_index', 'search'], 'it is offered app_roles and the three shares');
ok(mcp_tools(ACT, $kt) === [], 'and nothing on the activity server');
$ar = mcp_tool(REC, $kt, 'app_roles', [], true);
ok(!$ar['error'] && $ar['data']['schema'] === 'os.app-roles/1', 'app_roles answers it');
foreach (['find_bases' => ['q' => 'x'], 'get_section' => ['section' => 1], 'records_search' => ['sql' => 'select 1'], 'my_tokens' => [], 'get_question' => ['question' => 1]] as $tn => $a) {
    $r = mcp_tool(REC, kernel_token(), $tn, $a);
    ok($r['error'] && str_contains($r['text'], 'reaches'), "the kernel's token cannot call $tn");
}
$r = mcp_tool(REC, ptoken(OWNER), 'bases_index', [], true);
ok($r['error'], 'bases_index: even the admin\'s own token cannot call it (an application principal\'s tool)');
ok(in_array('ask', $all, true) && in_array('search', $all, true), 'ask and search are both: a person asks with them, the kernel shares them');
finish();
