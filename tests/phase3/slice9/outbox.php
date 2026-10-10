<?php
/** Slice 9 proof — the outbox sender (home-admin.md "Proof": outbox.php). */
require __DIR__ . '/lib.php';

$w = home_world();
$hb = $w['handbook'];
pdo()->exec("UPDATE pinned_answers SET status = 'retired', retired_at = now() WHERE status = 'active'");     // no daily step has anything to queue behind this proof's back
$sec = static fn (int $id): int => (int) strtotime((string) $id);
$epoch = static fn (string $ts): int => (int) strtotime($ts);
$pass = static function (int $minutes, array $env = []): array {
    $before = last_activity_id();
    $out = worker_at(at($minutes), $env);
    $runs = cron_runs($before);
    $c = $runs === [] ? [] : (steps_of(end($runs))['outbox'] ?? []);
    ksort($c);                                                                                   // (jsonb keeps its own key order)
    return [$out, $c];
};

echo "1. Email through MaluMail\n";
outbox_drain(); mail_clear(); sms_clear(); sms_mode('ok');
$before = last_activity_id();
$r1 = outbox_add('email', PRIYA, 'priya@example.invalid', 'Build finished: SMOKE Handbook', 'Eight sections extracted ' . SENTINEL, 'build_done');
[$out, $counts] = $pass(0);
$row = outbox_get($r1);
$log = mail_log();
ok($row['status'] === 'sent' && (int) $row['attempts'] === 1 && $row['sent_at'] !== null && str_starts_with((string) $row['provider_ref'], '<mm-') && $row['detail'] === null, 'an email is sent through the fake MaluMail: status sent, attempts 1, sent_at, provider_ref ' . $row['provider_ref']);
ok(count($log) === 1 && $log[0]['to'] === 'priya@example.invalid' && $log[0]['subject'] === 'Build finished: SMOKE Handbook' && str_contains($log[0]['text'], 'Eight sections extracted') && $log[0]['from'] === 'knowledge@example.invalid' && $log[0]['from_name'] === 'Knowledge', 'MaluMail was sent from, from_name, to, subject and the text');
ok($counts === ['failed' => 0, 'retried' => 0, 'sent' => 1, 'skipped' => 0], 'cron.run lists the outbox step\'s counts: ' . json_encode($counts));
$acts = q('SELECT action FROM activity_log WHERE id > :s ORDER BY id', ['s' => $before]);
$acts = array_column($acts, 'action');
ok(count(array_keys($acts, 'cron.run')) === 1 && array_filter($acts, static fn ($a) => str_starts_with($a, 'notification.')) === [], 'no per-message activity row — cron.run carries the counts');
ok(!in_log('@example.invalid') && !in_log(SENTINEL) && !in_log('Build finished'), 'no address, subject or body in any log row');
[$out2, $c2] = $pass(1);
ok(count(mail_log()) === 1 && $c2 === ['failed' => 0, 'retried' => 0, 'sent' => 0, 'skipped' => 0], 'a second pass sends it no more');

echo "2. No key, a suppressed address, a rejected one, none at all\n";
mail_clear();
$r2 = outbox_add('email', PRIYA, 'priya@example.invalid', 'No key', 'x');
[$out, $counts] = $pass(0, ['MALUMAIL_API_KEY' => '']);
$row = outbox_get($r2);
ok($row['status'] === 'skipped' && $row['detail'] === 'no_mail_key' && mail_log() === [] && $counts['skipped'] === 1, 'no MALUMAIL_API_KEY → skipped, detail no_mail_key; nothing sent');
$r3 = outbox_add('email', PRIYA, 'suppressed@example.invalid', 'Suppressed', 'x');
$r4 = outbox_add('email', PRIYA, 'rejected@example.invalid', 'Rejected', 'x');
$r5 = outbox_add('email', PRIYA, 'x@example.invalid', 'Address vanished', 'x');
pdo()->exec("UPDATE notification_outbox SET to_email = NULL WHERE id = $r5");
[$out, $counts] = $pass(0);
ok(outbox_get($r3)['status'] === 'skipped' && outbox_get($r3)['detail'] === 'undeliverable:suppressed:bounce', 'a suppressed address → skipped with the reason (undeliverable:suppressed:bounce)');
ok(outbox_get($r4)['status'] === 'skipped' && outbox_get($r4)['detail'] === 'undeliverable:invalid_address', 'a rejected address → skipped with the reason');
ok(outbox_get($r5)['status'] === 'skipped' && outbox_get($r5)['detail'] === 'no_address' && $counts['skipped'] === 3, 'no address → skipped (no_address); cron.run counts three skipped');
$r6 = outbox_add('email', PRIYA, 'priya@example.invalid', 'Later', 'x');
pdo()->exec("UPDATE notification_outbox SET send_after = now() + interval '1 hour' WHERE id = $r6");
mail_clear();
$pass(0);
ok(outbox_get($r6)['status'] === 'queued' && mail_log() === [], 'a row not yet due waits');
$pass(61);
ok(outbox_get($r6)['status'] === 'sent', '…and goes when its time comes');

echo "3. A flaky relay: backoff, then failed after five\n";
outbox_drain(); mail_clear();
$f = outbox_add('email', PRIYA, 'flaky@example.invalid', 'Flaky', 'x');
$steps = [[0, 2, 'retry:502'], [3, 4, 'retry:502'], [8, 8, 'retry:502'], [17, 16, 'retry:502']];
$okAll = true; $why = '';
foreach ($steps as $i => [$at, $wait, $detail]) {
    $pass($at);
    $row = outbox_get($f);
    $due = $epoch($row['send_after']) - strtotime(at($at));
    if ($row['status'] !== 'queued' || (int) $row['attempts'] !== $i + 1 || $row['detail'] !== $detail || abs($due - $wait * 60) > 2) { $okAll = false; $why .= " [pass $at: {$row['status']} attempts {$row['attempts']} {$row['detail']} due +" . round($due / 60, 1) . 'm]'; }
}
ok($okAll, 'each failure: attempts + 1, send_after = now + 2^attempts minutes (2, 4, 8, 16)' . $why);
$pass(18);
ok((int) outbox_get($f)['attempts'] === 4 && count(mail_log()) === 4, 'a pass before the backoff ends touches nothing (4 tries so far)');
[$out, $counts] = $pass(34);
$row = outbox_get($f);
ok($row['status'] === 'failed' && (int) $row['attempts'] === 5 && $row['detail'] === 'failed:502' && $counts['failed'] === 1 && count(mail_log()) === 5, 'the fifth failure is final: failed, attempts 5, detail failed:502, counted in cron.run');
$pass(80);
ok(count(mail_log()) === 5, 'a failed row is never tried again');

echo "4. Texts through the kernel's K6\n";
outbox_drain(); mail_clear(); sms_clear(); sms_mode('ok');
$t1 = outbox_add('text', PRIYA, '', '', 'Build failed: SMOKE Handbook', 'build_failed');
[$out, $counts] = $pass(0);
$row = outbox_get($t1);
$sms = sms_log();
ok($row['status'] === 'sent' && preg_match('/^kernel:\d+$/', (string) $row['provider_ref']) === 1 && count($sms) === 1 && $sms[0]['member_id'] === PRIYA && $sms[0]['text'] === 'Build failed: SMOKE Handbook', 'a text is sent through the fake K6 (member_id, text); provider_ref ' . $row['provider_ref']);
foreach (['opted_out', 'no_verified_phone', 'not_held', 'rate_limited', 'no_sender'] as $mode) {
    outbox_drain(); sms_clear(); sms_mode($mode);
    $t = outbox_add('text', PRIYA, '', '', 'A text ' . $mode, 'build_failed');
    $pass(0);
    $row = outbox_get($t);
    ok($row['status'] === 'skipped' && $row['detail'] === $mode, "K6 refusing with $mode → skipped, detail $mode");
}
outbox_drain(); sms_clear(); sms_mode('server_error');
$t = outbox_add('text', PRIYA, '', '', 'A text that meets a 500', 'build_failed');
$pass(0);
$row = outbox_get($t);
$due = $epoch($row['send_after']) - strtotime(at(0));
ok($row['status'] === 'queued' && (int) $row['attempts'] === 1 && $row['detail'] === 'retry:server_error' && abs($due - 120) <= 2, 'a 5xx from the kernel → attempts + 1 and a backoff of 2 minutes');
sms_mode('ok');
$pass(3);
ok(outbox_get($t)['status'] === 'sent' && (int) outbox_get($t)['attempts'] === 2, '…and it goes through once the kernel answers');

echo "5. One notice, an email and a text\n";
outbox_drain(); mail_clear(); sms_clear();
pdo()->exec('DELETE FROM notification_prefs WHERE member_id = ' . CARA);
pdo()->exec("INSERT INTO notification_prefs (member_id, email_enabled, text_enabled, kinds, text_kinds) VALUES (" . CARA . ", true, true, '{build_failed,limit_near}', '{build_failed}')");
$nid = (int) one("SELECT kn_notify(:m, 'build_failed', 'Build failed: SMOKE Policies', 'Two sections failed', 'base', :b, 'smoke-outbox-5')", ['m' => CARA, 'b' => $hb]);
$rows = q("SELECT id, channel FROM notification_outbox WHERE dedupe_key LIKE 'smoke-outbox-5:%' ORDER BY channel");
ok(count($rows) === 2 && $rows[0]['channel'] === 'email' && $rows[1]['channel'] === 'text', 'one notice queues an email row and a text row (kn_notify)');
sms_mode('opted_out');
[$out, $counts] = $pass(1);                      // (kn_notify queues a row due now: a minute later is surely past it)
$by = array_column(q("SELECT channel, status, detail FROM notification_outbox WHERE dedupe_key LIKE 'smoke-outbox-5:%'"), null, 'channel');
ok($by['text']['status'] === 'skipped' && $by['text']['detail'] === 'opted_out' && $by['email']['status'] === 'sent' && count(mail_log()) === 1 && mail_log()[0]['to'] === 'marco@example.invalid', 'the text refused opted_out is skipped while the email of the same notice is sent');
$nid2 = (int) one("SELECT kn_notify(:m, 'build_failed', 'Build failed: SMOKE 2', 'x', 'base', :b, 'smoke-outbox-5b')", ['m' => CARA, 'b' => $hb]);
sms_mode('no_sender');
$pass(2);
$by = array_column(q("SELECT channel, status, detail FROM notification_outbox WHERE dedupe_key LIKE 'smoke-outbox-5b:%'"), null, 'channel');
ok($by['text']['status'] === 'skipped' && $by['text']['detail'] === 'no_sender' && $by['email']['status'] === 'sent', 'no_sender (the business has no texting number) → the text skipped, the email sent');
sms_mode('ok');

echo "6. Two workers, each row once\n";
outbox_drain(); mail_clear();
$ids = [];
for ($i = 1; $i <= 30; $i++) { $ids[] = outbox_add('email', PRIYA, "bulk$i@example.invalid", "Bulk $i", 'x', 'build_done'); }
$prog = 'require "app/bootstrap.php"; require APP_ROOT . "/app/features/admin/worker_steps.php"; $pdo = db(); echo json_encode(outbox_pass($pdo, new DateTimeImmutable("now", new DateTimeZone("UTC")), 40));';
$procs = [];
foreach ([1, 2, 3] as $n) {
    $procs[$n] = proc_open(['php', '-r', $prog], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$n], APP_ROOT);
}
$sent = 0;
foreach ($procs as $n => $p) { $sent += (json_decode((string) stream_get_contents($pipes[$n][1]), true)['sent'] ?? 0); proc_close($p); }
$log = mail_log();
$rowsDone = q("SELECT status, attempts, provider_ref FROM notification_outbox WHERE id IN (" . implode(',', $ids) . ')');
ok($sent === 30 && count($log) === 30 && count(array_unique(array_column($log, 'to'))) === 30, 'three concurrent passes sent 30 rows: 30 mails, each address once (' . $sent . ' counted, ' . count($log) . ' sent)');
ok(count(array_filter($rowsDone, static fn ($r) => $r['status'] === 'sent' && (int) $r['attempts'] === 1)) === 30 && count(array_unique(array_column($rowsDone, 'provider_ref'))) === 30, 'every row sent once with its own provider_ref');
outbox_drain();
$out = worker_at(at(0));
$more = trim((string) shell_exec('cd ' . escapeshellarg(APP_ROOT) . ' && php -r ' . escapeshellarg('require "app/bootstrap.php"; $p = db(); $p->query("SELECT pg_advisory_lock(hashtext(\'knowledge_worker\'))"); echo shell_exec("php bin/worker.php 2>&1");')));
ok(str_contains($more, 'another worker pass holds the lock'), 'a second worker pass while one holds the lock exits at once');
sms_mode('ok');
finish();
