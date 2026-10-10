<?php
/** Slice 7 proof — the token API (api-keys-k7.md "Proof", The API). */
require __DIR__ . '/lib.php';

echo "4. The API\n";
$w = keys_world();
$m = $w['manuals'];
kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [manual_script($w)], 'recall' => [], 'titles' => []]);
health_cache_clear();
$k = mint(CARA, $m, 'Support site');
$kw = mint(CARA, $m, 'Warranty only', ['focus_area' => $w['warranty']]);
$since = last_activity_id();
$r = api('POST', '/api/v1/ask', $k['raw'], ['question' => 'What does the warranty cover?']);
$b = $r['body'];
$qid = (int) ($b['question_id'] ?? 0);
$row = question_row($qid);
ok($r['code'] === 200 && ($b['schema'] ?? '') === 'os.knowledge-answer/1' && $b['status'] === 'answered' && count($b['citations']) === 2 && $b['citations'][0]['section']['id'] === $w['cov'] && $b['citations'][0]['section']['page'] === 2
    && $b['citations'][0]['verified'] === true && str_contains($b['citations'][0]['url'], '/sections/' . $w['cov'] . '?quote='), 'POST /api/v1/ask with the key → slice 5\'s os.knowledge-answer/1: answered, two citations to the two sections with pages and urls');
ok(!isset($b['ok']) && !isset($b['http']) && !isset($b['error']) && !array_key_exists('model', $b) && !array_key_exists('usage', $b) && !str_contains($r['raw'], $k['raw']), 'the document is the contract\'s: no ok/http/error, no model or usage, no key');
ok($row['door'] === 'api' && (int) $row['token_id'] === $k['id'] && (int) $row['asked_by_member_id'] === CARA && $row['status'] === 'answered', 'the question row: door api, the key, asked_by = Cara (the trigger)');
$ask = activity('question.ask', $since);
$aa = json_decode($ask[0]['after'], true);
ok(count($ask) === 1 && $ask[0]['source'] === 'api' && (int) $ask[0]['token_id'] === $k['id'] && $aa['key_label'] === 'Support site' && $aa['door'] === 'api' && (int) $ask[0]['base_id'] === $m && count(activity('question.answer', $since)) === 1,
    'question.ask logged with source api, the token_id column and the key\'s label (and one outcome row)');
ok(!in_log($k['raw']) && !in_log(hash('sha256', $k['raw'])) && !in_log(KEY_SENTINEL), 'the key, its hash and the answer are in no activity row');
$sent = kb_last_answer();
ok($sent['namespace'] === 'kb:' . $m, 'the engine was asked the key\'s base');
// no Accept header at all: a plain client gets JSON too
$plain = req('POST', '/api/v1/ask', ['headers' => ['Authorization: Bearer ' . $k['raw'], 'Content-Type: application/json'], 'raw' => json_encode(['question' => 'What does the warranty cover? (plain)'])]);
ok($plain['code'] === 200 && (json_decode($plain['body'], true)['status'] ?? '') === 'answered' && str_contains($plain['headers'], 'application/json'), 'a client that sends no Accept header gets JSON all the same');
$r = api('POST', '/api/v1/ask', $k['raw'], ['question' => 'What does the warranty cover? (other base)', 'base' => $w['contracts']]);
ok($r['code'] === 200 && $r['body']['base']['key'] === 'smoke-manuals' && (int) question_row((int) $r['body']['question_id'])['base_id'] === $m, 'a body naming base 2 still asks base 1: the key\'s');
$r = api('POST', '/api/v1/ask', $kw['raw'], ['question' => 'What does the warranty cover? (area)', 'focus_area' => 'returns']);
ok($r['code'] === 403 && $r['body']['error']['code'] === 'area_not_allowed', 'a key bound to Warranty asking with focus_area: returns → 403 area_not_allowed');
$r = api('POST', '/api/v1/ask', $kw['raw'], ['question' => 'What does the warranty cover? (forced)']);
ok($r['code'] === 200 && (int) question_row((int) $r['body']['question_id'])['focus_area_id'] === $w['warranty'] && $r['body']['focus_area']['slug'] === 'warranty' && (kb_last_answer()['project'] ?? null) === 71, 'the same key without an area is forced to Warranty (project 71)');
$r = api('POST', '/api/v1/ask', $k['raw'], ['question' => 'What does the warranty cover? (slug)', 'focus_area' => 'warranty']);
ok($r['code'] === 200 && $r['body']['focus_area']['slug'] === 'warranty', 'an area by slug');
$r = api('POST', '/api/v1/ask', $k['raw'], ['question' => '']);
ok($r['code'] === 422 && $r['body']['error']['code'] === 'invalid' && isset($r['body']['error']['fields']['question']), '422 {error: {code: invalid, message, fields}} for an empty question');
$r = api('POST', '/api/v1/ask', $k['raw'], ['question' => 'warranty?', 'focus_area' => 'no-such-area']);
ok($r['code'] === 422 && isset($r['body']['error']['fields']['focus_area']), '422 for an area the base does not have');
$r = api('GET', '/api/v1/ask', $k['raw']);
ok($r['code'] === 405 && str_contains($r['headers'], 'Allow: POST'), 'GET /api/v1/ask → 405 with Allow');
$r = api('POST', '/api/v1/ask', $k['raw'], ['question' => 'What does the warranty cover? (conversation)']);
$conv = $r['body']['conversation_id'];
$r2 = api('POST', '/api/v1/ask', $k['raw'], ['question' => 'And returns? warranty', 'conversation_id' => $conv]);
ok($r2['body']['conversation_id'] === $conv && $r2['body']['turn_no'] === 2 && count(kb_last_answer()['history']) === 2, 'the key\'s conversation continues (turn 2, its history sent to the engine)');
$r3 = api('POST', '/api/v1/ask', $kw['raw'], ['question' => 'warranty again', 'conversation_id' => $conv]);
ok($r3['body']['conversation_id'] !== $conv && $r3['body']['turn_no'] === 1 && kb_last_answer()['history'] === [], 'another key naming that conversation starts a new one and never reads its turns');
// the engine stopped
$n = questions_count();
kb_stop();
$r = api('POST', '/api/v1/ask', $k['raw'], ['question' => 'What does the warranty cover? (engine down)']);
$fq = (int) ($r['body']['error']['question_id'] ?? 0);
ok($r['code'] === 503 && $r['body']['error']['code'] === 'engine_unavailable' && $fq > 0 && question_row($fq)['status'] === 'failed' && questions_count() === $n + 1, 'the fake engine stopped → 503 engine_unavailable with the question_id in the error (the failed row written)');
$s = api('POST', '/api/v1/search', $k['raw'], ['query' => 'warranty']);
ok($s['code'] === 503 && $s['body']['error']['code'] === 'engine_unavailable', '/api/v1/search with the engine stopped → 503');
health_cache_clear();
$h = api('GET', '/api/v1/health', null);
ok($h['code'] === 200 && $h['body']['engine'] === 'unavailable', 'health reports engine: unavailable while it is stopped');
kb_start();
kb_state(['answer_mode' => 'slow']);
$r = api('POST', '/api/v1/ask', $k['raw'], ['question' => 'What does the warranty cover? (slow)']);
ok($r['code'] === 503 && $r['body']['error']['code'] === 'engine_timeout', 'an engine that answers too slowly → 503 engine_timeout');
kb_state(['answer_mode' => 'ok']);
// search
kb_state(['recall' => [['chunk_id' => 91, 'document_id' => 701, 'statement_id' => 1, 'source_text' => 'The warranty covers defects in materials for two years.', 'similarity' => 0.78, 'subject_name' => 'Warranty', 'verb_name' => 'covers'],
                       ['chunk_id' => 92, 'document_id' => 702, 'statement_id' => 2, 'source_text' => 'A product may be returned within thirty days.', 'similarity' => 0.66, 'subject_name' => 'Returns', 'verb_name' => 'allows']], 'titles' => ['701' => 'Coverage', '702' => 'Window']]);
$n = questions_count();
$since = last_activity_id();
$s = api('POST', '/api/v1/search', $k['raw'], ['query' => 'warranty coverage', 'limit' => 5]);
$sl = activity('question.search', $since);
$sa = json_decode($sl[0]['after'] ?? '{}', true);
ok($s['code'] === 200 && $s['body']['schema'] === 'os.knowledge-evidence/1' && $s['body']['status'] === 'evidence_only' && count($s['body']['evidence']) === 2 && $s['body']['evidence'][0]['section']['id'] === $w['cov'] && questions_count() === $n,
    '/api/v1/search → os.knowledge-evidence/1, the passages mapped to sections, no question row');
ok(count($sl) === 1 && (int) $sl[0]['token_id'] === $k['id'] && $sl[0]['source'] === 'api' && $sa['result_count'] === 2 && $sa['query_excerpt'] === 'warranty coverage', 'question.search logged with the key, the count and the query excerpt only');
$s = api('POST', '/api/v1/search', $k['raw'], ['query' => 'warranty', 'limit' => 500]);
$rc = kb_log('/v1/memory/recall');
ok($s['code'] === 200 && end($rc)['body']['limit'] === 20, 'limit is clamped to 20 (the engine was asked for 20)');
$s = api('POST', '/api/v1/search', $k['raw'], ['query' => '']);
ok($s['code'] === 422 && isset($s['body']['error']['fields']['query']), 'an empty query → 422 fields.query');
// bases
$bs = api('GET', '/api/v1/bases', $k['raw']);
$bb = $bs['body'];
ok($bs['code'] === 200 && $bb['schema'] === 'os.knowledge-bases/1' && count($bb['bases']) === 1 && $bb['bases'][0]['key'] === 'smoke-manuals' && count($bb['bases'][0]['areas']) === 2 && $bb['bases'][0]['section_count'] >= 2,
    '/api/v1/bases → the one base with its two areas');
ok(isset($bb['calls_today']) && $bb['calls_today'] >= 6 && !str_contains($bs['raw'], 'owner') && !str_contains($bs['raw'], 'Marco') && !str_contains($bs['raw'], 'smoke-contracts'), 'with today\'s count; no owner, curator or other base');
$bw = api('GET', '/api/v1/bases', $kw['raw'])['body'];
ok(count($bw['bases'][0]['areas']) === 1 && $bw['bases'][0]['areas'][0]['slug'] === 'warranty', 'a key bound to Warranty sees that one area');
// questions
$own = (int) $b['question_id'];
[, $pq] = ask_as(PRIYA, ['base' => $m, 'question' => 'What does the warranty cover? (Priya)']);
$g = api('GET', '/api/v1/questions/' . $own, $k['raw']);
ok($g['code'] === 200 && $g['body']['schema'] === 'os.knowledge-answer/1' && $g['body']['question_id'] === $own && count($g['body']['citations']) === 2 && $g['body']['answer_md'] !== null, 'GET /api/v1/questions/{id} for the key\'s own question → the document');
$g2 = api('GET', '/api/v1/questions/' . $pq['question_id'], $k['raw']);
$g3 = api('GET', '/api/v1/questions/9999999', $k['raw']);
ok($g2['code'] === 404 && $g3['code'] === 404 && $g2['raw'] === $g3['raw'], 'Priya\'s window question → 404, the same body as a question that does not exist (though Cara, the minter, could read it in the app)');
$g4 = api('GET', '/api/v1/questions/' . $own, $kw['raw']);
ok($g4['code'] === 404, 'another key of the same base cannot read it');
// 401: one body
$tokP = act(as_member(PRIYA), '/settings/tokens/mint.php', ['label' => 'SMOKE person token', 'scope' => 'mcp']);
$rev = mint(CARA, $m, 'To revoke');
act(as_member(CARA), '/keys/revoke.php', ['api_key' => $rev['id']]);
$bodies = [];
foreach ([null, 'mcp_' . str_repeat('0', 48), (string) ($tokP[1]['token'] ?? 'none'), $rev['raw']] as $bearer) {
    $x = api('GET', '/api/v1/bases', $bearer);
    $bodies[] = $x['code'] . ' ' . $x['raw'];
}
ok(count(array_unique($bodies)) === 1 && str_starts_with($bodies[0], '401 ') && str_contains($bodies[0], '"unauthorized"'), 'no bearer, a wrong one, a person\'s mcp token and a revoked key → the same 401 body: ' . $bodies[0]);
ok(!empty($tokP[1]['token']) && api('POST', '/api/v1/ask', (string) $tokP[1]['token'], ['question' => 'x'])['code'] === 401, 'a person\'s mcp token is not a door to the API (401)');
// health
health_cache_clear();
$h = api('GET', '/api/v1/health', null);
ok($h['code'] === 200 && $h['body']['engine'] === 'ok' && !str_contains($h['raw'], '8503') && !str_contains($h['raw'], 'kb-fake') && !str_contains($h['raw'], 'http://'), 'GET /api/v1/health shows engine: ok and no URL or token');
kb_state(['answer_route' => false]);
api('POST', '/api/v1/ask', $k['raw'], ['question' => 'What does the warranty cover? (no M1)']);
health_cache_clear();
$h = api('GET', '/api/v1/health', null);
ok($h['body']['engine'] === 'answer_unavailable', 'engine: answer_unavailable when it is up but the last question fell back for answer_unavailable (M1 not deployed)');
kb_state(['answer_route' => true]);
health_cache_clear();
// session callers
$jar = as_member(PRIYA);
$tok = csrf_of(req('GET', '/', ['jar' => $jar])['body']);
$sr = req('POST', '/api/v1/ask', ['jar' => $jar, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $tok], 'json' => ['base' => 'smoke-manuals', 'question' => 'What does the warranty cover? (session)']]);
$sb = json_decode($sr['body'], true);
ok($sr['code'] === 200 && $sb['status'] === 'answered' && question_row((int) $sb['question_id'])['door'] === 'web' && (int) question_row((int) $sb['question_id'])['asked_by_member_id'] === PRIYA, 'a session caller (Priya) of /api/v1/ask: door web, held to her reach');
$nc = req('POST', '/api/v1/ask', ['jar' => $jar, 'headers' => ['Accept: application/json'], 'json' => ['base' => 'smoke-manuals', 'question' => 'warranty without csrf']]);
ok($nc['code'] === 403 && json_decode($nc['body'], true)['error']['code'] === 'csrf_failed', 'a session caller without the CSRF token → 403');
$pr = req('POST', '/api/v1/ask', ['jar' => $jar, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $tok], 'json' => ['base' => $w['contracts'], 'question' => 'warranty?']]);
ok($pr['code'] === 404, 'a session caller naming a private base she cannot read → 404');
$sg = req('GET', '/api/v1/bases', ['jar' => $jar, 'headers' => ['Accept: application/json']]);
$sgb = json_decode($sg['body'], true);
ok($sg['code'] === 200 && in_array('smoke-manuals', array_column($sgb['bases'], 'key'), true) && !in_array('smoke-contracts', array_column($sgb['bases'], 'key'), true) && !isset($sgb['calls_today']), 'a session caller of /api/v1/bases sees the bases mcp_bases gives her (not the private one)');
ok(api('GET', '/api/v1/other', null)['code'] === 404 && api('GET', '/api/', null)['code'] === 404, 'anything else under /api is 404');
if (getenv('KN_APP') === 'apache') {
    echo "   the public vhost\n";
    ok(api('POST', '/api/v1/ask', $k['raw'], ['question' => 'What does the warranty cover? (vhost)'])['code'] === 200 && api('GET', '/api/v1/bases', $k['raw'])['code'] === 200 && api('GET', '/api/v1/questions/' . $own, $k['raw'])['code'] === 200 && api('POST', '/api/v1/search', $k['raw'], ['query' => 'warranty'])['code'] === 200,
        'the public vhost answers the API (ask, search, bases, questions/{id}) with a bearer key');
    ok(api('POST', '/api/v1/questions/' . $own . '/feedback', $k['raw'], ['rating' => 'up'])['code'] === 200, 'and POST /api/v1/questions/{id}/feedback');
    ok(req('POST', '/internal/bridge.php', ['raw' => '{}'])['code'] === 404 && req('GET', '/internal/anything')['code'] === 404, '/internal/ stays 404 on the public vhost');
    ok(api('GET', '/api/v1/health.php', null)['code'] === 200 && api('GET', '/api/v1/ask.php', $k['raw'])['code'] === 405, 'a direct /api/v1/<name>.php is allowed (the rule passes it)');
}
finish();
