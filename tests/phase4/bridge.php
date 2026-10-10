<?php
/**
 * Proof — the engine bridge from both ends: the PHP door's new op (extraction_preview → `preview`, signed, a curator's, a dry run that saves nothing) and the records server's Python side — `ask` through a person's token reaches
 * kn_ask() as that member (door mcp / agent), answers with the two citations of the fake engine's script, stores the question; `search`, `suggested_questions` and the graph tools reach the engine through PHP only; an engine that is down
 * is an answer, never a crash; a door that cannot be reached is `bridge_unavailable`. The MaluDB token is never in the Python process.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$a = $w['ask'];
$hb = $a['handbook'];
$g = $w['graph'];
$internal = (getenv('KN_APP') === 'apache' ? 'http://127.0.0.1:8507' : BASE);

echo "1. The door: signed, loopback, and the new op `preview`\n";
$msg = fn (array $more = []) => $more + ['op' => 'preview', 'caller' => ['kind' => 'member', 'member_id' => MARCO], 'door' => 'mcp', 'run_id' => null, 'request_id' => null, 'is_eval' => false, 'args' => ['section' => $a['carry']]];
$r = bridge_post($msg(), ['no_header' => true]);
ok($r['code'] === 401, 'no signature: 401');
$r = bridge_post($msg(), ['key' => str_repeat('a', 64)]);
ok($r['code'] === 401, 'a signature made with another key: 401');
$r = bridge_post($msg(), ['ts' => time() - 300]);
ok($r['code'] === 401, 'a stale timestamp: 401');
$r = bridge_post($msg());
$p = $r['json'];
ok($r['code'] === 200 && $p['section_id'] === $a['carry'] && str_contains($p['documents_prompt'], 'kb:' . $hb) && str_contains($p['documents_prompt'], 'Up to five days of unused leave'), 'preview for the curator: the compiled prompt for kb:N with the section\'s words in place of {{chunk}}');
ok(array_key_exists('generic_prompt', $p) && $p['note'] === 'a dry run of the prompt, not of the model (MaluDB M5)' && is_array($p['hints']), '...the engine\'s generic prompt (null when the engine has no preview route), the hints and the note');
$r = bridge_post($msg(['caller' => ['kind' => 'member', 'member_id' => PRIYA]]));
ok($r['code'] === 403 && $r['json']['error']['code'] === 'curators_only', 'a reader who does not curate the base: 403 curators_only');
$r = bridge_post($msg(['caller' => ['kind' => 'member', 'member_id' => BEA], 'args' => ['section' => $a['private_section']]]));
ok($r['code'] === 404, 'a section of a base she cannot read: 404, the same words as a missing one');
$r = bridge_post($msg(['caller' => ['kind' => 'application', 'application' => 'helpdesk']]));
ok($r['code'] === 403, 'an application caller is not offered preview');
$saved = q("SELECT id, title, body FROM guidelines WHERE base_id = :b AND kind = 'extraction' ORDER BY id LIMIT 1", ['b' => $hb])[0] ?? null;
$ng = (int) one('SELECT count(*) FROM guidelines WHERE base_id = :b', ['b' => $hb]);
$r = bridge_post($msg(['args' => ['section' => $a['carry'], 'draft' => [['kind' => 'extraction', 'title' => 'p4 new draft', 'body' => 'P4-DRAFT-SENTINEL extract every count of days.']]]]));
ok($r['code'] === 200 && str_contains($r['json']['documents_prompt'], 'P4-DRAFT-SENTINEL'), 'a draft guideline is laid over the saved ones: its words are in the prompt');
ok((int) one('SELECT count(*) FROM guidelines WHERE base_id = :b', ['b' => $hb]) === $ng, '...and nothing was saved');
if ($saved !== null) {
    $first = trim(explode("\n", $saved['body'])[0]);
    $r0 = bridge_post($msg())['json']['documents_prompt'];
    $r = bridge_post($msg(['args' => ['section' => $a['carry'], 'draft' => [['kind' => 'extraction', 'title' => $saved['title'], 'body' => 'P4-REPLACED only this.']]]]));
    ok(str_contains($r['json']['documents_prompt'], 'P4-REPLACED') && ($first === '' || !str_contains($r['json']['documents_prompt'], $first) || str_contains($r0, $first)), 'a draft with the kind and title of a saved guideline replaces it');
}
$r = bridge_post($msg(['args' => ['section' => $a['carry'], 'draft' => array_fill(0, 11, ['kind' => 'extraction', 'title' => 'x', 'body' => 'y'])]]));
ok($r['code'] === 422 && $r['json']['error']['code'] === 'invalid_argument', 'eleven drafts: 422');
$r = bridge_post($msg(['args' => ['section' => $a['carry'], 'draft' => [['kind' => 'nonsense', 'title' => 'x', 'body' => 'y']]]]));
ok($r['code'] === 422, 'a draft of a kind that is not one: 422');
$r = bridge_post($msg(['args' => ['section' => $a['carry'], 'draft' => [['kind' => 'extraction', 'title' => 'x', 'body' => str_repeat('b', 4001)]]]]));
ok($r['code'] === 422, 'a draft body over 4,000 characters: 422');

echo "2. extraction_preview through the records server\n";
$t = tool(MARCO, 'extraction_preview', ['section' => $a['carry'], 'draft' => [['kind' => 'extraction', 'title' => 'p4 via mcp', 'body' => 'P4-MCP-DRAFT count the days.']]]);
ok(!$t['error'] && str_contains($t['data']['documents_prompt'], 'P4-MCP-DRAFT'), 'Marco (curator) gets the dry run with his draft');
$t = tool(PRIYA, 'extraction_preview', ['section' => $a['carry']]);
ok($t['code'] === 'curators_only', 'Priya reads the base but does not curate it: curators_only');
$t = tool(BEA, 'extraction_preview', ['section' => $a['private_section']]);
ok($t['code'] === 'not_found', 'Bea on a private base\'s section: not_found');
$t = tool(MARCO, 'extraction_preview', ['section' => $a['carry'], 'draft' => array_fill(0, 11, ['kind' => 'extraction', 'title' => 'x', 'body' => 'y'])]);
ok($t['code'] === 'invalid_argument' && in_array('draft', $t['data']['error']['fields'], true), 'eleven drafts are refused before the door is asked: invalid_argument naming draft');

echo "3. ask through a person's token: the two-citation answer, stored by PHP\n";
kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [carry_script($a)]]);
$n0 = questions_count();
$t = tool(PRIYA, 'ask', ['base' => 'smoke-handbook', 'question' => 'How does carry-over work? (p4 mcp)']);
$d = $t['data'];
$row = question_row((int) ($d['question_id'] ?? 0));
$cit = citations_of((int) ($d['question_id'] ?? 0));
ok(!$t['error'] && $d['schema'] === 'os.knowledge-answer/1' && $d['status'] === 'answered' && count($d['citations']) === 2, 'Priya asks the Handbook by its key: answered, os.knowledge-answer/1, two citations');
ok(questions_count() === $n0 + 1 && $row['door'] === 'mcp' && (int) $row['asked_by_member_id'] === PRIYA && count($cit) === 2 && (int) $cit[0]['section_id'] === $a['carry'] && (int) $cit[1]['section_id'] === $a['sick'], 'one question stored as hers, door mcp, its two citations on the two sections');
ok($d['citations'][0]['section']['id'] === $a['carry'] && $d['citations'][0]['section']['page'] === 3 && $d['citations'][1]['section']['page'] === 4 && $d['citations'][0]['verified'] === true, 'the citations name their sections and pages, verified');
ok(str_starts_with($d['citations'][0]['url'], rtrim(need('APP_URL'), '/') . '/sections/' . $a['carry'] . '?quote='), 'each citation carries the section\'s URL at the quote');
ok(!str_contains($t['text'], 'model') && !str_contains($t['text'], 'usage') && !str_contains($t['text'], 'asked_by'), 'the document holds no model, usage or asker');
$ev = last_event('question.ask');
ok($ev['source'] === 'mcp' && (int) $ev['actor_member_id'] === PRIYA && !in_log(SENTINEL), 'logged question.ask, source mcp, her id — and the answer\'s words are in no log row');
$t2 = tool(PRIYA, 'ask', ['question' => 'And sick days?', 'conversation_id' => $d['conversation_id']]);
ok(!$t2['error'] && $t2['data']['conversation_id'] === $d['conversation_id'] && $t2['data']['turn_no'] === 2 && $t2['data']['base']['key'] === 'smoke-handbook', 'a follow-up with only the conversation id finds its base: turn 2');
$t3 = tool(BEA, 'ask', ['question' => 'And sick days?', 'conversation_id' => $d['conversation_id']]);
ok($t3['code'] === 'invalid_argument', 'Bea naming Priya\'s conversation finds no base of hers: invalid_argument');
$t = tool(PRIYA, 'ask', ['base' => $hb, 'question' => 'How does carry-over work in Leave?', 'focus_area' => 'Leave']);
ok(!$t['error'] && (kb_last_answer()['project'] ?? null) === 77 && $t['data']['focus_area']['slug'] === 'leave', 'the area named by its name: the engine is told project 77');
$n1 = questions_count();
$t = tool(BEA, 'ask', ['base' => $a['private'], 'question' => 'carry?']);
ok($t['code'] === 'not_found' && questions_count() === $n1, 'Bea asking the private base: not_found, nothing stored');
$t = tool(PRIYA, 'ask', ['base' => 'no-such-base', 'question' => 'carry?']);
ok($t['code'] === 'not_found', 'a base that does not exist: the same not_found');
$t = tool(MARCO, 'ask', ['base' => $a['empty'], 'question' => 'Anything here?']);
ok(!$t['error'] && $t['data']['status'] === 'refused' && $t['data']['error_code'] === 'base_not_built', 'a base with nothing built (Marco\'s own): refused base_not_built (a document, not an error)');
$t = tool(PRIYA, 'ask', ['base' => $hb, 'question' => str_repeat('a', 2001)]);
ok($t['code'] === 'invalid_argument' && in_array('question', $t['data']['error']['fields'], true), 'a 2,001-character question is refused before the door is asked: invalid_argument naming question');
$t = tool(PRIYA, 'ask', ['base' => $hb]);
ok($t['code'] === 'invalid_argument' && in_array('question', $t['data']['error']['fields'], true), 'no question: invalid_argument naming it');
$t = tool(PRIYA, 'ask', ['question' => 'carry?']);
ok($t['code'] === 'invalid_argument' && isset($t['data']['error']['fields']), 'no base and no conversation: invalid_argument naming the field');

echo "4. search, suggested_questions, get_question\n";
kb_state(['recall' => [['chunk_id' => 81, 'document_id' => 501, 'statement_id' => 1, 'source_text' => 'Up to five days of unused leave carry over.', 'similarity' => 0.77, 'subject_name' => 'Leave', 'verb_name' => 'covers'],
                       ['chunk_id' => 82, 'document_id' => 502, 'statement_id' => 2, 'source_text' => 'Ten paid sick days a year.', 'similarity' => 0.64, 'subject_name' => 'Sick days', 'verb_name' => 'grants']], 'titles' => ['501' => 'Carry-over', '502' => 'Sick days']]);
$n2 = questions_count();
$s = tool(PRIYA, 'search', ['base' => 'smoke-handbook', 'query' => 'carry-over', 'limit' => 5]);
ok(!$s['error'] && $s['data']['schema'] === 'os.knowledge-evidence/1' && $s['data']['status'] === 'evidence_only' && count($s['data']['evidence']) >= 1 && questions_count() === $n2, 'search: os.knowledge-evidence/1 with the passages, no question stored');
$s = tool(BEA, 'search', ['base' => $a['private'], 'query' => 'plan']);
ok($s['code'] === 'not_found', 'search on a private base she cannot read: not_found');
$s = tool(PRIYA, 'suggested_questions', ['base' => $hb]);
$qs = array_column($s['data'] ?? [], 'question');
ok(!$s['error'] && in_array('How does carry-over work?', $qs, true) && in_array('How many sick days do I get?', $qs, true), 'suggested_questions: the area\'s example questions');
g_set($g);
$s = tool(PRIYA, 'suggested_questions', ['base' => $g['pumps'], 'limit' => 8]);
$qs = array_column($s['data'] ?? [], 'question');
ok(in_array('What does the base say about Zorblat Pump?', $qs, true) && !in_array('What does the base say about Quillon Valve?', $qs, true), 'and, from the graph, "What does the base say about <subject>?" for its own subjects only');
$s = tool(BEA, 'suggested_questions', ['base' => $a['private']]);
ok($s['code'] === 'not_found', 'suggested_questions on a private base: not_found');

echo "5. The graph tools: engine through PHP, confined to the base\n";
$t = tool(PRIYA, 'get_subject', ['base' => $g['pumps'], 'subject' => 'Zorblat Pump']);
ok(!$t['error'] && $t['data']['name'] === 'Zorblat Pump' && count($t['data']['sections']) === 2, 'get_subject: the subject and the sections behind it');
$t = tool(PRIYA, 'get_subject', ['base' => $g['pumps'], 'subject' => 'Quillon Valve']);
ok($t['code'] === 'not_in_base', 'a subject of another base\'s documents: not_in_base');
$t = tool(PRIYA, 'graph_path', ['base' => $g['pumps'], 'from' => 'Zorblat Pump', 'to' => 'Quillon Valve']);
ok($t['code'] === 'no_path_in_base', 'a path through another base: no_path_in_base');
$t = tool(PRIYA, 'graph_path', ['base' => $g['pumps'], 'from' => 'Zorblat Pump', 'to' => 'Merrow Filter']);
ok(!$t['error'] && $t['data']['found'] === true && count($t['data']['steps']) === 3, 'a path inside the base');
$t = tool(PRIYA, 'graph_stats', ['base' => $g['pumps']]);
ok(!$t['error'] && !str_contains($t['text'], '98765') && !str_contains($t['text'], '54321') && $t['data']['documents'] === 4, 'graph_stats: the base\'s own counts, never the tenant-wide ones');
$t = tool(PRIYA, 'god_nodes', ['base' => $g['pumps'], 'limit' => 5]);
ok(!$t['error'] && !str_contains($t['text'], 'Quillon') && $t['data']['god_nodes'][0]['name'] === 'Zorblat Pump', 'god_nodes: confined to the base');
foreach ([['communities', ['base' => $g['pumps']]], ['surprises', ['base' => $g['pumps']]], ['graph_query', ['base' => $g['pumps'], 'text' => 'Zorblat']], ['subject_neighbors', ['base' => $g['pumps'], 'subject' => 1]],
          ['subject_statements', ['base' => $g['pumps'], 'subject' => 'Zorblat Pump']], ['sections_for_subject', ['base' => $g['pumps'], 'subject' => 'Zorblat Pump']], ['section_graph', ['section' => $g['zorblat']]],
          ['source_graph', ['source' => $g['source']]]] as [$tn, $args]) {
    $t = tool(PRIYA, $tn, $args);
    ok(!$t['error'] && !str_contains($t['text'], 'Quillon'), "$tn answers and names nothing of another base");
}
g_set($g, g_communities($g));
g_cache_clear($g['pumps']);
$t = tool(PRIYA, 'communities', ['base' => $g['pumps']]);
ok(!$t['error'] && count($t['data']['communities']) >= 1 && $t['data']['communities'][0]['label'] === 'Pump and filter' && !str_contains($t['text'], 'Valves group'), 'communities: the base\'s own (a community of another namespace is not listed)');
$cid = $t['data']['communities'][0]['community_id'];
$t = tool(PRIYA, 'community_members', ['base' => $g['pumps'], 'community' => $cid]);
ok(!$t['error'] && count($t['data']['members']) >= 2 && !str_contains($t['text'], 'Quillon'), 'community_members: confined to the base (the Valves subject and its document are left out)');
$t = tool(PRIYA, 'community_members', ['base' => $g['pumps'], 'community' => 21]);
ok($t['code'] === 'not_in_base', 'another base\'s community: not_in_base');
g_set($g);
g_cache_clear($g['pumps']);
$t = tool(PRIYA, 'duplicate_subjects', ['base' => $g['pumps']]);
ok($t['code'] === 'curators_only', 'duplicate_subjects is the curator\'s: curators_only for a reader');
$t = tool(MARCO, 'duplicate_subjects', ['base' => $g['pumps']]);
ok(!$t['error'] && count($t['data']['groups']) >= 1 && $t['data']['documents_total'] === 4, 'and Marco gets the groups and how many documents it read');
foreach (['get_subject' => ['base' => $g['valves'], 'subject' => 'Quillon Valve'], 'graph_stats' => ['base' => $g['valves']], 'god_nodes' => ['base' => $g['valves']]] as $tn => $args) {
    $t = tool(BEA, $tn, $args);
    ok($t['code'] === 'not_found', "$tn on the private Valves base as Bea: not_found");
}

echo "6. An engine that is down is an answer, not a crash\n";
kb_stop();
$t = tool(PRIYA, 'ask', ['base' => $hb, 'question' => 'How does carry-over work? (engine down)']);
ok(!$t['error'] && $t['data']['status'] === 'failed' && $t['data']['error_code'] === 'engine_unavailable' && (int) question_row((int) $t['data']['question_id'])['id'] > 0, 'ask: a failed document with error_code engine_unavailable, the question stored');
$t = tool(PRIYA, 'graph_query', ['base' => $g['pumps'], 'text' => 'Merrow ' . bin2hex(random_bytes(3))]);
ok($t['code'] === 'engine_unavailable' && $t['data']['error']['retry'] === true, 'graph_query (not cached): engine_unavailable, retry true');
$t = tool(PRIYA, 'get_base', ['base' => $hb]);
ok(!$t['error'] && $t['data']['key'] === 'smoke-handbook', 'and a read of the database still answers');
kb_start();
$t = tool(PRIYA, 'graph_query', ['base' => $g['pumps'], 'text' => 'Merrow again ' . bin2hex(random_bytes(3))]);
ok(!$t['error'], 'the engine back: answers again');

echo "7. A door that cannot be reached is bridge_unavailable\n";
$env = need('KN_DEV_ENV');
$cmd = 'cd ' . escapeshellarg(APP_ROOT . '/mcp') . ' && (set -a; . ' . escapeshellarg($env) . '; set +a; MCP_RECORDS_PORT=8509 APP_INTERNAL_PORT=9 exec ' . escapeshellarg(trim((string) shell_exec(escapeshellarg(APP_ROOT . '/tests/phase4/mcp_servers.sh') . ' venv'))) . '/bin/python records_server.py >' . escapeshellarg(need('KN_DEV_STATE') . '/mcp-orphan.log') . ' 2>&1 & echo $! > ' . escapeshellarg(need('KN_DEV_STATE') . '/mcp-orphan.pid') . ')';
shell_exec($cmd);
for ($i = 0; $i < 60; $i++) { $c = curl_init('http://127.0.0.1:8509/mcp'); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]); curl_exec($c); if (curl_getinfo($c, CURLINFO_HTTP_CODE) > 0) { break; } usleep(200000); }
$t = mcp_tool(8509, ptoken(PRIYA), 'ask', ['base' => $hb, 'question' => 'carry?']);
ok($t['code'] === 'bridge_unavailable' && $t['data']['error']['retry'] === true, 'a records server whose door is closed answers bridge_unavailable, retry true');
$t = mcp_tool(8509, ptoken(PRIYA), 'find_bases', ['q' => 'smoke-handbook']);
ok(!$t['error'], '...and still answers what it reads from the database');
$pid = (int) trim((string) @file_get_contents(need('KN_DEV_STATE') . '/mcp-orphan.pid'));
if ($pid > 0) { posix_kill($pid, 15); }
usleep(500000);
@unlink(need('KN_DEV_STATE') . '/mcp-orphan.pid');

echo "8. The MaluDB token never reaches the Python side\n";
$py = shell_exec('cat ' . escapeshellarg(APP_ROOT) . '/mcp/*.py');
ok(!str_contains((string) $py, 'MALUDB_KB_API_TOKEN') && !str_contains((string) $py, 'MALUDB_KB_API_URL') && !str_contains((string) $py, 'INSERT INTO questions') && !str_contains((string) $py, 'INSERT INTO'), 'no mcp/*.py reads the engine token or inserts anything');
$env = (string) @file_get_contents('/proc/' . (int) trim((string) @file_get_contents(need('KN_DEV_STATE') . '/mcp-records.pid')) . '/environ');
ok($env === '' || !str_contains($env, 'MALUDB_KB_API_TOKEN'), 'and the records server\'s process was not given it');
finish();
