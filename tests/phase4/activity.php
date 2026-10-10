<?php
/**
 * Proof — the activity server (docs/knowledge-mcp-tool-surface.md "Activity server"): record_history, actor_timeline, recent_activity, share_reads, activity_search, activity_query. The view decides the rows (one's own, the rows about a
 * base one may see, the admin everything); another person's trail shows only what happened on the bases one curates; share_reads is the admin's; no row holds a text; the guarded query cannot step round the view.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$a = $w['ask'];
$hb = $a['handbook'];
$k = $w['keys'];
$A = fn (int $m, string $t, array $args = []) => tool($m, $t, $args, ACT);

echo "1. record_history\n";
$h = $A(PRIYA, 'record_history', ['entity_type' => 'question', 'entity_id' => $w['q_carry']]);
ok(!$h['error'] && count($h['data']) >= 1 && $h['data'][0]['action'] === 'question.ask' && $h['data'][0]['actor_name'] === 'SMOKE Priya' && $h['data'][0]['source'] === 'web', 'a question\'s history: question.ask by Priya, source web');
ok(count($h['data']) < 2 || $h['data'][0]['occurred_at'] <= $h['data'][1]['occurred_at'], 'oldest first');
ok(array_keys($h['data'][0]) === ['activity_id', 'occurred_at', 'actor_name', 'actor_is_agent', 'source', 'action', 'before', 'after', 'request_id', 'agent_run_id'], 'the row\'s fields');
$h = $A(BEA, 'record_history', ['entity_type' => 'question', 'entity_id' => $w['q_private']]);
ok(!$h['error'] && $h['data'] === [], 'the history of a question on a private base is empty for Bea');
$h = $A(MARCO, 'record_history', ['entity_type' => 'question', 'entity_id' => $w['q_private']]);
ok(count($h['data']) >= 1, '...and Marco, its base\'s owner, reads it');
$h = $A(OWNER, 'record_history', ['entity_type' => 'base', 'entity_id' => $a['private']]);
ok(!$h['error'] && count($h['data']) >= 1 && count(array_filter($h['data'], fn ($r) => str_starts_with($r['action'], 'base.'))) >= 1, 'the admin reads a private base\'s history (base.create …)');
$h = $A(BEA, 'record_history', ['entity_type' => 'base', 'entity_id' => $a['private']]);
ok($h['data'] === [], 'Bea reads none of it');
$h = $A(PRIYA, 'record_history', ['entity_type' => 'source', 'entity_id' => $a['source']]);
ok(count($h['data']) >= 1 && $h['data'][0]['action'] === 'source.add', 'a source: source.add');
ok($A(PRIYA, 'record_history', ['entity_type' => 'nonsense', 'entity_id' => 1])['error'] && $A(PRIYA, 'record_history', ['entity_type' => 'base'])['error'], 'an entity type that is not one, a missing id: refused');
$pin = $A(PRIYA, 'record_history', ['entity_type' => 'dispatch', 'entity_id' => $w['dispatch']]);
ok(!$pin['error'] && is_array($pin['data']), 'the vocabulary of the surface (dispatch, key, proposal …) is accepted');
foreach (['record_history' => ['entity_type' => 'base', 'entity_id' => $hb], 'recent_activity' => ['since' => '2020-01-01', 'exclude_mine' => false], 'activity_search' => ['q' => 'question']] as $tn => $args) {
    $t = $A(OWNER, $tn, $args);
    ok(!$t['error'] && !str_contains($t['text'], SENTINEL) && !str_contains($t['text'], 'Up to five days'), "$tn: no answer, quote or passage in any payload");
}

echo "2. actor_timeline\n";
$own = $A(PRIYA, 'actor_timeline', ['member' => PRIYA]);
ok(!$own['error'] && count($own['data']) >= 1 && count(array_filter($own['data'], fn ($r) => $r['actor_member_id'] !== PRIYA)) === 0, 'Priya reads her own trail');
$byName = $A(PRIYA, 'actor_timeline', ['member' => 'SMOKE Priya']);
ok(count($byName['data']) === count($own['data']), '...by her name too');
$other = $A(PRIYA, 'actor_timeline', ['member' => MARCO]);
ok(!$other['error'] && $other['data'] === [], 'Marco\'s trail, read by Priya: nothing (she curates none of his bases)');
$cur = $A(MARCO, 'actor_timeline', ['member' => PRIYA]);
ok(count($cur['data']) >= 1 && count(array_filter($cur['data'], fn ($r) => $r['actor_member_id'] !== PRIYA)) === 0 && count(array_filter($cur['data'], fn ($r) => $r['base_id'] === null)) === 0, 'Priya\'s trail, read by Marco: her work on the bases he curates');
$adm = $A(OWNER, 'actor_timeline', ['member' => PRIYA, 'since' => '2020-01-01']);
ok(count($adm['data']) >= count($cur['data']), 'the admin reads all of it');
ok($A(PRIYA, 'actor_timeline', [])['code'] === 'invalid_argument' && $A(PRIYA, 'actor_timeline', ['member' => PRIYA, 'key' => 1])['code'] === 'invalid_argument', 'exactly one of member or key');
$kk = $A(MARCO, 'actor_timeline', ['key' => $w['key']]);
ok(!$kk['error'] && is_array($kk['data']), 'a key\'s trail');
ok($A(PRIYA, 'actor_timeline', ['member' => 'Nobody At All'])['code'] === 'not_found', 'a member who does not exist: not_found');
ok(count($A(MARCO, 'actor_timeline', ['member' => PRIYA, 'base' => $hb])['data']) >= 1 && $A(MARCO, 'actor_timeline', ['member' => PRIYA, 'since' => '2999-01-01'])['data'] === [], 'by base and by time');

echo "3. recent_activity\n";
$r = $A(MARCO, 'recent_activity', ['since' => '2020-01-01', 'limit' => 200]);
ok(!$r['error'] && count($r['data']) >= 5 && count(array_filter($r['data'], fn ($x) => $x['actor_member_id'] === MARCO)) === 0, 'recent activity leaves out my own doing');
$r2 = $A(MARCO, 'recent_activity', ['since' => '2020-01-01', 'exclude_mine' => false, 'limit' => 200]);
ok(count($r2['data']) > count($r['data']) && count(array_filter($r2['data'], fn ($x) => $x['action'] === 'screen.view')) === 0, '...unless asked; screen views are never in it');
ok($r['data'][0]['occurred_at'] >= $r['data'][1]['occurred_at'], 'newest first');
$rb = $A(PRIYA, 'recent_activity', ['since' => '2020-01-01', 'base' => $hb, 'exclude_mine' => false, 'limit' => 200]);
ok(count($rb['data']) >= 1 && count(array_filter($rb['data'], fn ($x) => $x['base_id'] !== $hb)) === 0, 'one base');
$pv = $A(BEA, 'recent_activity', ['since' => '2020-01-01', 'exclude_mine' => false, 'limit' => 200]);
ok(count(array_filter($pv['data'], fn ($x) => in_array($x['base_id'], [$a['private'], $g = $w['graph']['valves'], $k['contracts']], true))) === 0, 'Bea\'s feed holds nothing about the private bases');
ok($A(PRIYA, 'recent_activity', [])['error'] && $A(PRIYA, 'recent_activity', ['since' => 'when'])['code'] === 'invalid_argument', 'since is required and must be a time');

echo "4. share_reads\n";
ok($A(PRIYA, 'share_reads')['code'] === 'curators_only' && $A(MARCO, 'share_reads')['code'] === 'curators_only', 'the admin\'s: a Member and a Curator are refused');
$sr = $A(OWNER, 'share_reads', ['since' => '2020-01-01']);
$hdAsk = find_row($sr['data'], 'tool', 'ask');
ok(!$sr['error'] && $hdAsk !== null && $hdAsk['application'] === 'helpdesk' && $hdAsk['calls'] >= 1 && array_keys($hdAsk) === ['application', 'tool', 'calls', 'refused', 'last_at'], 'grouped by application and tool: calls, refused, last');
$hdSearch = find_row($sr['data'], 'tool', 'search');
ok($hdSearch !== null && $hdSearch['refused'] >= 1, 'a refused call is counted');
$one = $A(OWNER, 'share_reads', ['since' => '2020-01-01', 'application' => 'helpdesk']);
ok(count($one['data']) >= 1 && count(array_filter($one['data'], fn ($r) => $r['application'] !== 'helpdesk')) === 0, 'by application');
$rows = $A(OWNER, 'share_reads', ['since' => '2020-01-01', 'group' => false]);
ok(count($rows['data']) >= 2 && isset($rows['data'][0]['outcome']), 'ungrouped: every row with its outcome');

echo "5. activity_search\n";
$s = $A(PRIYA, 'activity_search', ['q' => 'question.ask']);
ok(!$s['error'] && count($s['data']) >= 1 && count(array_filter($s['data'], fn ($r) => $r['action'] !== 'question.ask')) === 0, 'by action');
$s = $A(MARCO, 'activity_search', ['q' => 'SMOKE Handbook']);
ok(count($s['data']) >= 1, 'by a name the payloads carry');
$s = $A(PRIYA, 'activity_search', ['q' => 'p4 manuals key']);
ok($s['data'] === [], 'by a label: only rows the caller may see');
ok($A(PRIYA, 'activity_search', ['q' => 'x'])['error'], 'q is at least two characters');
$s = $A(BEA, 'activity_search', ['q' => 'base.create', 'limit' => 200]);
ok(count(array_filter($s['data'], fn ($r) => in_array($r['base_id'], [$a['private']], true))) === 0, 'Bea finds no row of a private base');

echo "6. activity_query: the guarded read of the view\n";
$q1 = $A(BEA, 'activity_query', ['sql' => 'select action, count(*) as n from mcp_activity_log group by 1 order by 2 desc']);
ok(!$q1['error'] && count($q1['data']) >= 1, 'a plain read of mcp_activity_log');
$q2 = $A(BEA, 'activity_query', ['sql' => 'select distinct base_id from mcp_activity_log where base_id is not null']);
ok(!in_array($a['private'], ids($q2['data'], 'base_id'), true), '...shows Bea no private base');
$q3 = $A(OWNER, 'activity_query', ['sql' => 'select l.action, b.name from mcp_activity_log l join mcp_bases b on b.base_id = l.base_id limit 5']);
ok(!$q3['error'] && count($q3['data']) >= 1, 'joined to the name views');
foreach (['select * from activity_log', 'select * from questions', "select set_config('app.member_id', '1', true)", 'select 1; select 2', 'delete from activity_log', 'select * from mcp_questions'] as $sql) {
    $t = $A(BEA, 'activity_query', ['sql' => $sql]);
    ok($t['error'], "refused: $sql");
}
$mem = sql_as(BEA, 'select count(*) from mcp_activity_log');
$cnt = $A(BEA, 'activity_query', ['sql' => 'select count(*) as n from mcp_activity_log']);
ok($cnt['data'][0]['n'] === (int) array_values($mem[0])[0], 'and the count is the view\'s own for Bea');
echo "7. An agent on the Activity MCP\n";
facts(rid(50), SEAMUS, ['Activity MCP' => ['record_history' => [], 'recent_activity' => []]]);
$t = rtoken(SEAMUS, rid(50));
ok(mcp_tools(ACT, $t) === ['recent_activity', 'record_history'] || mcp_tools(ACT, $t) === ['record_history', 'recent_activity'], 'Seamus is offered the two Activity tools the kernel granted');
$rr = mcp_tool(ACT, $t, 'recent_activity', ['since' => '2020-01-01']);
ok(!$rr['error'], 'a granted one answers (as the agent)');
$no = mcp_tool(ACT, $t, 'share_reads', []);
ok($no['error'] && str_contains($no['text'], 'not among the tools'), 'share_reads was not granted: refused in words');
finish();
