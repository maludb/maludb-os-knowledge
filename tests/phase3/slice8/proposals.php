<?php
/** Slice 8 proof — the Archivist's proposals (agents-feeds.md "Proof": proposals.php). */
require __DIR__ . '/lib.php';

echo "1. The Archivist proposes one of every kind\n";
$s = proposal_world();
$m = $s['manuals'];
$p = $s['policies'];
$cara = as_member(CARA);
$admin = as_member(OWNER);
[$c] = act($cara, '/bases/members/add.php', ['base' => $m, 'member' => OWNER, 'level' => 'curate']);   // the admin curates the Manuals: kn_base_curator_ids() names who is told
$since = last_activity_id();
$made = [];
$cases = [
    'failed_source'  => ['subject_type' => 'source', 'subject_id' => $s['failed'], 'payload' => ['reason' => 'failed', 'error' => 'extract_error']],
    'stale_source'   => ['subject_type' => 'base', 'subject_id' => $m, 'payload' => ['sections' => 4, 'characters' => 1200]],
    'url_changed'    => ['subject_type' => 'source', 'subject_id' => $s['url'], 'payload' => ['changed_at' => '2026-10-09', 'vanished' => false]],
    'feed_stopped'   => ['subject_type' => 'feed', 'subject_id' => $s['feed'], 'payload' => ['last_status' => 'failed', 'since' => '2026-10-01']],
    'limit_near'     => ['subject_type' => 'base', 'subject_id' => $m, 'payload' => ['used_chars' => 900000, 'limit_chars' => 1000000, 'pct' => 90]],
    'gap'            => ['subject_type' => 'question', 'subject_id' => $s['q_gap2'], 'payload' => ['cluster_key' => 'contractors-carry-over', 'question_count' => 2, 'question_ids' => [$s['q_gap1'], $s['q_gap2']], 'sample' => 'How does carry-over work for contractors?']],
    'unverified'     => ['subject_type' => 'question', 'subject_id' => $s['q_unver'], 'payload' => []],
    'review_pin'     => ['subject_type' => 'pinned_answer', 'subject_id' => $s['pin'], 'payload' => ['review_until' => '2026-09-01']],
    'merge'          => ['payload' => ['groups' => [['names' => ['Zorblat Pump', 'Zorblat Pumps'], 'suggested' => 'Zorblat Pump'], ['names' => ['Quokka Valve', 'Quokka Valves'], 'suggested' => 'Quokka Valve']]]],
    'unassigned'     => ['subject_type' => 'focus_area', 'subject_id' => $s['empty_area'], 'payload' => []],
    'area'           => ['payload' => ['name' => 'SMOKE Warranty claims', 'description' => 'How claims are made.', 'seed_terms' => ['Zorblat Pump', 'claim form'], 'example_questions' => ['How do I claim?']]],
];
foreach ($cases as $kind => $more) {
    [$c, $b] = propose($m, $kind, $more + ['title' => 'SMOKE ' . $kind]);
    $id = (int) ($b['record_id'] ?? 0);
    $made[$kind] = $id;
    $row = proposal_row($id);
    ok($c === 200 && $id > 0 && $row['status'] === 'proposed' && (int) $row['proposed_by'] === ARCHIVIST && ($row['subject_type'] ?? null) === ($more['subject_type'] ?? null)
        && str_starts_with((string) ($b['location'] ?? ''), '/bases/' . $m . '/proposals'), "$kind proposed" . (isset($more['subject_type']) ? ' about a ' . $more['subject_type'] : ' with no subject') . ($c === 200 ? '' : ' — ' . $c . ' ' . json_msg($b)));
}
[$c, $b] = propose($m, 'failed_source', ['subject_type' => 'source', 'subject_id' => $s['no_text'], 'payload' => ['reason' => 'no_text']]);
$made['no_text'] = (int) ($b['record_id'] ?? 0);
ok($c === 200, 'a source with no text → failed_source with reason no_text');
[$c, $b] = propose($m, 'gap', ['subject_type' => 'question', 'subject_id' => $s['q_down'], 'payload' => ['reason' => 'thumbs_down', 'has_comment' => true]]);
$made['thumbs'] = (int) ($b['record_id'] ?? 0);
ok($c === 200, 'a thumbs-down answer → gap with reason thumbs_down');
[$c, $b] = propose($m, 'unassigned', ['subject_type' => 'source', 'subject_id' => $s['plain'], 'payload' => []]);
$made['unassigned_source'] = (int) ($b['record_id'] ?? 0);
ok($c === 200, 'a source in no area → unassigned about the source');
$ev = activity('archivist.propose', $since);
ok(count($ev) === 14 && !in_log('Because the Monday report found it.') && !in_log('Zorblat Pumps') && !in_log('contractors-carry-over'), '14 archivist.propose rows (ids, kind, subject) — never the reason or the payload');
ok($ev[0]['source'] === 'agent' && (int) $ev[0]['base_id'] === $m, 'logged by the agent, with base_id');

echo "   What the database and the gate refuse\n";
[$c, $b] = propose($m, 'gap', ['subject_type' => 'question', 'subject_id' => $s['q_gap2'], 'payload' => ['sample' => 'again']]);
ok($c === 422 && json_msg($b) === 'Already proposed — an open proposal for this exists.', 'a second open gap for the same newest question → 422 "' . json_msg($b) . '"');
[$c, $b] = propose($m, 'gap', ['subject_type' => 'question', 'subject_id' => $s['q_gap1'], 'payload' => ['sample' => 'another cluster']]);
ok($c === 200, 'a gap cluster with another newest question is open beside it');
[$c, $b] = propose($m, 'merge', ['payload' => ['groups' => [['names' => ['A b', 'A-b']]]]]);
ok($c === 422 && str_contains(json_msg($b), 'Already proposed'), 'a second open merge → 422');
[$c, $b] = propose($m, 'gap', ['subject_type' => 'question', 'subject_id' => $s['q_other'], 'payload' => []]);
ok($c === 422 && json_msg($b) === 'That subject is not in this base.', 'a question of the Policies on a Manuals proposal → 422 "' . json_msg($b) . '"');
[$c, $b] = propose($m, 'review_pin', ['subject_type' => 'source', 'subject_id' => $s['failed'], 'payload' => []]);
ok($c === 422 && str_contains(json_msg($b), 'pinned answer'), 'a subject of the wrong type for the kind → 422: "' . json_msg($b) . '"');
[$c, $b] = propose($m, 'sky_falling', ['payload' => []]);
ok($c === 422, 'an unknown kind → 422');
[$c, $b] = propose($m, 'area', ['payload' => [], 'title' => str_repeat('x', 301)]);
ok($c === 422 && str_contains(json_msg($b), '300'), 'a title over 300 characters → 422');
[$c, $b] = as_archivist('/proposals/save.php', ['base' => $m, 'kind' => 'area', 'title' => 'SMOKE big', 'reason' => 'r', 'payload' => json_encode(['x' => str_repeat('y', 17000)])]);
ok($c === 422 && str_contains(json_msg($b), '16 KB'), 'a payload over 16 KB → 422');
[$c, $b] = as_archivist('/proposals/save.php', ['base' => $m, 'kind' => 'area', 'title' => 'SMOKE bad', 'reason' => 'r', 'payload' => '[1,2]']);
ok($c === 422 && isset(fields($b)['payload']), 'a payload that is not an object → 422 on the field');
[$c, $b] = as_archivist('/proposals/save.php', ['base' => 999999, 'kind' => 'area', 'title' => 't', 'reason' => 'r']);
ok($c === 404, 'an unknown base → 404');
$cpriya = act(as_member(PRIYA), '/proposals/save.php', ['base' => $m, 'kind' => 'area', 'title' => 't', 'reason' => 'r', 'payload' => '{}']);
ok($cpriya[0] === 403, 'Priya (a reader) may not propose → 403');
[$c, $b] = act($cara, '/proposals/save.php', ['base' => $m, 'kind' => 'url_changed', 'subject_type' => 'source', 'subject_id' => $s['plain'], 'title' => 'SMOKE Cara\'s own', 'reason' => 'A curator may too.', 'payload' => '{"vanished":true}']);
ok($c === 200, 'a curator of the base may propose (the gate is the reach)');
$outside = act_token('/proposals/save.php', ['base' => (int) base_by_key('smoke-empty8'), 'kind' => 'area', 'title' => 't', 'reason' => 'r', 'payload' => '{}'], as_agent(run_token(ARCHIVIST, 7802)));
ok($outside[0] === 404, 'a base the Archivist does not read → 404 (it reaches the bases shared with it only)');
[$c, $b] = act($cara, '/proposals/save.php', ['base' => (int) base_by_key('smoke-empty8'), 'kind' => 'area', 'title' => 't', 'reason' => 'r', 'payload' => '{}']);
ok($c === 200, '(control: the empty base is the curator\'s own)');

echo "   Who is told, who sees\n";
$told = static fn (int $member): int => (int) one("SELECT count(*) FROM notifications WHERE member_id = :m AND kind = 'proposal' AND record_type = 'archivist_proposal' AND title LIKE 'Archivist: SMOKE%'", ['m' => $member]);
ok($told(CARA) >= 14 && $told(OWNER) >= 14, 'Cara and the admin are told (proposal): ' . $told(CARA) . ' and ' . $told(OWNER));
ok($told(PRIYA) === 0 && $told(REID) === 0 && told(ARCHIVIST, 'proposal') === 0, 'Priya, Reid and the Archivist are not');
ok(proposals_visible_to(PRIYA) === 0 && proposals_visible_to(REID) === 0 && proposals_visible_to(ARCHIVIST) === 0 && proposals_visible_to(CARA) >= 14, 'the view shows none of it to Priya, Reid or the Archivist; Cara sees them');
[$c, $b] = screen(as_member(PRIYA), '/bases/' . $m . '/proposals');
ok($c === 403 && str_contains(msg(['error' => ['message' => ($b['error']['message'] ?? '')]]) . page(as_member(PRIYA), '/bases/' . $m . '/proposals')['body'], 'You may not change this knowledge base'), 'Priya gets 403 on the page, in words');
[$c, $d] = screen($cara, '/bases/' . $m . '/proposals');
ok($c === 200 && count($d['proposals']) >= 14 && $d['counts']['proposed'] >= 14 && isset($d['proposals'][0]['accept']), 'Cara\'s screen (JSON): the proposals with what Accept does');
$h = page($cara, '/bases/' . $m . '/proposals')['body'];
$allKinds = true;
foreach (['area', 'merge', 'gap', 'stale_source', 'failed_source', 'review_pin', 'unassigned', 'unverified', 'feed_stopped', 'limit_near', 'url_changed'] as $k) { $allKinds = $allKinds && str_contains($h, '-kind"'); }
ok(str_contains($h, 'id="proposal-' . $made['merge'] . '"') && str_contains($h, 'id="merge-group-1"') && str_contains($h, 'id="merge-group-2"') && str_contains($h, 'id="proposal-accept-' . $made['area'] . '-name"') && str_contains($h, 'id="proposal-accept-' . $made['stale_source'] . '-estimate"'),
    'the page: a merge\'s tick boxes, an area\'s fields, a build\'s estimate');
ok(str_contains($h, 'href="/sources/' . $s['failed'] . '"') && str_contains($h, 'href="/pinned#pin-' . $s['pin'] . '"') && str_contains($h, 'href="/questions/' . $s['q_gap2'] . '"') && str_contains($h, 'href="/areas/' . $s['empty_area'] . '"'), 'each subject is a link to its screen');
$ad = page($admin, '/admin/proposals')['body'];
ok(str_contains($ad, 'SMOKE merge') && str_contains($ad, 'id="proposal-' . $made['area'] . '-base"') && str_contains($ad, 'merge-group-' . $made['merge'] . '-1'), 'the admin\'s page lists every base\'s proposals, the base named on each');
ok(page(as_member(PRIYA), '/admin/proposals')['code'] === 403, 'a Member may not open the admin\'s page');

echo "2. Dismissed, and not proposed again\n";
$old = (int) $made['unassigned_source'];
$d1 = (int) $made['no_text'];
$since = last_activity_id();
[$c, $b] = act($cara, '/proposals/dismiss.php', ['proposal' => $d1, 'note' => 'Nothing we can do with a scan.']);
$row = proposal_row($d1);
ok($c === 200 && $row['status'] === 'dismissed' && (int) $row['decided_by'] === CARA && proposal_payload($d1)['decision_note'] === 'Nothing we can do with a scan.' && ($row['payload'] !== null), 'dismiss with a note → dismissed, payload.decision_note, decided by Cara');
$dl = activity('archivist.dismiss', $since);
ok(count($dl) === 1 && (after_of($dl[0])['has_note'] ?? null) === true && !in_log('Nothing we can do with a scan'), 'archivist.dismiss logged (has_note true) — never the note');
[$c, $b] = act($cara, '/proposals/dismiss.php', ['proposal' => $d1]);
ok($c === 422 && fields($b) !== [] || ($c === 422 && str_contains(msg($b), 'stays dismissed')), 'deciding twice → 422: "' . msg($b) . '" ' . json_encode(fields($b)));
[$c, $b] = act($cara, '/proposals/dismiss.php', ['proposal' => $old, 'note' => str_repeat('n', 501)]);
ok($c === 422 && isset(fields($b)['note']), 'a note over 500 characters → 422 on the field');
[$c] = act(as_member(PRIYA), '/proposals/dismiss.php', ['proposal' => $old]);
ok($c === 403, 'Priya may not dismiss → 403');
[$c] = act_token('/proposals/dismiss.php', ['proposal' => $old], as_agent(run_token(ARCHIVIST, 7803)));
ok($c === 403, 'the Archivist may not dismiss (people only) → 403');
pdo()->exec("UPDATE archivist_proposals SET status = 'dismissed', decided_at = now() - interval '10 days', decided_by = " . CARA . " WHERE id = $old");
pdo()->exec("UPDATE archivist_proposals SET status = 'dismissed', decided_at = now() - interval '100 days', decided_by = " . CARA . " WHERE id = {$made['unverified']}");
$rd = json_decode(php8('echo json_encode(array_column(recently_dismissed($pdo, ' . $m . '), "proposal_id"));'), true);
ok(is_array($rd) && in_array($old, $rd, true) && in_array($d1, $rd, true) && !in_array($made['unverified'], $rd, true), 'recently_dismissed(): a dismissal of 10 days ago is listed, one of 100 is not (' . json_encode($rd) . ')');
$rd30 = json_decode(php8('echo json_encode(array_column(recently_dismissed($pdo, ' . $m . ', 5), "proposal_id"));'), true);
ok(is_array($rd30) && !in_array($old, $rd30, true) && in_array($d1, $rd30, true), 'the window is a parameter');
[$c, $b] = propose($m, 'unverified', ['subject_type' => 'question', 'subject_id' => $s['q_unver'], 'payload' => []]);
ok($c === 200, 'what was dismissed 100 days ago may be proposed again (the one-open index only)');
finish();
