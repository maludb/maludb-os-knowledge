<?php
/**
 * Proof — coverage: every tool of the surface (61 records, 6 activity) was called through the real protocol by some proof in this run; every mcp_* view of db/014 (and mcp_app_roles) and every db/013 read function is read by
 * a named tool; every design §7 question the surface names has a tool; the servers' sources hold no write.
 */
require __DIR__ . '/lib.php';
$S = surface();
$root = dirname(__DIR__, 2);
$called = array_values(array_unique(array_filter(array_map('trim', file(need('KN_DEV_STATE') . '/p4-called.txt') ?: []))));

echo "1. Every tool was called\n";
$never = array_values(array_diff(array_merge($S['records'], $S['activity']), $called));
ok($never === [], 'all ' . (count($S['records']) + count($S['activity'])) . ' tools were called through the protocol in this run' . ($never ? ' — never called: ' . implode(', ', $never) : ''));
ok(count($S['records']) === 61 && count($S['activity']) === 6, '61 records tools and 6 activity tools: the surface\'s count');
$live = mcp_tools(REC, ptoken(OWNER));
$liveAct = mcp_tools(ACT, ptoken(OWNER));
ok(count($live) === 60 && count($liveAct) === 6 && array_values(array_diff($S['records'], $live)) === ['bases_index'], 'the servers offer exactly them (60 to a person: bases_index is the kernel\'s alone)');

echo "2. Every view and every read function is read by a named tool\n";
$py = '';
foreach (glob($root . '/mcp/*.py') as $f) { $py .= file_get_contents($f) . "\n"; }
$sql = (string) file_get_contents($root . '/db/014_mcp_views.sql');
preg_match_all('/CREATE OR REPLACE VIEW (mcp_[a-z_]+)/', $sql, $m);
$views = array_merge($m[1], ['mcp_app_roles']);
$unread = array_values(array_filter($views, fn ($v) => !str_contains($py, $v)));
ok(count($views) === 32 && $unread === [], '32 views (31 of db/014, mcp_app_roles), each named in a tool\'s query' . ($unread ? ' — unread: ' . implode(', ', $unread) : ''));
$fn = ['kn_base_overview', 'kn_source_outline', 'kn_section_markdown', 'kn_questions', 'kn_question_gaps', 'kn_build_progress', 'kn_build_estimate', 'kn_usage', 'kn_unassigned_sources', 'kn_empty_areas', 'kn_unused_terms'];
$unreadFn = array_values(array_filter($fn, fn ($f) => !str_contains($py, $f)));
ok($unreadFn === [], 'the eleven db/013 read functions each have a tool' . ($unreadFn ? ' — unread: ' . implode(', ', $unreadFn) : ''));
$act = (string) file_get_contents($root . '/mcp/activity_server.py');
ok(str_contains($act, 'mcp_activity_log') && !str_contains((string) file_get_contents($root . '/mcp/records_server.py') . file_get_contents($root . '/mcp/kn_admin.py'), 'FROM mcp_activity_log'), 'mcp_activity_log is the activity server\'s alone');

echo "3. The design's questions have their tools\n";
$design = (string) file_get_contents($root . '/docs/knowledge-design.md');
$doc = (string) file_get_contents($root . '/docs/knowledge-mcp-tool-surface.md');
$ids = ['B1', 'B2', 'B3', 'S1', 'S2', 'S3', 'S4', 'S5', 'G1', 'G2', 'G3', 'G4', 'G5', 'L1', 'L2', 'L3', 'K1', 'K2', 'K3', 'K4', 'K5', 'K6', 'K7', 'Q1', 'Q2', 'Q3', 'Q4', 'Q5', 'Q6', 'Q7', 'A1', 'A2', 'A3', 'A4', 'ACT1', 'ACT2', 'ACT3', 'ACT4', 'ACT5'];
$cov = substr($doc, strpos($doc, '**Design §7 → tools**'), 4000);
$gaps = array_values(array_filter($ids, fn ($i) => !preg_match('/\b' . $i . '\b/', $cov)));
ok(count($ids) === 39 && $gaps === [], 'the 39 question groups (B1–ACT5) of design §7 are each in the surface\'s coverage table' . ($gaps ? ' — missing: ' . implode(', ', $gaps) : ''));
preg_match_all('/`([a-z_]+)`/', substr($cov, 0, 3200), $tn);
$missingTools = array_values(array_filter(array_unique($tn[1]), fn ($t) => preg_match('/^(find|get|my|search|source|section|graph|god|subject|communit|ask|base|build|guideline|vocab|unused|unassigned|empty|duplicate|extraction|suggested|activity|share|record|actor|recent|question|pinned|pins|archivist|agent|surprises|app_roles|records_search|bases_index)/', $t) && !in_array($t, array_merge($S['records'], $S['activity'], ['my_rating']), true)));
ok($missingTools === [], 'every tool the coverage table names exists' . ($missingTools ? ' — ' . implode(', ', $missingTools) : ''));
$tn1 = array_values(array_diff(array_merge($S['records'], $S['activity']), ['app_roles', 'records_search', 'bases_index']));
$orphan = array_values(array_filter($tn1, fn ($t) => !str_contains($doc, '`' . $t . '`')));
ok($orphan === [], 'every tool is named in the surface document');

echo "4. The servers never write\n";
$writes = [];
foreach (glob($root . '/mcp/{records_server,activity_server,kn_*,server_common}.py', GLOB_BRACE) as $f) {
    foreach (file($f) as $n => $line) { if (preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|TRUNCATE\s|DROP\s+(TABLE|VIEW|FUNCTION)|ALTER\s+(TABLE|ROLE))/', $line) && !preg_match('/^\s*#/', $line)) { $writes[] = basename($f) . ':' . ($n + 1); } }
}
ok($writes === [], 'no INSERT, UPDATE, DELETE or DDL in a server\'s code' . ($writes ? ' — ' . implode(', ', $writes) : ''));
$ro = true;
foreach (['knowledge_records_ro', 'knowledge_activity_ro'] as $role) {
    $n = (int) one("SELECT count(*) FROM information_schema.role_table_grants WHERE grantee = :r AND privilege_type IN ('INSERT', 'UPDATE', 'DELETE', 'TRUNCATE') AND table_schema = 'public'", ['r' => $role]);
    $ro = $ro && $n === 0;
}
ok($ro, 'and the two read roles hold no write privilege on any table');
$t1 = tool(PRIYA, 'records_search', ['sql' => 'select count(*) as n from mcp_questions']);
$qa = questions_count();
ok(!$t1['error'] && questions_count() === $qa, 'a read leaves the question count where it was');
finish();
