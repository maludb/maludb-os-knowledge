<?php
/**
 * Proof — the three K7 shares through the kernel's own token (design §8; K26): ask, search and bases_index. WHO is asking is the kernel's word — the headers X-OS-Consumer and X-OS-Consumer-Agent — never an argument:
 * helpdesk, which holds an application row on the Manuals, asks them; knowledge/projects hold nothing and are told not_shared; no header at all is consumer_unknown. Every call is logged share.read under the consumer, and no
 * log row holds a question's, an answer's or a passage's words. The kernel sends a share's arguments flat; a person's header cannot turn their token into the application's.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$k = $w['keys'];
$m = $k['manuals'];
$hd = ['X-OS-Consumer: helpdesk', 'X-OS-Consumer-Agent: 71'];
$other = ['X-OS-Consumer: projects'];
$call = fn (string $tool, array $args, array $hdr = [], ?string $tok = null) => mcp_tool(REC, $tok ?? kernel_token(), $tool, $args, true, $hdr);
kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [manual_script($k)], 'recall' => [['chunk_id' => 91, 'document_id' => 701, 'statement_id' => 1, 'source_text' => 'The warranty covers defects in materials for two years.', 'similarity' => 0.78,
    'subject_name' => 'Warranty', 'verb_name' => 'covers'], ['chunk_id' => 92, 'document_id' => 702, 'statement_id' => 2, 'source_text' => 'Returns within thirty days.', 'similarity' => 0.7, 'subject_name' => 'Returns', 'verb_name' => 'allows']],
    'titles' => ['701' => 'Coverage', '702' => 'Window']]);
$since = last_activity_id();

echo "1. bases_index: the bases shared with the consumer\n";
$bi = $call('bases_index', [], $hd);
ok(!$bi['error'] && $bi['data']['schema'] === 'os.knowledge-bases/1' && array_column($bi['data']['bases'], 'key') === ['smoke-manuals'], 'bases_index for helpdesk: os.knowledge-bases/1, the Manuals only');
$b = $bi['data']['bases'][0];
ok(array_keys($b) === ['key', 'name', 'description', 'template', 'language', 'status', 'last_built_at', 'source_count', 'section_count', 'areas'] && array_column($b['areas'], 'slug') === ['returns', 'warranty'], 'the contract\'s fields and its focus areas');
$bj = $bi['text'];
ok(!str_contains($bj, 'Marco') && !str_contains($bj, 'owner') && !str_contains($bj, 'curator') && !str_contains($bj, 'smoke-contracts') && !str_contains($bj, 'smoke-private'), 'people-free, and no other base');
ok($call('bases_index', [], $other)['data']['bases'] === [] && $call('bases_index', [], ['X-OS-Consumer: knowledge'])['data']['bases'] === [], 'projects and knowledge (named to nothing): no bases');
$bq = $call('bases_index', ['q' => 'manuals'], $hd);
$bn = $call('bases_index', ['q' => 'nothing-like-it'], $hd);
ok(count($bq['data']['bases']) === 1 && $bn['data']['bases'] === [], 'q filters by name');
$none = $call('bases_index', [], []);
ok(!$none['error'] && ($none['data']['error']['code'] ?? '') === 'consumer_unknown' && !str_contains($none['text'], 'smoke-manuals'), 'no X-OS-Consumer: consumer_unknown (an answer, not isError; nothing shared, no base named)');
$bad = $call('bases_index', [], ['X-OS-Consumer: Bad Key!']);
ok(($bad['data']['error']['code'] ?? '') === 'consumer_unknown', 'a header that is not a catalog key is the same as none');

echo "2. ask: the application principal\n";
$n0 = questions_count();
$r = $call('ask', ['base' => 'smoke-manuals', 'question' => 'What does the warranty cover?'], $hd);
$d = $r['data'];
ok(!$r['error'] && $d['schema'] === 'os.knowledge-answer/1' && $d['status'] === 'answered' && count($d['citations']) === 2 && $d['base']['key'] === 'smoke-manuals', 'helpdesk asks the Manuals (flat arguments): answered, two citations');
$row = question_row((int) $d['question_id']);
ok(questions_count() === $n0 + 1 && $row['door'] === 'share' && $row['application_key'] === 'helpdesk' && $row['asked_by_member_id'] === null && $row['token_id'] === null, 'the question row: door share, application_key helpdesk, no member');
$ev = last_event('question.ask');
$after = json_decode($ev['after'], true);
ok($ev['source'] === 'application' && $ev['actor_member_id'] === null && $after['application'] === 'helpdesk' && $after['door'] === 'share', 'kn_ask logged question.ask, source application, the application, no actor');
$sr = last_event('share.read');
$sa = json_decode($sr['after'], true);
ok($sr['source'] === 'application' && $sa['application'] === 'helpdesk' && $sa['tool'] === 'ask' && $sa['outcome'] === 'ok' && isset($sa['ms']), 'and share.read: the consumer, the tool, the outcome, the milliseconds');
$r2 = $call('ask', ['base' => (string) $m, 'question' => 'And returns? warranty', 'conversation_id' => $d['conversation_id']], $hd);
ok($r2['data']['turn_no'] === 2 && $r2['data']['conversation_id'] === $d['conversation_id'], 'a conversation continues for the same consumer (the base by id)');
$r3 = $call('ask', ['base' => 'smoke-manuals', 'question' => 'warranty again', 'conversation_id' => $d['conversation_id']], $other);
ok(($r3['data']['error']['code'] ?? '') === 'not_shared', 'projects, to which nothing is shared, cannot continue it');
$sneak = $call('ask', ['base' => 'smoke-manuals', 'question' => 'What does the warranty cover? (sneak)', 'consumer' => 'projects', 'as_agent' => 1, 'consumer_agent_id' => 5], $hd);
$srow = question_row((int) $sneak['data']['question_id']);
ok(!$sneak['error'] && $srow['application_key'] === 'helpdesk', 'a consumer, as_agent or consumer_agent_id in the arguments is dropped: the header is the only word');
$words = [];
foreach ([[$k['contracts'], $hd], ['smoke-contracts', $hd], [999999, $hd], ['no-such-base', $hd], [$m, $other], [$w['ask']['handbook'], $hd], [$w['ask']['private'], $hd]] as [$base, $hdr]) {
    $x = $call('ask', ['base' => $base, 'question' => 'warranty?'], $hdr);
    $words[] = json_encode($x['data']['error'] ?? $x['data']);
}
ok(array_unique($words) === [json_encode(['code' => 'not_shared', 'message' => 'No such base is shared with this application.'])], 'a private base, a made-up one, one shared to someone else, an internal one named to nobody: not_shared, the same words each time');
$x = $call('ask', ['base' => $k['contracts'], 'question' => 'warranty?'], $hd);
ok(!$x['error'] && $x['data']['schema'] === 'os.knowledge-answer/1' && !array_key_exists('question_id', $x['data']), 'a refusal is a normal result (not isError), shaped {schema, application, error}');
$u = $call('ask', ['base' => 'smoke-manuals', 'question' => 'warranty?'], []);
ok(($u['data']['error']['code'] ?? '') === 'consumer_unknown' && questions_count() === $n0 + 3, 'no header: consumer_unknown, nothing asked, nothing stored');
$e = $call('ask', ['base' => 'smoke-manuals', 'question' => ''], $hd);
ok(($e['data']['error']['code'] ?? '') === 'invalid' || $e['error'], 'an empty question: refused');
$e = $call('ask', ['base' => 'smoke-manuals', 'question' => 'warranty?', 'focus_area' => 'nope'], $hd);
ok(($e['data']['error']['code'] ?? '') === 'invalid' && isset($e['data']['error']['fields']['focus_area']), 'a focus area the base does not have: invalid, naming the field');
$e = $call('ask', ['base' => 'smoke-manuals', 'question' => 'How does the warranty on returns work?', 'focus_area' => 'warranty'], $hd);
ok(!$e['error'] && ($e['data']['focus_area']['slug'] ?? '') === 'warranty', 'the area by its slug');
$mr = mcp_tool(REC, ptoken(PRIYA), 'ask', ['base' => 'smoke-manuals', 'question' => 'warranty? (person with header)'], false, $hd);
$prow = question_row((int) ($mr['data']['question_id'] ?? 0));
ok(($prow['door'] ?? '') === 'mcp' && (int) ($prow['asked_by_member_id'] ?? 0) === PRIYA && $prow['application_key'] === null, 'with the header on a person\'s call: door mcp, Priya, no application key');

echo "3. search: the evidence, nothing stored\n";
$n = questions_count();
$s = $call('search', ['base' => 'smoke-manuals', 'query' => 'warranty', 'limit' => 5], $hd);
ok(!$s['error'] && $s['data']['schema'] === 'os.knowledge-evidence/1' && $s['data']['status'] === 'evidence_only' && questions_count() === $n, 'search for helpdesk: os.knowledge-evidence/1, no question stored');
$s = $call('search', ['base' => 'smoke-contracts', 'query' => 'terms'], $hd);
ok(($s['data']['error']['code'] ?? '') === 'not_shared', 'search on a base not shared: not_shared');
$s = $call('search', ['base' => 'smoke-manuals', 'query' => 'warranty'], []);
ok(($s['data']['error']['code'] ?? '') === 'consumer_unknown', 'search with no header: consumer_unknown');
$sl = last_event('share.read');
ok(json_decode($sl['after'], true)['tool'] === 'search' && json_decode($sl['after'], true)['outcome'] === 'consumer_unknown', 'every call is logged share.read, refused ones too');

echo "4. The engine down, the log, the people\n";
kb_stop();
$d2 = $call('ask', ['base' => 'smoke-manuals', 'question' => 'What does the warranty cover? (engine down)'], $hd);
ok(!$d2['error'] && $d2['data']['status'] === 'failed' && $d2['data']['error_code'] === 'engine_unavailable', 'engine down: a failed document with engine_unavailable, not a crash');
kb_start();
$sl = last_event('share.read');
ok(json_decode($sl['after'], true)['outcome'] === 'engine_unavailable', '...logged as that outcome');
$shareRows = q("SELECT * FROM activity_log WHERE action = 'share.read' AND id > :s ORDER BY id", ['s' => $since]);
ok(count($shareRows) >= 12 && count(array_filter($shareRows, fn ($r) => $r['actor_member_id'] !== null)) === 0 && count(array_filter($shareRows, fn ($r) => $r['source'] !== 'application')) === 0, 'every share call wrote a share.read row: source application, no actor');
ok(!in_log(KEY_SENTINEL) && !in_log('defects in materials') && !in_log('thirty days'), 'no log row holds an answer\'s, a quote\'s or a passage\'s words (a question\'s first 120 characters are the log\'s own allowance)');
$cites = q("SELECT c.section_id FROM citations c WHERE c.question_id = :q", ['q' => (int) $d['question_id']]);
ok(count($cites) === 2 && (int) one('SELECT base_id FROM sections WHERE id = :s', ['s' => $cites[0]['section_id']]) === $m, 'the citations are sections of the shared base');
ok(!str_contains(json_encode($d), 'Marco') && !str_contains(json_encode($d), 'asker') && !str_contains(json_encode($d), 'Priya'), 'the answer names no person');
finish();
