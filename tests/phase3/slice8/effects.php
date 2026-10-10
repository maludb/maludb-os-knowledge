<?php
/** Slice 8 proof — what accepting does (agents-feeds.md "Proof": effects.php). */
require __DIR__ . '/lib.php';

$s = proposal_world();
$m = $s['manuals'];
$cara = as_member(CARA);
kb_state(['projects_fake' => true, 'config' => ['model_identifier' => 'fake-model', 'base_url' => 'http://127.0.0.1:9', 'embedding_model' => 'fake-embed', 'secret_ref' => 'fake-secret', 'model_alias' => 'knowledge-extract']]);
/** A fresh open proposal of a kind on the Manuals (any open one of the kind is set aside first): its id. */
$fresh = static function (string $kind, array $more = []) use ($m): int {
    pdo()->exec("UPDATE archivist_proposals SET status = 'dismissed', decided_at = now() WHERE base_id = $m AND kind = '$kind' AND status = 'proposed'");
    [$c, $b] = propose($m, $kind, $more + ['payload' => []]);
    if ($c !== 200) { fwrite(STDERR, "fresh $kind: $c " . json_encode($b) . "\n"); }
    return (int) ($b['record_id'] ?? 0);
};
$accept = static fn (int $id, array $form = []): array => act($cara, '/proposals/accept.php', ['proposal' => $id] + $form);
$is = static fn (int $id): string => (string) proposal_row($id)['status'];

echo "1. An area\n";
$id = $fresh('area', ['payload' => ['name' => 'SMOKE Warranty claims', 'description' => 'How claims are made.', 'seed_terms' => ['Zorblat Pump', 'claim form'], 'example_questions' => ['How do I claim?']]]);
$since = last_activity_id();
[$c, $b] = $accept($id, ['name' => 'SMOKE Warranty claims', 'description' => 'How claims are made, edited.', 'seed_terms' => "Zorblat Pump\nclaim form\nreceipt", 'example_questions' => "How do I claim?\nWhat is covered?"]);
$aid = (int) ($b['effect_record_id'] ?? 0);
$a = q('SELECT * FROM focus_areas WHERE id = :id', ['id' => $aid])[0] ?? [];
ok($c === 200 && $is($id) === 'accepted' && ($a['status'] ?? '') === 'active' && $a['name'] === 'SMOKE Warranty claims' && $a['description'] === 'How claims are made, edited.' && $a['seed_terms'] === '{"Zorblat Pump","claim form",receipt}', 'accept area → an active area with the terms as edited (' . ($a['seed_terms'] ?? '?') . ')');
ok(($b['effect'] ?? '') === 'create_area' && str_ends_with((string) ($b['location'] ?? ''), '/areas/' . $aid) && ($b['engine_synced'] ?? null) === true && $a['maludb_project_id'] !== null, 'the fake engine made its project (' . ($a['maludb_project_id'] ?? 'none') . '); the answer lands on the area');
$ac = activity('archivist.accept', $since);
ok(count($ac) === 1 && after_of($ac[0])['effect'] === 'create_area' && count(activity('focus_area.save', $since)) === 1 && !in_log('How claims are made'), 'archivist.accept and focus_area.save logged — no description');
$id2 = $fresh('area', ['payload' => ['name' => 'SMOKE Warranty claims', 'description' => 'again', 'seed_terms' => [], 'example_questions' => []]]);
[$c, $b] = $accept($id2);
ok($c === 422 && isset(fields($b)['name']) && $is($id2) === 'proposed', 'a name the base has → 422 on the field, the proposal stays open: "' . (fields($b)['name'] ?? '') . '"');
[$c, $b] = $accept($id2, ['name' => '']);
ok($c === 422 && isset(fields($b)['name']), 'an empty name → 422 on the field');
[$c, $b] = $accept($id2, ['name' => 'SMOKE Warranty claims 2']);
ok($c === 200 && $is($id2) === 'accepted', '…edited in the form, it is accepted');

echo "2. Duplicate subjects\n";
act($cara, '/vocabulary/save.php', ['base' => $m, 'canonical_name' => 'Zorblat Pump', 'aliases' => 'ZP-9']);
$id = $fresh('merge', ['payload' => ['groups' => [['names' => ['Zorblat Pump', 'Zorblat Pumps'], 'suggested' => 'Zorblat Pump'], ['names' => ['Quokka Valve', 'Quokka Valves'], 'suggested' => 'Quokka Valve']]]]);
$v0 = (int) one('SELECT guideline_version FROM knowledge_bases WHERE id = :b', ['b' => $m]);
$since = last_activity_id();
[$c, $b] = $accept($id, ['groups_sent' => 1, 'groups' => ['1']]);
$t = q("SELECT * FROM vocabulary_terms WHERE base_id = :b AND canonical_name = 'Zorblat Pump'", ['b' => $m])[0] ?? [];
ok($c === 200 && $is($id) === 'accepted' && str_contains((string) $t['aliases'], 'Zorblat Pumps') && str_contains((string) $t['aliases'], 'ZP-9'), 'one of two groups ticked → "Zorblat Pump" gains "Zorblat Pumps" (' . $t['aliases'] . ')');
ok((int) one('SELECT guideline_version FROM knowledge_bases WHERE id = :b', ['b' => $m]) === $v0 + 1 && (int) one("SELECT count(*) FROM vocabulary_terms WHERE base_id = :b AND canonical_name = 'Quokka Valve'", ['b' => $m]) === 0, 'the base is marked for a rebuild (version + 1); the unticked group is untouched');
ok(count(activity('vocabulary.save', $since)) === 1 && count(activity('guideline.version', $since)) === 1 && count(activity('archivist.accept', $since)) === 1, 'vocabulary.save, guideline.version and archivist.accept logged');
$id = $fresh('merge', ['payload' => ['groups' => [['names' => ['Quokka Valve', 'Quokka Valves'], 'suggested' => 'Quokka Valve', 'subject_type' => 'part']]]]);
[$c, $b] = $accept($id, ['groups_sent' => 1]);
ok($c === 422 && isset(fields($b)['proposal']) && $is($id) === 'proposed', 'no group ticked → 422 and the proposal stays open: "' . (fields($b)['proposal'] ?? '') . '"');
[$c, $b] = $accept($id);
$q = q("SELECT * FROM vocabulary_terms WHERE base_id = :b AND canonical_name = 'Quokka Valve'", ['b' => $m])[0] ?? [];
ok($c === 200 && ($q['subject_type'] ?? '') === 'part' && str_contains((string) ($q['aliases'] ?? ''), 'Quokka Valves'), 'every group (none sent at all, as an agent\'s call would) → a new term is made with its type');

echo "3. A build\n";
pdo()->exec("UPDATE sections SET status = 'stale' WHERE base_id = $m AND status = 'extracted'");
$id = $fresh('stale_source', ['subject_type' => 'base', 'subject_id' => $m, 'payload' => ['sections' => 4, 'characters' => 1200]]);
$since = last_activity_id();
[$c, $b] = $accept($id);
$bid = (int) ($b['effect_record_id'] ?? 0);
$bd = q('SELECT * FROM builds WHERE id = :id', ['id' => $bid])[0] ?? [];
ok($c === 200 && $is($id) === 'accepted' && ($b['effect'] ?? '') === 'start_build' && ($bd['status'] ?? '') === 'queued' && ($b['location'] ?? '') === '/builds/' . $bid, 'accept stale_source → a build queued, the answer lands on it');
$bs = activity('build.start', $since);
ok(count($bs) === 1 && (int) (after_of($bs[0])['build_id'] ?? 0) === $bid && (after_of($bs[0])['auto'] ?? null) === false, 'build.start logged');
$id = $fresh('stale_source', ['subject_type' => 'base', 'subject_id' => $m, 'payload' => []]);
[$c, $b] = $accept($id);
ok($c === 422 && ($b['error']['fields']['proposal'] ?? '') === 'A build is already running.' && $is($id) === 'proposed', 'again while it runs → the proposal stays open: "A build is already running."');
pdo()->exec("UPDATE builds SET status = 'cancelled' WHERE id = $bid");
pdo()->exec("UPDATE knowledge_bases SET monthly_text_mb_limit = 1 WHERE id = $m");
pdo()->exec("INSERT INTO ai_usage (base_id, month, kind, calls, chars) VALUES ($m, date_trunc('month', now())::date, 'extract', 1, 5242880) ON CONFLICT (base_id, month, kind) DO UPDATE SET chars = 5242880");
[$c, $b] = $accept($id);
ok($c === 422 && ($b['error']['fields']['proposal'] ?? '') === 'This month\'s text limit for this base is spent.' && $is($id) === 'proposed', 'over the month\'s limit → the build screen\'s words, the proposal open: "' . ($b['error']['fields']['proposal'] ?? '') . '"');
pdo()->exec("DELETE FROM ai_usage WHERE base_id = $m");
pdo()->exec("UPDATE knowledge_bases SET monthly_text_mb_limit = 100 WHERE id = $m");
pdo()->exec("UPDATE sections SET status = 'extracted' WHERE base_id = $m AND status = 'stale'");
[$c, $b] = $accept($id);
ok($c === 200 && ($b['effect'] ?? '') === 'none' && $is($id) === 'accepted', 'nothing waiting for a build any more → accepted, no build');
kb_state(['config' => null]);
pdo()->exec("UPDATE sections SET status = 'stale' WHERE base_id = $m AND status = 'extracted'");
$id = $fresh('stale_source', ['subject_type' => 'base', 'subject_id' => $m, 'payload' => []]);
[$c, $b] = $accept($id);
ok($c === 422 && str_contains((string) ($b['error']['fields']['proposal'] ?? ''), 'model is not set up') && $is($id) === 'proposed', 'the engine\'s model not set up → refused in the build screen\'s words, open');
kb_state(['config' => ['model_identifier' => 'fake-model', 'base_url' => 'http://127.0.0.1:9', 'embedding_model' => 'fake-embed', 'secret_ref' => 'fake-secret', 'model_alias' => 'knowledge-extract']]);
pdo()->exec("UPDATE sections SET status = 'extracted' WHERE base_id = $m AND status = 'stale'");

echo "4. A pin, a feed, a source\n";
pdo()->exec("UPDATE pinned_answers SET review_until = current_date - 30 WHERE id = {$s['pin']}");
$id = $fresh('review_pin', ['subject_type' => 'pinned_answer', 'subject_id' => $s['pin'], 'payload' => ['review_until' => '2026-09-01']]);
$since = last_activity_id();
[$c, $b] = $accept($id);
$until = (string) one('SELECT review_until FROM pinned_answers WHERE id = :id', ['id' => $s['pin']]);
ok($c === 200 && $is($id) === 'accepted' && $until > date('Y-m-d') && count(activity('question.pin', $since)) === 1 && (after_of(activity('question.pin', $since)[0])['review'] ?? false) === true, 'accept review_pin → review_until moved to ' . $until . ', question.pin logged');
pdo()->exec("UPDATE feeds SET next_sync_at = now() + interval '5 days' WHERE id = {$s['feed']}");
$id = $fresh('feed_stopped', ['subject_type' => 'feed', 'subject_id' => $s['feed'], 'payload' => ['last_status' => 'failed']]);
$since = last_activity_id();
[$c, $b] = $accept($id);
ok($c === 200 && strtotime((string) feed_row($s['feed'])['next_sync_at']) <= time() + 2 && count(activity('feed.sync', $since)) === 1 && (after_of(activity('feed.sync', $since)[0])['requested'] ?? false) === true, 'accept feed_stopped → the feed is due now, feed.sync logged (requested)');
$id = $fresh('failed_source', ['subject_type' => 'source', 'subject_id' => $s['failed'], 'payload' => ['reason' => 'failed']]);
$since = last_activity_id();
[$c, $b] = $accept($id);
ok($c === 200 && (string) one('SELECT status FROM sources WHERE id = :id', ['id' => $s['failed']]) === 'uploaded' && count(activity('source.retry', $since)) === 1, 'accept failed_source → the source goes back in the queue (source.retry)');
pdo()->exec("UPDATE sources SET status = 'failed' WHERE id = {$s['failed']}");
$id = $fresh('failed_source', ['subject_type' => 'source', 'subject_id' => $s['no_text'], 'payload' => ['reason' => 'no_text']]);
[$c, $b] = $accept($id);
ok($c === 200 && ($b['effect'] ?? '') === 'open_source' && ($b['location'] ?? '') === '/sources/' . $s['no_text'], 'accept a source with no text → accepted, lands on the source (Replace / Remove there)');

echo "5. Kinds that land on a screen\n";
$lands = [
    ['url_changed', ['subject_type' => 'source', 'subject_id' => $s['url'], 'payload' => ['vanished' => false]], '/sources/' . $s['url'] . '/replace'],
    ['limit_near', ['subject_type' => 'base', 'subject_id' => $m, 'payload' => ['pct' => 91]], '/bases/' . $m . '/settings'],
    ['gap', ['subject_type' => 'question', 'subject_id' => $s['q_gap2'], 'payload' => ['sample' => 'x']], '/sources/new?base=' . $m],
    ['unverified', ['subject_type' => 'question', 'subject_id' => $s['q_unver'], 'payload' => []], '/questions/' . $s['q_unver']],
    ['unassigned', ['subject_type' => 'focus_area', 'subject_id' => $s['empty_area'], 'payload' => []], '/areas/' . $s['empty_area']],
    ['unassigned', ['subject_type' => 'source', 'subject_id' => $s['plain'], 'payload' => []], '/sources/' . $s['plain']],
];
foreach ($lands as [$kind, $more, $where]) {
    $id = $fresh($kind, $more);
    [$c, $b] = $accept($id);
    ok($c === 200 && $is($id) === 'accepted' && ($b['location'] ?? '') === $where, "accept $kind → accepted, lands on $where");
}
pdo()->exec("UPDATE archivist_proposals SET status = 'dismissed', decided_at = now() WHERE base_id = $m AND kind = 'gap' AND status = 'proposed'");
$id = $fresh('gap', ['subject_type' => 'question', 'subject_id' => $s['q_down'], 'payload' => ['reason' => 'thumbs_down']]);
[$c, $b] = $accept($id);
ok($c === 200 && ($b['location'] ?? '') === '/questions/' . $s['q_down'], 'accept a thumbs-down gap → lands on the question');
// the browser's way: a redirect, with the effect already done
$id = $fresh('limit_near', ['subject_type' => 'base', 'subject_id' => $m, 'payload' => []]);
$r = act_html($cara, '/proposals/accept.php', ['proposal' => $id]);
ok($r['code'] === 302 && str_starts_with($r['location'], '/bases/' . $m . '/settings'), 'a browser is redirected to the screen');

echo "6. Twice, and who may\n";
[$c, $b] = $accept($id);
ok($c === 422 && ($b['error']['fields']['proposal'] ?? '') === 'A decided proposal stays accepted.', 'deciding twice → the trigger\'s words: "' . ($b['error']['fields']['proposal'] ?? '') . '"');
$dm = proposal_row($made = (int) one("SELECT id FROM archivist_proposals WHERE base_id = :b AND status = 'dismissed' ORDER BY id DESC LIMIT 1", ['b' => $m]));
[$c, $b] = $accept((int) $dm['id']);
ok($c === 422 && ($b['error']['fields']['proposal'] ?? '') === 'A decided proposal stays dismissed.', 'accepting a dismissed one → "A decided proposal stays dismissed."');
$id = $fresh('limit_near', ['subject_type' => 'base', 'subject_id' => $m, 'payload' => []]);
[$c] = act(as_member(PRIYA), '/proposals/accept.php', ['proposal' => $id]);
ok($c === 403 && $is($id) === 'proposed', 'Priya → 403, still open');
[$c] = act(as_member(REID), '/proposals/accept.php', ['proposal' => $id]);
ok($c === 403, 'Reid → 403');
[$c] = act_token('/proposals/accept.php', ['proposal' => $id], as_agent(run_token(ARCHIVIST, 7804)));
ok($c === 403 && $is($id) === 'proposed', 'the Archivist never accepts → 403');
[$c, $b] = act($cara, '/proposals/accept.php', ['proposal' => 999999]);
ok($c === 404, 'an unknown proposal → 404');
// a curate share without questions.review: the right is the gate
$bea = as_member(BEA);
act($cara, '/bases/members/add.php', ['base' => $m, 'member' => BEA, 'level' => 'curate']);
[$c, $b] = act($bea, '/proposals/accept.php', ['proposal' => $id]);
ok($c === 403, 'a curate share holding only the Member role may not decide ("' . msg($b) . '")');
act($cara, '/bases/members/remove.php', ['base' => $m, 'base_member' => (int) one("SELECT id FROM base_members WHERE base_id = :b AND principal_id = :m AND principal_kind = 'member'", ['b' => $m, 'm' => BEA])]);
ok((int) one("SELECT count(*) FROM base_members WHERE base_id = :b AND principal_id = :m", ['b' => $m, 'm' => BEA]) === 0, '(Bea\'s share removed again)');
finish();
