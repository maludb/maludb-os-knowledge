<?php
/** Slice 6 proof — the bridge's graph ops (graph-explorer.md "Proof", The bridge). */
require __DIR__ . '/lib.php';

echo "9. The bridge\n";
$w = graph_world();
$p = $w['pumps'];
$v = $w['valves'];
g_set($w, g_communities($w) + g_surprises($w));
g_cache_clear($p);
g_cache_clear($v);
$internal = getenv('KN_APP') === 'apache' ? 'http://127.0.0.1:8507' : BASE;
$op = static function (string $op, array $args, int $member = PRIYA, string $kind = 'member') use ($internal): array {
    $caller = $kind === 'member' ? ['kind' => 'member', 'member_id' => $member] : ['kind' => 'application', 'application' => 'helpdesk'];
    [$c, $b] = bridge(['op' => $op, 'caller' => $caller, 'door' => 'mcp', 'run_id' => null, 'request_id' => null, 'is_eval' => false, 'args' => $args], null, null, $internal);
    return [$c, $b];
};
$n0 = questions_count();
$since = last_activity_id();

[$c, $b] = $op('graph_stats', ['base' => $p]);
ok($c === 200 && $b['base_id'] === $p && $b['documents'] === 4 && $b['documents_cached'] === 4 && $b['subjects_count'] === 4 && $b['statements_count'] === 3 && $b['communities_count'] === 0 && !str_contains(g_flat($b), '98765')
    && array_column($b['top_subjects'], 'name') === ['Zorblat Pump', 'Merrow Filter', 'Zorblat Pumps', 'ZP-9'] && $b['top_subjects'][0]['degree'] === 9 && $b['top_verbs'] !== [], 'graph_stats: the base\'s own figures, the confined top subjects with their degree, the verbs — no tenant-wide count');
$verbs = array_column($b['top_verbs'], 'verb');
ok(in_array('described_in', $verbs, true) && in_array('uses', $verbs, true), 'top_verbs from the top subjects\' edges (' . implode(', ', $verbs) . ')');

[$c, $b] = $op('communities', ['base' => $p]);
ok($c === 200 && $b['communities'] === [['community_id' => 11, 'label' => 'Pump and filter', 'size' => 2], ['community_id' => 12, 'label' => 'Legacy', 'size' => 1]] && $b['note'] === '', 'communities: id, label, size');
g_set($w, g_surprises($w));
g_cache_clear($p);
[$c, $b] = $op('communities', ['base' => $p]);
ok($c === 200 && $b['communities'] === [] && str_contains($b['note'], 'god_nodes'), 'with none, an empty list and a note that points to god_nodes');
g_set($w, g_communities($w) + g_surprises($w));
g_cache_clear($p);
[$c, $b] = $op('community_members', ['base' => $p, 'community' => 11]);
ok($c === 200 && $b['community_id'] === 11 && array_column($b['members'], 'name') === ['Zorblat Pump', 'Merrow Filter', 'Zorblat Pump'] && array_column($b['members'], 'kind') === ['subject', 'subject', 'section'] && $b['members'][2]['section']['section_id'] === $w['zorblat']
    && !str_contains(g_flat($b), 'Quillon') && !str_contains(g_flat($b), '601'), 'community_members: confined, a document as its section');
[$c, $b] = $op('community_members', ['base' => $p, 'community' => 21]);
ok($c === 404 && $b['error']['code'] === 'not_in_base', 'a community of another base → not_in_base');

[$c, $b] = $op('subject', ['base' => $p, 'subject' => 'Zorblat Pump']);
ok($c === 200 && $b['name'] === 'Zorblat Pump' && $b['type'] === 'product' && $b['statements'] === 2 && $b['neighbours'] === 1 && count($b['sections']) === 2 && $b['sections'][0] === ['section_id' => $w['zorblat'], 'heading_path' => 'Pump manual › Pumps › Zorblat Pump', 'source' => ['id' => $w['source'], 'title' => 'Pump manual'], 'page_no' => 1],
    'subject: type, counts and the sections as {section_id, heading_path, source, page_no}');
[$c, $bi] = $op('subject', ['base' => $p, 'subject' => $b['subject_id']]);
ok($c === 200 && $bi['name'] === 'Zorblat Pump', 'a subject can be named by its id');
[$c, $b2] = $op('subject', ['base' => $p, 'subject' => 'Quillon Valve']);
[$c2, $b3] = $op('subject', ['base' => $p, 'subject' => 'Nothing At All']);
ok($c === 404 && $c2 === 404 && $b2['error']['code'] === 'not_in_base' && $b3['error']['code'] === 'not_in_base' && $b2['error']['message'] === $b3['error']['message'], 'a subject the base\'s documents never touch, and one the engine does not know → the same not_in_base');
[$c, $b] = $op('subject_neighbors', ['base' => $p, 'subject' => 'Zorblat Pump']);
$kinds = array_column($b['neighbours'], 'kind');
ok($c === 200 && $b['subject']['name'] === 'Zorblat Pump' && array_count_values($kinds) === ['subject' => 1, 'section' => 2, 'statement' => 2] && !str_contains(g_flat($b), 'Quillon'), 'subject_neighbors: the subject, the sections (documents replaced) and the statements');
[$c, $b] = $op('subject_neighbors', ['base' => $p, 'subject' => 'Zorblat Pump', 'kind' => 'document']);
ok($c === 200 && array_unique(array_column($b['neighbours'], 'kind')) === ['section'] && count($b['neighbours']) === 2, 'kind document filters to the sections');
[$c] = $op('subject_neighbors', ['base' => $p, 'subject' => 'Zorblat Pump', 'kind' => 'bogus']);
ok($c === 422, 'a bad kind → 422');
[$c, $b] = $op('subject_statements', ['base' => $p, 'subject' => 'Zorblat Pump']);
ok($c === 200 && array_column($b['by_verb'], 'verb') === ['rated', 'uses'] && $b['by_verb'][0]['statements'][0]['text'] === 'Zorblat Pump is rated at forty bar' && $b['by_verb'][0]['statements'][0]['section']['section_id'] === $w['zorblat'], 'subject_statements: by verb');
[$c, $b] = $op('subject_statements', ['base' => $p, 'subject' => 'Zorblat Pump', 'verb' => 'uses']);
ok(array_column($b['by_verb'], 'verb') === ['uses'], '…and one verb');
[$c, $b] = $op('sections_for_subject', ['base' => $p, 'subject' => 'Merrow Filter']);
ok($c === 200 && array_column($b['sections'], 'section_id') === [$w['merrow']] && $b['subject']['name'] === 'Merrow Filter', 'sections_for_subject');

[$c, $b] = $op('path', ['base' => $p, 'from' => 'Zorblat Pump', 'to' => 'Merrow Filter']);
ok($c === 200 && $b['found'] === true && array_column($b['steps'], 'kind') === ['subject', 'section', 'subject'] && $b['steps'][1]['section']['section_id'] === $w['merrow'], 'path: found, the steps with the section in the middle');
[$c, $b] = $op('path', ['base' => $p, 'from' => 'Zorblat Pump', 'to' => 'Quillon Valve']);
ok($c === 404 && $b['error']['code'] === 'no_path_in_base' && !str_contains(g_flat($b), '601') && !str_contains(g_flat($b), 'closes'), 'a path that is not wholly the base\'s → no_path_in_base, no node named');
[$c, $b] = $op('god_nodes', ['base' => $p, 'limit' => 3]);
ok($c === 200 && array_column($b['god_nodes'], 'name') === ['Zorblat Pump', 'Merrow Filter', 'Merrow Filter'] && array_column($b['god_nodes'], 'kind') === ['subject', 'subject', 'section'] && $b['god_nodes'][0]['degree'] === 9, 'god_nodes: kind, id, name, degree (a document as its section)');
[$c, $b] = $op('surprises', ['base' => $p]);
ok($c === 200 && count($b['surprises']) === 2 && $b['surprises'][0]['from']['name'] === 'Zorblat Pump' && $b['surprises'][0]['verb'] === 'uses' && $b['surprises'][1]['from']['kind'] === 'section' && $b['surprises'][1]['sections'][0]['section_id'] === $w['zorblat']
    && !str_contains(g_flat($b), 'Quillon'), 'surprises: both ends confined');
[$c, $b] = $op('graph_query', ['base' => $p, 'text' => 'Zorblat']);
ok($c === 200 && array_column($b['seeds'], 'name') === ['Zorblat Pump', 'Zorblat Pumps'] && !in_array('document', array_column($b['nodes'], 'kind'), true) && in_array('section', array_column($b['nodes'], 'kind'), true) && $b['edges'] !== []
    && !str_contains(g_flat($b), 'Quillon') && !str_contains(g_flat($b), '601'), 'graph_query: seeds, nodes (documents replaced by sections) and edges, nothing of Valves');
[$c] = $op('graph_query', ['base' => $p, 'text' => 'z']);
ok($c === 422, 'graph_query text under 2 characters → 422');
[$c, $b] = $op('section_graph', ['section' => $w['zorblat']]);
ok($c === 200 && $b['extracted'] === true && array_column($b['subjects'], 'name') === ['Zorblat Pump'] && count($b['statements']) === 1 && $b['statements'][0]['statement_id'] === 9001, 'section_graph: the subjects and statements it taught');
[$c, $b] = $op('section_graph', ['section' => $w['top']]);
ok($c === 200 && $b['extracted'] === false && $b['subjects'] === [], 'a section never extracted: subjects [] and extracted false');
[$c, $b] = $op('section_graph', ['section' => $w['quillon']]);
ok($c === 404 && $b['error']['code'] === 'not_found', 'a section of a base Priya cannot read → not_found');
[$c, $b] = $op('source_graph', ['source' => $w['source']]);
ok($c === 200 && $b['sections_read'] === 4 && $b['sections_total'] === 5 && $b['subjects'][0] === ['subject_id' => $b['subjects'][0]['subject_id'], 'name' => 'Zorblat Pump', 'type' => null, 'sections' => 2], 'source_graph: subjects by section count');
[$c] = $op('source_graph', ['source' => $w['valve_source']]);
ok($c === 404, 'a source Priya cannot read → 404');

[$c, $b] = $op('duplicate_subjects', ['base' => $p]);
ok($c === 403 && $b['error']['code'] === 'curators_only', 'duplicate_subjects for Priya → curators_only (403)');
[$c, $b] = $op('duplicate_subjects', ['base' => $p], MARCO);
ok($c === 200 && count($b['groups']) === 2 && array_column($b['groups'][0]['subjects'], 'name') === ['Zorblat Pump', 'Zorblat Pumps'] && $b['documents_read'] === 4 && $b['documents_total'] === 4 && isset($b['groups'][1]['vocabulary_term_id']), 'for Marco → the groups, documents_read / documents_total');
$all = [['graph_stats', []], ['communities', []], ['community_members', ['community' => 21]], ['subject', ['subject' => 'Quillon Valve']], ['subject_neighbors', ['subject' => 'Quillon Valve']], ['subject_statements', ['subject' => 'Quillon Valve']],
        ['path', ['from' => 'a', 'to' => 'b']], ['god_nodes', []], ['surprises', []], ['graph_query', ['text' => 'Quillon']], ['sections_for_subject', ['subject' => 'Quillon Valve']], ['duplicate_subjects', []]];
$codes = [];
foreach ($all as [$name, $args]) { [$c, $b] = $op($name, ['base' => $v] + $args, BEA); $codes[$name] = $c . ' ' . ($b['error']['code'] ?? '') . ' ' . ($b['error']['message'] ?? ''); }
ok(array_unique(array_values($codes)) === ['404 not_found Knowledge base not found.'], 'every graph op for a base Bea cannot read → the same not_found, even duplicate_subjects (' . json_encode(array_unique(array_values($codes))) . ')');
[$c, $b] = $op('graph_stats', ['base' => 999999]);
ok($c === 404 && $b['error']['code'] === 'not_found', 'a made-up base → not_found');
[$c, $b] = $op('graph_stats', ['base' => 'smoke-pumps']);
ok($c === 200 && $b['base_id'] === $p, 'the base by its key');
[$c, $b] = $op('graph_stats', ['base' => $p], PRIYA, 'application');
ok($c === 403 && $b['error']['code'] === 'caller_not_allowed', 'an application caller on a graph op → refused (the share ops are slice 7\'s)');
[$c, $b] = $op('graph_stats', ['base' => $p], 999999);
ok($c === 403 && $b['error']['code'] === 'unknown_member', 'an unknown member → 403');
$after = q("SELECT action FROM activity_log WHERE id > :s AND action NOT IN ('member.sign_on')", ['s' => $since]);
ok(questions_count() === $n0 && $after === [], 'the graph ops write nothing: no question, no log row (' . json_encode(array_column($after, 'action')) . ')');
g_set($w);
g_cache_clear($p);
finish();
