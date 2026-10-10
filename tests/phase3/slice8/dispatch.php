<?php
/** Slice 8 proof — the expert, woken after the first build; asked by a person; drafts (agents-feeds.md "Proof": dispatch.php). */
require __DIR__ . '/lib.php';

echo "1. Woken after the first build\n";
$w = agents_world();
$m = $w['manuals'];
$p = $w['policies'];
clear_dispatches();
$long = 'I proposed focus areas from the graph. ' . str_repeat('More detail about them. ', 20) . SENTINEL;
chat_state(['chat' => ['run_id' => 6001, 'request_id' => 'req-6001', 'status' => 'succeeded', 'finished' => true, 'reply' => $long, 'cost' => 0.0421, 'currency' => 'USD']]);
$since = last_activity_id();
$line = worker_at();
$pa = dispatches_of('propose_areas');
$dg = dispatches_of('draft_guidelines');
ok(count($pa) === 1 && count($dg) === 1 && (int) $pa[0]['base_id'] === $m && (int) $dg[0]['base_id'] === $m, 'one pass inserts propose_areas and draft_guidelines for the Manuals, and nothing for the Policies (' . count($pa) . ' + ' . count($dg) . ')');
ok((int) $pa[0]['acting_member_id'] === CARA && (int) $pa[0]['agent_member_id'] === EXPERT && $pa[0]['via'] === 'chat' && $pa[0]['record_type'] === 'base' && (int) $pa[0]['record_id'] === $m && $pa[0]['build_id'] !== null, 'acting as Cara (the base\'s owner), to the expert, via chat, record the base, the build kept');
$chats = chat_log();
$c1 = $chats[0] ?? [];
ok(count($chats) === 2 && $c1['method'] === 'POST' && $c1['agent'] === (string) EXPERT && $c1['acting'] === (string) CARA && (int) $c1['wait'] === 25 && $c1['conversation_id'] === 'kd-' . $pa[0]['id']
    && str_contains((string) $c1['utterance'], 'base id ' . $m) && str_contains((string) $c1['utterance'], 'propose-focus-areas') && ($c1['context']['entity'] ?? '') === 'base' && (int) ($c1['context']['record_id'] ?? 0) === $m, 'the kernel was called ?agent=<expert>, X-Acting-Member Cara, the utterance naming base ' . $m . ', wait 25');
ok(str_contains((string) $chats[1]['utterance'], 'draft-guidelines-from-a-sample') && str_contains((string) $chats[1]['utterance'], 'is_active false'), 'the second call is the draft-guidelines request');
$pa = dispatch_row((int) $pa[0]['id']);
ok($pa['status'] === 'answered' && (int) $pa['run_id'] === 6001 && $pa['request_id'] === 'req-6001' && mb_strlen((string) $pa['reply_excerpt']) === 200 && $pa['answered_at'] !== null, '200 finished → answered, run 6001, a 200-character excerpt');
$rep = activity('agent.reply', $since);
$ra = after_of($rep[0] ?? ['after' => '{}']);
ok(count($rep) === 2 && (float) ($ra['cost'] ?? 0) === 0.0421 && ($ra['currency'] ?? '') === 'USD' && (int) ($ra['reply_length'] ?? 0) === mb_strlen($long) && count(activity('agent.dispatch', $since)) === 2, 'agent.reply logged with the cost and the reply\'s length; agent.dispatch twice');
ok(!in_log(SENTINEL) && !in_log('More detail about them') && !in_log('propose-focus-areas'), 'no word of the reply or the utterance is in a log row');
ok(told(CARA, 'proposal') === 0 || (int) one("SELECT count(*) FROM notifications WHERE member_id = :m AND title LIKE '%proposed 0 focus%'", ['m' => CARA]) === 0, 'the expert proposed nothing → nobody told "proposed 0 focus areas"');
ok(str_contains((string) $pa['detail'], 'no focus areas'), 'the row says so: "' . $pa['detail'] . '"');
worker_at();
ok(count(dispatches_of('propose_areas')) === 1 && count(dispatches_of('draft_guidelines')) === 1 && count(chat_log()) === 2, 'a second pass inserts nothing and calls nothing');
ok($pa['status'] === 'answered' && dispatches_of('propose_areas', $p) === [], 'the Policies (areas exist, a second build) was never woken');

echo "   Three proposed areas, told once\n";
clear_dispatches();
pdo()->exec("DELETE FROM focus_areas WHERE base_id = $m");
chat_state(['chat_http' => 202, 'chat' => ['run_id' => 6002, 'status' => 'running', 'finished' => false]]);
$since = last_activity_id();
worker_at();
$pa = dispatches_of('propose_areas')[0];
ok($pa['status'] === 'sent' && (int) $pa['run_id'] === 6002, '202 → the dispatch stays sent with the run kept');
$expert = as_agent(run_token(EXPERT, 7802));
foreach (['Leave', 'Pay', 'Travel'] as $name) {
    [$c, $b] = act_token('/areas/propose.php', ['base' => $m, 'name' => 'SMOKE ' . $name, 'proposal_note' => 'A cluster of subjects about ' . strtolower($name), 'seed_terms' => $name], $expert);
    if ($c !== 200) { fwrite(STDERR, "area propose $name: $c " . json_encode($b) . "\n"); }
}
chat_state(['chat_run' => ['run_id' => 6002, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Three areas proposed.', 'cost' => 0.01, 'currency' => 'USD']]);
worker_at();
$n = (int) one("SELECT count(*) FROM notifications WHERE member_id = :m AND kind = 'proposal' AND title LIKE '%proposed 3 focus areas for%'", ['m' => CARA]);
ok(dispatch_row((int) $pa['id'])['status'] === 'answered' && $n === 1, 'the poll finishes it; Cara is told once, "proposed 3 focus areas"');
worker_at();
worker_at(at(120));
ok((int) one("SELECT count(*) FROM notifications WHERE member_id = :m AND title LIKE '%proposed 3 focus areas for%'", ['m' => CARA]) === 1, 'later passes tell nobody again');
ok(told(REID, 'proposal') === 0 && told(PRIYA, 'proposal') === 0, 'a Reader is not a curator: not told');
$title = (string) one("SELECT title FROM notifications WHERE member_id = :m AND title LIKE '%proposed 3 focus areas for%'", ['m' => CARA]);
ok(str_contains($title, '«SMOKE Manuals»'), 'the bell names the base: ' . $title);

echo "   No expert named\n";
clear_dispatches();
pdo()->exec("DELETE FROM focus_areas WHERE base_id = $m");
pdo()->exec('UPDATE kn_settings SET expert_member_id = NULL WHERE id = 1');
$since = last_activity_id();
worker_at();
worker_at();
$noexp = array_filter(activity('agent.fail', $since), static fn ($r) => (after_of($r)['detail'] ?? '') === 'no expert named');
ok(dispatches_of('propose_areas') === [] && dispatches_of('draft_guidelines') === [] && count($noexp) === 1, 'no expert named → no dispatch, agent.fail once ("no expert named")');
pdo()->exec('UPDATE kn_settings SET expert_member_id = ' . EXPERT . ' WHERE id = 1');
chat_state(['chat' => ['run_id' => 6003, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Nothing to add.']]);
worker_at();
ok(count(dispatches_of('propose_areas')) === 1, 'the expert named → the next pass wakes it');

echo "2. Asked by a person\n";
clear_dispatches();
pdo()->exec("INSERT INTO focus_areas (base_id, name, slug, description, status) VALUES ($m, 'Leave', 'leave', 'Leave rules.', 'active') ON CONFLICT DO NOTHING");     // the Manuals have an area now: the first-build step leaves it alone
$cara = as_member(CARA);
chat_state(['chat' => ['run_id' => 6010, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Done.']]);
$since = last_activity_id();
[$c, $b] = act($cara, '/agents/propose-areas.php', ['base' => $p]);
$d = dispatches_of('propose_areas', $p);
ok($c === 200 && count($d) === 1 && (int) $d[0]['acting_member_id'] === CARA && $d[0]['status'] === 'sent' && $d[0]['run_id'] === null && ($b['location'] ?? '') === '/bases/' . $p . '/areas', 'Cara\'s Propose areas on the Policies → a propose_areas dispatch acting as Cara, sent');
$req = activity('agent.dispatch', $since);
ok(count($req) === 1 && (after_of($req[0])['requested'] ?? false) === true && (after_of($req[0])['kind'] ?? '') === 'propose_areas', 'agent.dispatch logged with requested true');
[$c, $b] = act($cara, '/agents/propose-areas.php', ['base' => $p]);
ok($c === 200 && ($b['did'] ?? '') === 'The expert is already on it.' && count(dispatches_of('propose_areas', $p)) === 1, 'a second press while it is sent → "The expert is already on it." and no second row');
$h = page($cara, '/bases/' . $p . '/areas?notice=already')['body'];
ok(str_contains($h, 'id="areas-propose-btn"') && str_contains($h, 'The expert is already on it.'), 'the areas screen offers Propose areas and shows the notice');
chat_clear_log();
worker_at();
$chats = chat_log();
ok(count($chats) === 1 && $chats[0]['acting'] === (string) CARA && str_contains((string) $chats[0]['utterance'], 'base id ' . $p) && dispatches_of('propose_areas', $p)[0]['status'] === 'answered', 'the next pass calls the kernel as Cara and the row is answered');
$empty = make_base(CARA, 'SMOKE Empty', ['key' => 'smoke-empty8']);
[$c, $b] = act($cara, '/agents/propose-areas.php', ['base' => $empty]);
ok($c === 422 && str_contains(msg($b), 'Build the base first') && dispatches_of('propose_areas', $empty) === [], 'a base with no extracted section → 422: "' . msg($b) . '"');
[$c, $b] = act(as_member(PRIYA), '/agents/propose-areas.php', ['base' => $p]);
ok($c === 403, 'Priya → 403 ("' . msg($b) . '")');
[$c] = act(as_member(REID), '/agents/draft-guidelines.php', ['base' => $p]);
ok($c === 403, 'Reid → 403 on Draft guidelines too');
[$c, $b] = act($cara, '/agents/draft-guidelines.php', ['base' => $p]);
$dd = dispatches_of('draft_guidelines', $p);
ok($c === 200 && count($dd) === 1 && (int) $dd[0]['acting_member_id'] === CARA && ($b['location'] ?? '') === '/bases/' . $p . '/guidelines', 'Draft guidelines from a sample → a draft_guidelines dispatch acting as Cara');
[$c, $b] = act($cara, '/agents/draft-guidelines.php', ['base' => $p]);
ok($c === 200 && ($b['did'] ?? '') === 'The expert is already on it.' && count(dispatches_of('draft_guidelines', $p)) === 1, '…and a second press does not double it');
[$c, $b] = act($cara, '/agents/draft-guidelines.php', ['base' => $empty]);
ok($c === 422, 'a base with no extracted section → 422 on Draft guidelines too');
$g = page($cara, '/bases/' . $p . '/guidelines')['body'];
ok(str_contains($g, 'id="guidelines-draft-btn"') && !str_contains(page(as_member(PRIYA), '/bases/' . $p . '/areas')['body'], 'id="areas-propose-btn"'), 'the guidelines screen offers the draft button; a Member\'s areas screen offers no Propose areas');
[$c] = act_token('/agents/propose-areas.php', ['base' => $p], as_agent(run_token(EXPERT, 7803)));
ok($c === 403, 'an agent token is refused (people only)');

echo "3. Drafts shape nothing (divergence 3)\n";
$v0 = (int) one('SELECT guideline_version FROM knowledge_bases WHERE id = :b', ['b' => $m]);
$st0 = q("SELECT status, count(*) AS n FROM sections WHERE base_id = :b GROUP BY status ORDER BY status", ['b' => $m]);
[$c, $b] = act_token('/guidelines/save.php', ['base' => $m, 'kind' => 'extraction', 'title' => 'SMOKE Draft extraction', 'body' => 'Also extract the names of the people who sign each rule.', 'is_active' => '0'], $expert);
$gid = (int) ($b['record_id'] ?? 0);
$row = q('SELECT is_active, origin FROM guidelines WHERE id = :id', ['id' => $gid])[0] ?? [];
ok($c === 200 && $row['origin'] === 'agent' && !filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN), 'the expert\'s inactive extraction draft is saved, origin agent');
ok((int) one('SELECT guideline_version FROM knowledge_bases WHERE id = :b', ['b' => $m]) === $v0 && q("SELECT status, count(*) AS n FROM sections WHERE base_id = :b GROUP BY status ORDER BY status", ['b' => $m]) === $st0, 'guideline_version and every section are as they were');
[$c] = act_token('/guidelines/save.php', ['guideline' => $gid, 'body' => 'Also extract the names of the people who sign each rule, and the dates.'], $expert);
ok($c === 200 && (int) one('SELECT guideline_version FROM knowledge_bases WHERE id = :b', ['b' => $m]) === $v0, 'editing the draft still shapes nothing');
[$c, $b] = act($cara, '/guidelines/save.php', ['guideline' => $gid, 'is_active' => '1']);
$stale = (int) one("SELECT count(*) FROM sections WHERE base_id = :b AND status = 'stale'", ['b' => $m]);
ok($c === 200 && (int) one('SELECT guideline_version FROM knowledge_bases WHERE id = :b', ['b' => $m]) === $v0 + 1 && $stale > 0, 'Cara activating it bumps the version and marks ' . $stale . ' sections stale');
finish();
