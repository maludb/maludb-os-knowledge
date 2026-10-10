<?php
/**
 * Proof — the walls are the database's: what a member reads through the tools is exactly what the mcp_* views show them (the same ids, asked in SQL as that member) — a private base, its sources, sections, questions, keys and
 * graph are its members' alone; a guest reaches only the bases named to them; a curator's tool says curators_only to a reader; a forgotten section's words are never served; a key's value and hash are in no answer; the
 * guarded search cannot step round any of it.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$a = $w['ask'];
$hb = $a['handbook'];
$k = $w['keys'];
$g = $w['graph'];

echo "1. Which bases: the tool's ids are the view's, for each kind of person\n";
foreach ([['Priya (Member)', PRIYA], ['Bea (Member, no share)', BEA], ['Reid (Reader)', REID], ['Ann (a guest)', ANN], ['Marco (curator, owner)', MARCO], ['the admin', OWNER]] as [$who, $m]) {
    $t = tool($m, 'find_bases', ['limit' => 100]);
    $got = ids($t['data'], 'base_id'); sort($got);
    $want = array_values(array_map('intval', array_column(sql_as($m, 'SELECT base_id FROM mcp_bases WHERE archived_at IS NULL ORDER BY base_id'), 'base_id')));
    ok(!$t['error'] && $got === $want, "$who sees " . count($want) . ' base(s): exactly the view\'s');
}
$priya = ids(tdata(PRIYA, 'find_bases', ['limit' => 100]), 'base_id');
ok(!in_array($a['private'], $priya, true) && !in_array($g['valves'], $priya, true) && !in_array($k['contracts'], $priya, true) && in_array($hb, $priya, true), 'Priya reads the internal bases and none of the private ones');
$ann = ids(tdata(ANN, 'find_bases', ['limit' => 100]), 'base_id');
ok($ann !== [] && !in_array($k['manuals'], $ann, true) && in_array($hb, $ann, true), 'the guest Ann reads the Handbook, named to her, and not the internal Manuals');
ok(count(ids(tdata(OWNER, 'find_bases', ['limit' => 100]), 'base_id')) >= count(ids(tdata(MARCO, 'find_bases', ['limit' => 100]), 'base_id')), 'the admin reads every base');
$mine = ids(tdata(MARCO, 'find_bases', ['mine' => true, 'limit' => 100]), 'base_id');
ok(in_array($a['private'], $mine, true) && in_array($hb, $mine, true), 'mine: true lists the bases Marco owns or curates');

echo "2. A base that is not yours is not found, in the same words as one that does not exist\n";
$words = [];
foreach ([$a['private'], 99999, 'no-such-base'] as $x) { $t = tool(BEA, 'get_base', ['base' => $x]); $words[] = $t['code'] . '|' . ($t['data']['error']['message'] ?? ''); }
ok(count(array_unique($words)) === 1 && str_starts_with($words[0], 'not_found|'), 'get_base: the private base, an id that is not there and a key that is not there answer the same');
foreach ([['find_sources', ['base' => $a['private']]], ['source_outline', ['source' => $w['ask']['source'] + 100]], ['find_focus_areas', ['base' => $a['private']]], ['pinned_answers', ['base' => $a['private']]], ['graph_stats', ['base' => $g['valves']]]] as [$tn, $args]) {
    $t = tool(BEA, $tn, $args);
    ok($t['code'] === 'not_found', "$tn: not_found");
}
$t = tool(BEA, 'get_section', ['section' => $a['private_section']]);
ok($t['code'] === 'not_found' && !str_contains($t['text'], 'private plan'), 'a section of a private base: not_found, no words');
$t = tool(BEA, 'get_source', ['source' => (int) one('SELECT id FROM sources WHERE base_id = :b', ['b' => $a['private']])]);
ok($t['code'] === 'not_found', 'a source of a private base: not_found');
$t = tool(BEA, 'source_excerpt', ['question' => $w['q_private'], 'n' => 1]);
ok($t['code'] === 'not_found', 'a citation of someone else\'s question on a private base: not_found');
$t = tool(OWNER, 'get_base', ['base' => $a['private']]);
tool(OWNER, 'get_base', ['base' => $a['private']]);
$logged = (int) one("SELECT count(*) FROM activity_log WHERE action = 'base.admin_view' AND base_id = :b AND actor_member_id = :m AND created_at > now() - interval '1 hour'", ['b' => $a['private'], 'm' => OWNER]);
ok(!$t['error'] && ($t['data']['admin_view'] ?? false) === true && $logged === 1, 'the admin reads the private base: the answer says admin_view, and base.admin_view is logged once for the hour however often he reads');
$t = tool(MARCO, 'get_base', ['base' => $a['private']]);
ok(!$t['error'] && !array_key_exists('admin_view', $t['data']), 'its owner reads it without that mark');

echo "3. Questions: one's own, and every one on a base one curates\n";
$t = tool(PRIYA, 'get_question', ['question' => $w['q_carry']]);
ok(!$t['error'] && $t['data']['status'] === 'answered' && !array_key_exists('model', $t['data']) && !array_key_exists('usage', $t['data']) && $t['data']['my_rating'] === 'up', 'Priya reads her own question — without the model or the usage, with her rating');
$t = tool(MARCO, 'get_question', ['question' => $w['q_carry']]);
ok(!$t['error'] && array_key_exists('model', $t['data']) && array_key_exists('usage', $t['data']) && $t['data']['asker_name'] === 'SMOKE Priya', 'Marco curates the Handbook: he reads it, with the model and usage, and who asked');
foreach ([['Bea', BEA, $w['q_carry']], ['Priya on the private base', PRIYA, $w['q_private']]] as [$who, $m, $qid]) {
    $t = tool($m, 'get_question', ['question' => $qid]);
    ok($t['code'] === 'not_found', "$who: someone else's question is not_found");
}
ok(!tool(OWNER, 'get_question', ['question' => $w['q_private']])['error'], 'the admin reads every question');
$mq = tdata(PRIYA, 'my_questions', ['limit' => 100]);
ok(count($mq) >= 1 && count(array_filter($mq, fn ($r) => (int) sql_as(PRIYA, 'SELECT asked_by_member_id FROM mcp_questions WHERE question_id = ' . (int) $r['question_id'])[0]['asked_by_member_id'] !== PRIYA)) === 0, 'my_questions lists hers alone');
$t = tool(PRIYA, 'questions', ['base' => $hb]);
ok($t['code'] === 'curators_only', 'questions (every asker\'s) is the curator\'s: Priya gets curators_only');
$t = tool(MARCO, 'questions', ['base' => $hb, 'limit' => 100]);
ok(!$t['error'] && count($t['data']) >= 3 && in_array(PRIYA, array_map('intval', array_column($t['data'], 'asked_by_member_id')), true), 'Marco reads the log of his base, other people\'s questions included');
$t = tool(PRIYA, 'question_gaps', ['base' => $hb]);
ok($t['code'] === 'curators_only', 'question_gaps: curators_only for a reader');
$t = tool(MARCO, 'question_gaps', ['base' => $hb]);
ok(!$t['error'] && count($t['data']) >= 1 && strlen($t['data'][0]['sample_question']) <= 200, 'and Marco reads the gap cluster');

echo "4. The curator's tools say curators_only to a reader — and show them nothing\n";
foreach ([['find_guidelines', ['base' => $hb]], ['guideline_versions', ['base' => $hb]], ['find_vocabulary', ['base' => $hb]], ['unused_terms', ['base' => $hb]], ['unassigned_sources', ['base' => $hb]],
          ['empty_areas', ['base' => $hb]], ['build_progress', ['base' => $hb]], ['find_builds', ['base' => $hb]], ['build_estimate', ['base' => $hb]], ['base_usage', ['base' => $hb]], ['base_members', ['base' => $hb]],
          ['find_feeds', ['base' => $hb]], ['question_feedback', ['base' => $hb]], ['find_keys', ['base' => $k['manuals']]], ['extraction_preview', ['section' => $a['carry']]]] as [$tn, $args]) {
    $t = tool(PRIYA, $tn, $args);
    ok($t['code'] === 'curators_only', "$tn: curators_only for Priya on a base she only reads");
}
foreach (['find_guidelines', 'find_vocabulary', 'find_keys', 'archivist_proposals', 'find_feeds'] as $tn) {
    $t = tool(PRIYA, $tn, ['q' => 'p4']);
    ok(!$t['error'] && $t['data'] === [] || $t['data'] === ['base_guideline_version' => null, 'guidelines' => []], "$tn with q alone: Priya's list is empty (the view shows her no row)");
}
$t = tool(PRIYA, 'base_readers');
ok(!$t['error'] && $t['data'] === [], 'base_readers: nothing for Priya (the view shows readers of the bases one curates)');
$t = tool(PRIYA, 'find_builds', ['base' => $hb]);
ok($t['code'] === 'curators_only', 'find_builds on a base she does not curate: curators_only');
$t = tool(PRIYA, 'agent_dispatches');
ok(!$t['error'] && count(array_filter($t['data'], fn ($r) => $r['record_type'] === 'question')) === 1, 'agent_dispatches: only the dispatch Priya caused (she asked the expert about her question)');
$t = tool(BEA, 'agent_dispatches');
ok(!$t['error'] && $t['data'] === [], '...and Bea sees none');
$t = tool(PRIYA, 'get_section', ['section' => $a['carry']]);
ok(!$t['error'] && !array_key_exists('maludb_document_id', $t['data']), 'get_section: the engine\'s document id is shown to curators only');
$t = tool(MARCO, 'get_section', ['section' => $a['carry']]);
ok($t['data']['maludb_document_id'] === '501', '...Marco gets it');
$t = tool(PRIYA, 'get_focus_area', ['area' => 'leave', 'base' => $hb]);
ok(!$t['error'] && !array_key_exists('maludb_project_id', $t['data']['area']), 'get_focus_area: the project id is a curator\'s too');

echo "5. A forgotten section's words are never served\n";
$wall = base_by_key('p4-wall') ?? make_base(MARCO, 'SMOKE Wall', ['key' => 'p4-wall']);
pdo()->exec("UPDATE knowledge_bases SET visibility = 'internal' WHERE id = $wall");
$src = mksource($wall, 'p4 wall source');
$sec = mksection($src, '901');
pdo()->prepare("UPDATE sections SET text = 'P4-FORGOTTEN-WORDS were here.' WHERE id = :s")->execute(['s' => $sec]);
$before = tool(PRIYA, 'get_section', ['section' => $sec]);
ok(!$before['error'] && str_contains($before['data']['markdown'], 'P4-FORGOTTEN-WORDS'), 'a live section serves its words');
pdo()->exec("UPDATE sections SET status = 'forgotten' WHERE id = $sec");
$after = tool(PRIYA, 'get_section', ['section' => $sec]);
ok(!$after['error'] && !str_contains($after['text'], 'P4-FORGOTTEN-WORDS') && str_contains($after['data']['markdown'], '(forgotten)') && $after['data']['status'] === 'forgotten', 'once forgotten: the heading and "(forgotten)", no words');
$t = tool(PRIYA, 'source_excerpt', ['section' => $sec, 'quote' => 'P4-FORGOTTEN']);
ok($t['code'] === 'not_found' && !str_contains($t['text'], 'P4-FORGOTTEN-WORDS'), 'source_excerpt on it: not_found');
$t = tool(PRIYA, 'records_search', ['sql' => 'select text from mcp_sections where section_id = ' . $sec]);
ok(!$t['error'] && !str_contains($t['text'], 'P4-FORGOTTEN-WORDS'), 'and the view blanks it for the guarded search too');
$t = tool(PRIYA, 'records_search', ['sql' => 'select kn_section_markdown(' . $sec . ')']);
ok($t['error'] && !str_contains($t['text'], 'P4-FORGOTTEN-WORDS'), 'kn_section_markdown is not callable from the guarded search');

echo "6. A key's value and hash are in no answer\n";
$raw = 'knk_' . bin2hex(random_bytes(20));
q("INSERT INTO mcp_access_tokens (member_id, token_hash, label, scope, base_id) VALUES (:m, :h, 'p4 secret key', 'api', :b)", ['m' => MARCO, 'h' => hash('sha256', $raw), 'b' => $k['manuals']]);
$ptok = ptoken(MARCO);
foreach ([['find_keys', ['base' => $k['manuals']]], ['my_tokens', []], ['find_keys', ['q' => 'secret']]] as [$tn, $args]) {
    $t = tool(MARCO, $tn, $args);
    ok(!$t['error'] && !str_contains($t['text'], $raw) && !str_contains($t['text'], hash('sha256', $raw)) && !str_contains($t['text'], $ptok) && !str_contains($t['text'], hash('sha256', $ptok)) && !has_key($t['data'], 'token_hash'), "$tn: neither the key nor its hash, nor the person's own token");
}
$t = tool(MARCO, 'find_keys', ['base' => $k['manuals']]);
ok(find_row($t['data'], 'label', 'p4 secret key') !== null, '...but the key is there by its label');
$t = tool(BEA, 'my_tokens');
ok(!$t['error'] && count(array_filter($t['data'], fn ($r) => $r['label'] === 'p4 secret key')) === 0, 'my_tokens lists mine only');
$t = tool(MARCO, 'records_search', ['sql' => 'select * from mcp_keys']);
ok(!$t['error'] && !has_key($t['data'], 'token_hash') && !str_contains($t['text'], hash('sha256', $raw)), 'no view selects a hash: mcp_keys has none');

echo "7. The guarded search cannot step round the views\n";
foreach (['select * from base_members' => 'a table', 'select * from questions' => 'a table', 'select * from members' => 'a table', 'select * from activity_log' => 'the log table', 'select * from sections' => 'sections'] as $sql => $what) {
    $t = tool(BEA, 'records_search', ['sql' => $sql]);
    ok($t['error'] && !str_contains($t['text'], 'private plan'), "records_search: $what is not readable ($sql)");
}
foreach (["select set_config('app.member_id', '1', true)", "select current_setting('app.member_id')", "select kn_application_bases('helpdesk')", "select * from kn_application_base_ids('helpdesk')", "select kn_base_curator_ids(2)",
          "select mcp_admit_agent(41)", "select mcp_resolve_token('x', 'mcp')", "select pg_sleep(1)", "select kn_record_base_id('base', 2)"] as $sql) {
    $t = tool(BEA, 'records_search', ['sql' => $sql]);
    ok($t['error'] && ($t['code'] === 'invalid_argument'), "records_search refuses: $sql");
}
foreach (['delete from notes', 'select 1; select 2', 'update members set status = 1', 'with x as (select 1) insert into notes values (1)', 'drop table notes'] as $sql) {
    $t = tool(BEA, 'records_search', ['sql' => $sql]);
    ok($t['error'], "records_search refuses: $sql");
}
$t = tool(BEA, 'records_search', ['sql' => 'select base_id from mcp_bases order by 1']);
ok(!$t['error'] && !in_array($a['private'], ids($t['data'], 'base_id'), true), 'and what it does answer is the views\' rows for Bea: no private base');
$t = tool(BEA, 'records_search', ['sql' => 'select count(*) as n from generate_series(1, 500)']);
ok(!$t['error'] && $t['data'][0]['n'] === 500, 'a plain read still works');
$t = tool(BEA, 'records_search', ['sql' => 'select * from generate_series(1, 1000)']);
ok(!$t['error'] && count($t['data']) === 200, 'capped at 200 rows');

echo "8. A guest, an agent\n";
ok(tdata(ANN, 'find_departments') === [], 'a guest is shown no department');
$m = tdata(ANN, 'find_members', ['limit' => 100]);
$mem = array_map('intval', array_column($m, 'member_id'));
ok(in_array(MARCO, $mem, true) && !in_array(BEA, $mem, true), 'a guest sees the people on the bases shared with her (Marco owns them) and not Bea');
$m = tdata(PRIYA, 'find_members', ['limit' => 100]);
ok(in_array(BEA, array_map('intval', array_column($m, 'member_id')), true), 'a member sees everyone admitted');
ok(find_row($m, 'member_id', PRIYA)['email'] === 'priya@example.invalid' && find_row($m, 'member_id', BEA)['email'] === null, 'an email only for oneself');
facts(rid(40), SEAMUS, ['Records MCP' => ['find_bases' => [], 'find_guidelines' => [], 'get_base' => []]]);
$t = rtoken(SEAMUS, rid(40));
$sv = mcp_tool(REC, $t, 'find_bases', ['limit' => 100]);
$gotb = ids($sv['data'], 'base_id'); sort($gotb);
ok($gotb === array_values(array_map('intval', array_column(sql_as(SEAMUS, 'SELECT base_id FROM mcp_bases WHERE archived_at IS NULL ORDER BY base_id'), 'base_id'))), 'an agent is a member: Seamus reads the same rows the view shows him');
$sv = mcp_tool(REC, $t, 'find_guidelines', ['base' => $hb]);
ok($sv['code'] === 'curators_only', 'and without a curate share he gets curators_only on the Handbook');
q("INSERT INTO base_members (base_id, principal_kind, principal_id, level, granted_by) VALUES (:b, 'agent', :a, 'curate', :m) ON CONFLICT DO NOTHING", ['b' => $hb, 'a' => SEAMUS, 'm' => MARCO]);
$sv = mcp_tool(REC, $t, 'find_guidelines', ['base' => $hb]);
ok(!$sv['error'] && count($sv['data']['guidelines']) >= 2, 'with a curate share to the agent the same call answers');
q("DELETE FROM base_members WHERE base_id = :b AND principal_kind = 'agent' AND principal_id = :a", ['b' => $hb, 'a' => SEAMUS]);
finish();
