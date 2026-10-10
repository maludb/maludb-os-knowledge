<?php
/** Slice 9 proof — the home (home-admin.md "Proof": home.php). */
require __DIR__ . '/lib.php';

echo "1. First run — nothing is shared yet\n";
$h = html_of(BEA, '/');
ok(str_contains($h, 'id="home-first-run"') && substr_count($h, 'id="home-create-base-btn"') === 1 && str_contains($h, 'Create a knowledge base') && str_contains($h, 'Create a base · Add documents · Ask'), 'a new Member with no base: one card, one button "Create a knowledge base", the three steps in muted text');
ok(!str_contains($h, 'id="home-bases"') && !str_contains($h, 'id="home-recent"') && !str_contains($h, 'id="home-pins"') && !str_contains($h, 'id="home-note"') && !str_contains($h, 'id="home-builds"') && !str_contains($h, 'id="home-admin"'), '…and nothing else on the page');
[$c, $d] = data_of(BEA, '/');
ok($c === 200 && $d['first_run'] === true && $d['bases'] === [] && $d['builds'] === null && $d['gaps'] === null && $d['admin'] === null && $d['recent_questions'] === [] && $d['may']['create'] === true, 'JSON: first_run true, no bases, the curator and admin regions null');
$h = html_of(ANN, '/');
ok(str_contains($h, 'Nothing has been shared with you yet.') && str_contains($h, 'id="home-nothing-shared"') && !str_contains($h, 'id="home-create-base-btn"'), 'a guest with none: "Nothing has been shared with you yet." and no button');
$h = html_of(REID, '/');
ok(str_contains($h, 'id="home-nothing-shared"') && !str_contains($h, 'id="home-create-base-btn"'), 'a Reader (no bases.create) with none: told nothing has been shared');
[$c, $d] = data_of(OWNER, '/');
ok($c === 200 && $d['first_run'] === true && $d['admin'] === null, 'the admin with no base at all sees the first-run card too');

echo "2. Priya — a Reader of two bases\n";
$w = home_world();
$hb = $w['handbook']; $pol = $w['policies']; $emp = $w['empty']; $pv = $w['private'];
$long = str_repeat('How does the carry-over of leave work for a contractor who joined mid-year ', 2) . SENTINEL . "\nA second line that is never shown.";
$ids = [];
foreach ([1, 2, 3, 4, 5] as $i) { $ids[$i] = old_question($hb, PRIYA, "Question $i about leave", (7 - $i) * 60); }
$ids[6] = old_question($hb, PRIYA, $long, 10);
$oldest = old_question($pol, PRIYA, 'The oldest question of all', 9000);
old_question($hb, BEA, 'A question of Bea\'s, not Priya\'s', 5);
as_member(PRIYA);                                   // signed on already: the sign-on writes its own rows
$since = last_activity_id();
$h = html_of(PRIYA, '/');
$rows = activity('screen.view', $since);
ok(count($rows) === 1 && $rows[0]['screen'] === 'home' && (int) one('SELECT count(*) FROM activity_log WHERE id > :s', ['s' => $since]) === 1, 'the home logs screen.view and writes nothing else');
ok(str_contains($h, 'id="home-bases"') && str_contains($h, 'id="home-base-' . $hb . '"') && str_contains($h, 'id="home-base-' . $pol . '"') && !str_contains($h, 'id="home-base-' . $pv . '"') && !str_contains($h, 'id="home-base-' . $emp . '"'), 'her bases: the two internal ones, never the private or the empty one');
ok(!str_contains($h, 'id="home-first-run"') && !str_contains($h, 'id="home-builds"') && !str_contains($h, 'id="home-gaps"') && !str_contains($h, 'id="home-admin"'), 'no first-run card, and no curator or admin region');
$pos = static fn (string $id): int => (int) strpos($h, 'id="' . $id . '"');
ok($pos('home-bases') < $pos('home-recent') && $pos('home-recent') < $pos('home-pins') && $pos('home-pins') < $pos('home-note'), 'the order on a phone: My bases · My recent questions · Pins due · the Archivist\'s note');
[$c, $d] = data_of(PRIYA, '/');
ok($c === 200 && count($d['bases']) === 2 && $d['first_run'] === false && $d['builds'] === null && $d['gaps'] === null && $d['admin'] === null && $d['may']['create'] === true && $d['may']['admin'] === false, 'JSON: two bases; builds, gaps and admin are null');
$rq = $d['recent_questions'];
ok(count($rq) === 5 && $rq[0]['question'] !== '' && !in_array('The oldest question of all', array_column($rq, 'question'), true) && !in_array('A question of Bea\'s, not Priya\'s', array_column($rq, 'question'), true), 'her five recent questions, newest first — not the oldest, not Bea\'s');
ok(mb_strlen($rq[0]['question']) <= 120 && !str_contains($rq[0]['question'], "\n") && !str_contains(json_encode($d), SENTINEL) && !str_contains($h, SENTINEL), 'the first line of a question is cut to 120 characters — the rest never leaves the database here');
ok($d['pins_due'] === [] && $d['archivist_note'] === null && str_contains($h, 'id="home-pins-none"') && str_contains($h, 'id="home-note-none"'), 'an empty region says what will appear there');
ok(str_contains($h, 'id="home-question-' . $ids[3] . '-link"') && str_contains($h, 'href="/questions/' . $ids[3]), 'a question links to its question-view');
[$c, $d] = data_of(REID, '/');
ok($c === 200 && count($d['bases']) === 2 && $d['gaps'] === null && $d['builds'] === null, 'Reid (a Reader) sees the same two bases and no curator region');

echo "3. Cara — a curator\n";
$h = html_of(CARA, '/');
ok(str_contains($h, 'id="home-base-' . $emp . '-add"') && str_contains($h, 'href="/sources/new?base=' . $emp . '"') && !str_contains($h, 'id="home-base-' . $hb . '-add"'), 'an empty base\'s card says Add documents (→ /sources/new?base=); a base with sources does not');
ok(str_contains($h, 'id="home-builds"') && str_contains($h, 'id="home-builds-none"') && !str_contains($h, 'hx-get="/home/builds" hx-trigger="every 5s'), 'Builds running: empty, and not polling when nothing runs');
ok(str_contains($h, 'id="home-gaps"') && str_contains($h, 'id="home-gaps-none"') && !str_contains($h, 'id="home-admin"'), 'Gaps this week for a curator (empty so far); no admin region');
// a pin due (Cara\'s), the Archivist\'s note, gaps and a thumbs-down, a running build
$qa = old_question($hb, PRIYA, 'How many sick days are there?', 300);
$pin = (int) one('SELECT kn_pin_answer(:q, :m, :t)', ['q' => $qa, 'm' => CARA, 't' => 'SMOKE Sick days pin']);
pdo()->exec("UPDATE pinned_answers SET review_until = current_date - 3 WHERE id = $pin");
$note = (int) one("SELECT kn_notify(:m, 'monday_note', :t, :b, 'base', :bid, :d)", ['m' => CARA, 't' => 'SMOKE Monday note: Handbook', 'b' => "- 2 sources failed to read\n- 1 build failed\n- 3 questions found nothing\n- a fourth line " . SENTINEL, 'bid' => $hb, 'd' => 'smoke-monday-9']);
foreach (['How do contractors carry over leave?', 'Do contractors carry over leave days?', 'Contractors and their leave carry-over'] as $i => $words) { old_question($hb, PRIYA, $words, 100 + $i, 'no_evidence'); }
pdo()->prepare("INSERT INTO question_feedback (question_id, member_id, rating, comment) VALUES (:q, :m, 'down', 'Not what I asked')")->execute(['q' => $qa, 'm' => PRIYA]);
$failed = running_build($pol, 2, 0);
finish_build($failed, 'failed');
$build = running_build($hb, 3, 1);
$h = html_of(CARA, '/');
ok(str_contains($h, 'id="home-pin-' . $pin . '"') && str_contains($h, 'SMOKE Sick days pin'), 'Pins due for review: her pin past its date');
[$c, $d] = data_of(PRIYA, '/');
ok($d['pins_due'] === [], '…and not Priya\'s home (she neither owns it nor curates the base)');
[$c, $d] = data_of(CARA, '/');
ok(count($d['builds']) === 1 && $d['builds'][0]['build_id'] === $build && $d['builds'][0]['percent'] === 33 && $d['builds'][0]['eta_seconds'] > 0, 'Builds running: the build with its percent (33) and an ETA — kn_build_progress()\'s');
ok(str_contains($h, 'id="home-build-' . $build . '"') && str_contains($h, '33 %') && str_contains($h, 'hx-get="/home/builds"') && str_contains($h, 'every 5s'), '…drawn with its progress bar, polling every 5 s');
ok($d['archivist_note']['title'] === 'SMOKE Monday note: Handbook' && count($d['archivist_note']['lines']) === 3 && str_contains($d['archivist_note']['lines'][0], '2 sources failed') && !str_contains(json_encode($d['archivist_note']), SENTINEL), 'the Archivist\'s note: its title and first three lines');
ok(str_contains($h, 'id="home-note-link"') && str_contains($h, '/notifications#notification-' . $note), '…linking to the notification');
ok(count($d['gaps']) === 1 && (int) $d['gaps'][0]['base_id'] === $hb && $d['gaps'][0]['thumbs_down'] === 1 && count($d['gaps'][0]['clusters']) >= 1 && $d['gaps'][0]['clusters'][0]['question_count'] >= 3, 'the week\'s gaps: the cluster of three contractor questions and one answer rated down');
ok(str_contains($h, 'id="home-gap-' . $hb . '"') && str_contains($h, 'href="/bases/' . $hb . '/gaps?since=7"') && str_contains($h, 'id="home-gap-' . $hb . '-down"'), '…the base links to its gaps page, with the thumbs-down count');
ok($d['admin'] === null, 'a curator has no admin region (the key is null)');

echo "4. The builds region polls, and stops\n";
$r = req('GET', '/home/builds', ['jar' => as_member(CARA), 'headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="home-build-' . $build . '"') && str_contains($r['body'], 'every 5s') && !str_contains($r['headers'], 'baseChanged'), 'while the build runs the endpoint answers 200 with the region, still polling');
finish_build($build);
$r = req('GET', '/home/builds', ['jar' => as_member(CARA), 'headers' => ['HX-Request: true']]);
ok($r['code'] === 286 && str_contains($r['body'], 'id="home-builds-none"') && !str_contains($r['body'], 'every 5s') && preg_match('/^HX-Trigger: baseChanged/mi', $r['headers']) === 1, 'when the build finishes: 286 (htmx stops polling) with the final region and HX-Trigger: baseChanged');
$r = req('GET', '/home/builds?region=bases', ['jar' => as_member(CARA), 'headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="home-bases"') && str_contains($r['body'], 'id="home-base-' . $hb . '"'), 'the bases region refreshes on baseChanged (/home/builds?region=bases)');
$r = req('GET', '/home/builds', ['jar' => as_member(PRIYA), 'headers' => ['HX-Request: true']]);
ok($r['code'] === 286, 'a person who curates nothing is answered 286 at once');
$r = req('GET', '/home/builds', ['jar' => as_member(CARA), 'headers' => JSONH]);
ok($r['code'] === 286 && json_decode($r['body'], true)['data']['builds'] === [], 'JSON gets the builds (none now)');

echo "5. The admin\n";
$dsp = (int) one("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, base_id, status, detail, attempts, answered_at) VALUES ('base', :b, :a, 'propose_areas', 'chat', :c, :b, 'failed', 'The kernel is not reachable right now.', 5, now()) RETURNING id", ['b' => $pol, 'a' => EXPERT, 'c' => CARA]);
pdo()->exec("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, base_id, status) VALUES ('base', $hb, " . EXPERT . ", 'draft_guidelines', 'chat', " . CARA . ", $hb, 'sent')");
pdo()->exec("INSERT INTO archivist_proposals (base_id, kind, title, reason, proposed_by) VALUES ($hb, 'gap', 'SMOKE gap proposal', 'Three people asked.', " . ARCHIVIST . ")");
pdo()->prepare('SELECT kn_usage_add(:b, :k, :c, :i, :o, :ch)')->execute(['b' => $hb, 'k' => 'extract', 'c' => 4, 'i' => 4000, 'o' => 900, 'ch' => 5000]);
pdo()->prepare('SELECT kn_usage_add(:b, :k, :c, :i, :o, :ch)')->execute(['b' => $hb, 'k' => 'answer', 'c' => 7, 'i' => 700, 'o' => 210, 'ch' => 0]);
[$c, $d] = data_of(OWNER, '/');
$a = $d['admin'];
ok($c === 200 && $a !== null && count($a['usage']['bases']) >= 1 && $a['usage']['bases'][0]['base_id'] === $hb && $a['usage']['bases'][0]['calls'] === 11 && $a['usage']['bases'][0]['input_tokens'] === 4700, 'the admin: usage by base this month (calls, tokens)');
ok(count($a['failed_builds']) === 1 && $a['failed_builds'][0]['build_id'] === $failed && $a['dispatches']['failed'] === 1 && $a['dispatches']['pending'] === 1 && $a['open_proposals']['count'] === 1, 'a failed build (7 days), a failed dispatch and a waiting one, an open proposal');
$h = html_of(OWNER, '/');
ok(str_contains($h, 'id="home-usage-' . $hb . '"') && str_contains($h, 'id="home-failed-build-' . $failed . '"') && str_contains($h, 'id="home-dispatches-counts"') && str_contains($h, 'id="home-proposals-count"') && str_contains($h, 'id="home-dispatch-' . $dsp . '"'), 'the page draws the four admin cards');
ok(str_contains($h, 'href="/admin/usage"') && str_contains($h, 'href="/admin/dispatches"') && str_contains($h, 'href="/admin/proposals"'), '…each linking to its page');
ok(str_contains($h, 'id="home-builds"') && str_contains($h, 'id="home-gaps"'), 'the admin curates every base, so Builds running and Gaps are there too');
[$c, $dd] = data_of(AVERY, '/');
ok($c === 200 && $dd['admin'] !== null && $dd['admin']['dispatches']['failed'] === 1, 'a second admin (the admin role) sees the admin region');

echo "6. JSON answers the same keys\n";
$keys = null; $same = true;
foreach ([PRIYA, CARA, REID, OWNER, AVERY] as $m) { [, $dj] = data_of($m, '/'); $k = array_keys($dj); sort($k); $keys ??= $k; $same = $same && $k === $keys; }
ok($same && $keys === ['admin', 'archivist_note', 'bases', 'builds', 'first_run', 'gaps', 'may', 'pins_due', 'recent_questions'], 'every role\'s JSON has the same keys: ' . implode(', ', $keys));
ok(!str_contains(json_encode($dj), 'owner@example') && !str_contains(json_encode($dj), '"email"'), 'no address in the home\'s JSON');
finish();
