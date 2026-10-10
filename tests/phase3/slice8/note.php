<?php
/** Slice 8 proof — the Monday note (agents-feeds.md "Proof": note.php). */
require __DIR__ . '/lib.php';

$w = agents_world();
$m = $w['manuals'];
$p = $w['policies'];
$cara = as_member(CARA);
act($cara, '/bases/members/add.php', ['base' => $m, 'member' => OWNER, 'level' => 'curate']);       // the admin curates the Manuals: kn_base_curator_ids() names who is told
$body = "## Monday\n\n- 2 sources failed to read\n- 1 build failed: the model refused\n- 3 questions found nothing " . SENTINEL;
$since = last_activity_id();
[$c, $b] = as_archivist('/notifications/send.php', ['base' => $m, 'title' => 'SMOKE Monday note: Manuals', 'body' => $body, 'kind' => 'monday_note']);
ok($c === 200 && ($b['recipients'] ?? null) === 2 && ($b['record_id'] ?? 0) === $m, 'the Archivist\'s notification → 200, two curators told ' . json_encode($b));
$n = static fn (int $member): array => q("SELECT * FROM notifications WHERE member_id = :m AND kind = 'monday_note' AND record_type = 'base' AND record_id = :b ORDER BY id", ['m' => $member, 'b' => $m]);
ok(count($n(CARA)) === 1 && count($n(OWNER)) === 1 && $n(CARA)[0]['title'] === 'SMOKE Monday note: Manuals' && str_contains((string) $n(CARA)[0]['body'], '2 sources failed'), 'Cara and the admin hold a monday_note in the bell');
ok(strlen((string) $n(CARA)[0]['body']) <= 500 && count($n(PRIYA)) === 0 && count($n(REID)) === 0, 'the bell body is cut at 500 characters; a Reader is not told');
$ob = q("SELECT * FROM notification_outbox WHERE member_id = :m AND kind = 'monday_note' ORDER BY id DESC", ['m' => CARA]);
ok(count($ob) >= 1 && $ob[0]['channel'] === 'email' && $ob[0]['status'] === 'queued' && str_starts_with((string) $ob[0]['dedupe_key'], 'monday:' . $m . ':'), 'an email is queued for Cara per her preferences (dedupe key ' . $ob[0]['dedupe_key'] . ')');
$ev = activity('notification.send', $since);
$a = after_of($ev[0] ?? ['after' => '{}']);
ok(count($ev) === 1 && ($a['recipients'] ?? null) === 2 && ($a['kind'] ?? '') === 'monday_note' && (int) $ev[0]['base_id'] === $m && !in_log(SENTINEL) && !in_log('sources failed to read'), 'notification.send logged with the count — never the body');
[$c, $b] = as_archivist('/notifications/send.php', ['base' => $m, 'title' => 'SMOKE Monday note: Manuals (again)', 'body' => 'Second try the same week.']);
ok($c === 200 && ($b['recipients'] ?? null) === 0 && count($n(CARA)) === 1 && count($n(OWNER)) === 1, 'the same week again → nobody is told twice');
pdo()->exec("UPDATE notification_prefs SET email_enabled = false WHERE member_id = " . CARA);
[$c, $b] = as_archivist('/notifications/send.php', ['base' => $m, 'title' => 'SMOKE Monday note: Manuals (third)', 'body' => 'Even with email off.']);
ok(($b['recipients'] ?? null) === 0 && count($n(CARA)) === 1, '…also for a person whose email is off (the outbox dedupe would not hold; the bell\'s week does)');
pdo()->exec("UPDATE notification_prefs SET email_enabled = true WHERE member_id = " . CARA);
[$c, $b] = as_archivist('/notifications/send.php', ['base' => $m, 'title' => 't', 'body' => 'b', 'kind' => 'proposal']);
ok($c === 422 && json_msg($b) === 'Only the Monday note is sent this way.', 'kind proposal → 422 "' . json_msg($b) . '"');
[$c, $b] = as_archivist('/notifications/send.php', ['base' => $m, 'title' => '', 'body' => '']);
ok($c === 422 && isset(fields($b)['title']) && isset(fields($b)['body']), 'no title, no body → 422 on both');
[$c, $b] = as_archivist('/notifications/send.php', ['base' => $m, 'title' => str_repeat('t', 201), 'body' => 'b']);
ok($c === 422 && isset(fields($b)['title']), 'a title over 200 characters → 422');
[$c, $b] = as_archivist('/notifications/send.php', ['base' => $m, 'title' => 't', 'body' => str_repeat('b', 4001)]);
ok($c === 422 && isset(fields($b)['body']), 'a body over 4,000 characters → 422');
[$c, $b] = act(as_member(PRIYA), '/notifications/send.php', ['base' => $m, 'title' => 't', 'body' => 'b']);
ok($c === 403, 'Priya (not an agent, no settings.manage) → 403 ("' . msg($b) . '")');
[$c] = act($cara, '/notifications/send.php', ['base' => $p, 'title' => 't', 'body' => 'b']);
ok($c === 403, 'a curator without settings.manage → 403 as well');
[$c, $b] = act(as_member(OWNER), '/notifications/send.php', ['base' => $p, 'title' => 'SMOKE Monday note: Policies', 'body' => 'The admin writes one too.']);
ok($c === 200 && ($b['recipients'] ?? null) === 1 && (int) one("SELECT count(*) FROM notifications WHERE member_id = :m AND kind = 'monday_note' AND record_id = :b", ['m' => CARA, 'b' => $p]) === 1, 'the admin (settings.manage) may send one: Cara told on the Policies');
[$c] = as_archivist('/notifications/send.php', ['base' => (int) base_by_key('smoke-empty8'), 'title' => 't', 'body' => 'b']);
ok($c === 404, 'a base the Archivist does not read → 404');
finish();
