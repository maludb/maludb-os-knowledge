<?php
/**
 * Proof — the questions: get_question rebuilds EXACTLY the os.knowledge-answer/1 document the window and the token API answer with (compared with the PHP presenter for an answered, a no_evidence, an evidence_only and a failed
 * question), my_questions / questions / question_feedback / question_gaps / pinned_answers / pins_due read the database's own rows with their filters and their cuts, and none of them shows the answer's words to a log.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$a = $w['ask'];
$hb = $a['handbook'];

/** The PHP presenter's document for a question, as the member who reads it. */
function php_doc(int $qid, int $member): array
{
    $out = php_run('require_once APP_ROOT . "/app/features/ask/queries.php"; require_once APP_ROOT . "/app/features/ask/present.php"; require_once APP_ROOT . "/app/features/bases/queries.php";
        $q = find_question($pdo, (int) $arg); $b = find_base($pdo, (int) $q["base_id"]);
        $area = $q["focus_area_id"] === null ? null : ($pdo->query("SELECT slug, name FROM focus_areas WHERE id = " . (int) $q["focus_area_id"])->fetch(PDO::FETCH_ASSOC) ?: null);
        echo json_encode(ask_present_question($q, ["key" => $b["key"], "name" => $b["name"]], $area, question_citations($pdo, (int) $arg)));', $member, $qid);
    $d = json_decode($out, true) ?? ['php_error' => $out];
    unset($d['generated_at']);
    return $d;
}
/** The tool's document with the parts the PHP presenter does not have (and the clock) taken out. */
function core(array $d): array
{
    foreach (['generated_at', 'asker_name', 'door', 'created_at', 'my_rating', 'key_label', 'application_key', 'usage', 'model', 'latency_ms'] as $k) { unset($d[$k]); }
    return $d;
}

echo "1. get_question is the document the window answers with\n";
kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [carry_script($a)]]);
[, $ans] = ask_as(PRIYA, ['base' => $hb, 'question' => 'How does carry-over work? (p4 asking)']);
$qs = ['answered' => (int) $ans['question_id'], 'no_evidence' => $w['q_gap']];
$tag = bin2hex(random_bytes(3));
$qs['evidence_only'] = evidence_only_question($a, PRIYA, "How does carry-over work? (p4 evidence $tag)");
kb_stop();
[, $dn] = ask_as(PRIYA, ['base' => $hb, 'question' => 'How does carry-over work? (p4 down)']);
kb_start();
$qs['failed'] = (int) $dn['question_id'];
foreach ($qs as $status => $qid) {
    $t = tool(PRIYA, 'get_question', ['question' => $qid]);
    $php = php_doc($qid, PRIYA);
    ok(!$t['error'] && $t['data']['status'] === $status && core($t['data']) == $php, "a $status question: the tool's document equals the PHP presenter's, field for field" . (isset($php['php_error']) ? ' — ' . substr($php['php_error'], 0, 200) : ''));
}
$d = tdata(PRIYA, 'get_question', ['question' => $qs['answered']]);
ok(count($d['citations']) === 2 && $d['citations'][1]['n'] === 2 && $d['citations'][0]['section']['source']['kind'] === 'text' && $d['evidence'][0]['section']['source']['kind'] === 'text', 'the answered one: two citations and the evidence, each section with its source and kind');
ok($d['door'] === 'web' && $d['asker_name'] === 'SMOKE Priya' && !array_key_exists('model', $d) && array_key_exists('created_at', $d), 'plus who asked, the door and when');
$cd = tdata(MARCO, 'get_question', ['question' => $qs['answered']]);
ok($cd['model'] === 'fake-answer-model' && $cd['usage']['calls'] === 1 && $cd['latency_ms'] !== null, 'a curator also gets the model, the usage and the latency');
$ev = tdata(PRIYA, 'get_question', ['question' => $qs['evidence_only']]);
ok($ev['answer_md'] === null && $ev['message'] !== null && count($ev['evidence']) === 2, 'evidence_only: no answer, a plain sentence, the passages');

echo "2. my_questions\n";
$all = tdata(PRIYA, 'my_questions', ['limit' => 100]);
ok(count($all) >= 4 && count(array_filter($all, fn ($r) => !in_array($r['status'], ['answered', 'no_evidence', 'evidence_only', 'failed', 'refused', 'unverified'], true))) === 0, 'Priya\'s questions, newest first');
ok($all[0]['created_at'] >= $all[1]['created_at'], 'newest first');
$row = find_row($all, 'question_id', $qs['answered']);
ok($row['citation_count'] === 2 && $row['verified_citations'] === 2 && $row['door'] === 'web' && $row['my_rating'] === null && $row['pinned'] === false && mb_strlen($row['question']) <= 300, 'a row: citations, verified ones, door, rating, pin, the question cut');
$row = find_row($all, 'question_id', $w['q_carry']);
ok($row['my_rating'] === 'up' && $row['pinned'] === true, 'the rated, pinned one shows my rating and its pin');
ok(count(tdata(PRIYA, 'my_questions', ['status' => 'no_evidence'])) >= 1 && count(array_filter(tdata(PRIYA, 'my_questions', ['status' => 'no_evidence']), fn ($r) => $r['status'] !== 'no_evidence')) === 0, 'status filters');
ok(count(tdata(PRIYA, 'my_questions', ['base' => $hb, 'limit' => 100])) === count($all), 'a base filter');
ok(tdata(PRIYA, 'my_questions', ['base' => $a['private']]) === [] || tool(PRIYA, 'my_questions', ['base' => $a['private']])['code'] === 'not_found', 'a base she cannot read: nothing');
ok(tdata(PRIYA, 'my_questions', ['since' => '2999-01-01']) === [], 'since');
ok(tool(PRIYA, 'my_questions', ['since' => 'yesterday-ish'])['code'] === 'invalid_argument', 'a since that is not a time: invalid_argument');
$cv = $ans['conversation_id'];
ok(count(tdata(PRIYA, 'my_questions', ['conversation_id' => $cv])) === 1, 'conversation_id');
$r = tdata(PRIYA, 'my_questions', ['q' => "p4 evidence $tag"]);
ok(count($r) === 1 && array_keys($r[0]) === ['question_id', 'question', 'base_id'] && $r[0]['question_id'] === $qs['evidence_only'], 'q alone resolves one of my questions: {question_id, question}');
ok(tdata(BEA, 'my_questions', ['q' => "p4 evidence $tag"]) === [], 'and not another person\'s');
ok(tdata(MARCO, 'my_questions', ['base' => $hb, 'limit' => 100]) === [] || count(array_filter(tdata(MARCO, 'my_questions', ['base' => $hb, 'limit' => 100]), fn ($r) => $r['question_id'] === $qs['answered'])) === 0, 'Marco curates the base but my_questions is still only his own');
$pg1 = tdata(PRIYA, 'my_questions', ['limit' => 2]);
$pg2 = tdata(PRIYA, 'my_questions', ['limit' => 2, 'offset' => 2]);
ok(count($pg1) === 2 && count(array_intersect(ids($pg1, 'question_id'), ids($pg2, 'question_id'))) === 0, 'limit and offset page');

echo "3. questions, question_feedback, question_gaps — the curator's log\n";
$t = tdata(MARCO, 'questions', ['base' => $hb, 'limit' => 100]);
$mine = find_row($t, 'question_id', $qs['answered']);
ok($mine !== null && mb_strlen($mine['question']) <= 300 && mb_strlen((string) $mine['answer_md']) <= 600 && $mine['asker_name'] === 'SMOKE Priya' && $mine['door'] === 'web' && $mine['citation_count'] === 2, 'questions: every asker\'s, with the asker, the door, the counts');
ok(count(array_filter(tdata(MARCO, 'questions', ['base' => $hb, 'status' => 'failed']), fn ($r) => $r['status'] !== 'failed')) === 0 && count(tdata(MARCO, 'questions', ['base' => $hb, 'status' => 'failed'])) >= 1, 'by status');
ok(count(array_filter(tdata(MARCO, 'questions', ['base' => $hb, 'asked_by' => 'SMOKE Priya', 'limit' => 100]), fn ($r) => $r['asked_by_member_id'] !== PRIYA)) === 0 && count(tdata(MARCO, 'questions', ['base' => $hb, 'asked_by' => SEAMUS])) >= 1, 'by asker (a name or an id)');
ok(count(array_filter(tdata(MARCO, 'questions', ['base' => $hb, 'door' => 'agent']), fn ($r) => $r['door'] !== 'agent')) === 0 && count(tdata(MARCO, 'questions', ['base' => $hb, 'door' => 'agent'])) >= 1, 'by door');
ok(tdata(MARCO, 'questions', ['base' => $hb, 'door' => 'api']) === [] && tdata(MARCO, 'questions', ['base' => $hb, 'since' => '2999-01-01']) === [], 'a door nobody used, a time not yet');
ok(count(tdata(MARCO, 'questions', ['base' => $hb, 'q' => "p4 evidence $tag"])) === 1, 'by words');
ok(count(tdata(MARCO, 'questions', ['base' => $hb, 'focus_area' => 'leave'])) >= 1, 'by focus area (the one asked in Leave)');
ok(tool(MARCO, 'questions', ['base' => $hb, 'asked_by' => 'Nobody At All'])['code'] === 'not_found', 'an asker who does not exist: not_found');
$fb = tdata(MARCO, 'question_feedback', ['base' => $hb]);
ok(count($fb) === 1 && $fb[0]['rating'] === 'up' && $fb[0]['comment'] === 'p4 thanks' && $fb[0]['member_name'] === 'SMOKE Priya' && $fb[0]['status'] === 'answered' && array_key_exists('key_label', $fb[0]), 'question_feedback: the thumb, the comment, who gave it, the question\'s outcome');
ok(tdata(MARCO, 'question_feedback', ['base' => $hb, 'rating' => 'down']) === [], 'by rating');
$gp = tdata(MARCO, 'question_gaps', ['base' => $hb]);
ok(count($gp) >= 1 && $gp[0]['question_count'] >= 1 && count($gp[0]['question_ids']) >= 1 && in_array($w['q_gap'], $gp[0]['question_ids'], true), 'question_gaps: the questions the base could not answer, clustered');
ok(tdata(MARCO, 'question_gaps', ['base' => $hb, 'since' => '2999-01-01']) === [], 'since');
$sql = sql_as(OWNER, 'SELECT cluster_key, question_count FROM kn_question_gaps(' . $hb . ', now() - interval \'30 days\') ORDER BY question_count DESC, cluster_key');
ok(count($sql) === count($gp), 'as many clusters as the database function says');

echo "4. Pinned answers\n";
$pa = tdata(PRIYA, 'pinned_answers', ['base' => $hb]);
ok(count($pa) === 1 && $pa[0]['title'] === 'p4 Carry-over' && $pa[0]['review_due'] === true && $pa[0]['owner_name'] === 'SMOKE Marco' && $pa[0]['status'] === 'active', 'pinned_answers: the pin, its owner, review due');
$pq = tdata(PRIYA, 'pinned_answers', ['q' => 'carry']);
ok(count($pq) === 1 && array_keys($pq[0]) === ['pinned_answer_id', 'title', 'base_id'], 'q alone resolves a pinned answer by title');
ok(tdata(PRIYA, 'pinned_answers', ['base' => $hb, 'status' => 'retired']) === [], 'retired: none');
$pd = tdata(PRIYA, 'pins_due');
ok(count($pd) === 1 && $pd[0]['pinned_answer_id'] === $w['pin'], 'pins_due: past its review date');
ok(count(tdata(MARCO, 'pins_due', ['owner' => 'me'])) === 1 && tdata(PRIYA, 'pins_due', ['owner' => 'me']) === [], 'owner: me keeps the ones I own');
ok(tdata(PRIYA, 'pins_due', ['base' => $a['handbook']]) !== [] && tool(BEA, 'pins_due', ['base' => $a['private']])['code'] === 'not_found', 'a base filter, and a private base is not found');

echo "5. Nothing of an answer in the trail\n";
ok(!in_log(SENTINEL), 'the answer\'s, a quote\'s and a passage\'s words are in no activity row');
$long = str_repeat('L', 300);
kb_state(['scripts' => [carry_script($a)]]);
tool(PRIYA, 'ask', ['base' => $hb, 'question' => 'carry ' . $long]);
$ev = last_event('question.ask');
ok(mb_strlen((string) (json_decode($ev['after'], true)['question_excerpt'] ?? '')) <= 120, 'question.ask holds at most 120 characters of the question');
finish();
