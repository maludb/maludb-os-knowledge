<?php
/** Slice 8 proof — polling and retries (agents-feeds.md "Proof": poll.php). A person asks (Cara on the Policies) and the worker is run with its clock moved. */
require __DIR__ . '/lib.php';

$w = agents_world();
$p = $w['policies'];
$cara = as_member(CARA);
$posts = static fn (): int => count(array_filter(chat_log(), static fn ($c) => $c['method'] === 'POST'));
$gets = static fn (): int => count(array_filter(chat_log(), static fn ($c) => $c['method'] === 'GET'));
/** A propose_areas dispatch asked for by Cara on the Policies, on a clean slate: its id. */
$ask = static function () use ($cara, $p): int {
    clear_dispatches();
    [$c, $b] = act($cara, '/agents/propose-areas.php', ['base' => $p]);
    if ($c !== 200) { fwrite(STDERR, "ask: $c " . json_encode($b) . "\n"); }
    return (int) ($b['record_id'] ?? 0);
};

echo "1. 202, then the poll\n";
chat_state(['chat_http' => 202, 'chat' => ['run_id' => 6101, 'request_id' => 'req-6101', 'status' => 'running', 'finished' => false]]);
$id = $ask();
worker_at();
$d = dispatch_row($id);
ok($d['status'] === 'sent' && (int) $d['run_id'] === 6101 && $d['request_id'] === 'req-6101' && $posts() === 1, '202 → sent with run 6101 and its request id; one call');
worker_at();
worker_at();
ok($posts() === 1 && $gets() >= 2, 'the queue skips a dispatch with a run (still one POST); the poll step asks GET ?run= each pass (' . $gets() . ')');
chat_state(['chat_run' => ['run_id' => 6101, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Proposed.', 'cost' => 0.02, 'currency' => 'USD']]);
$since = last_activity_id();
worker_at();
$d = dispatch_row($id);
ok($d['status'] === 'answered' && $d['reply_excerpt'] === 'Proposed.' && $d['answered_at'] !== null && count(activity('agent.reply', $since)) === 1, 'finished → answered by the poll step, agent.reply logged once');
worker_at();
ok(count(activity('agent.reply', $since)) === 1, '…and not again');

echo "2. 409: busy, retried 2^attempts minutes later\n";
chat_state(['chat_status' => 409]);
$id = $ask();
chat_clear_log();
$since = last_activity_id();
worker_at();
$d = dispatch_row($id);
$ahead = strtotime((string) $d['next_attempt_at']) - time();
ok($d['status'] === 'sent' && (int) $d['attempts'] === 2 && $ahead > 3 * 60 + 30 && $ahead < 4 * 60 + 30 && str_contains((string) $d['detail'], 'busy'), '409 → attempts 2, next attempt 4 minutes ahead (' . $ahead . ' s), "' . $d['detail'] . '"');
worker_at(at(2));
ok($posts() === 1, 'two minutes on, the pass skips it (still one call)');
chat_state(['chat' => ['run_id' => 6102, 'status' => 'succeeded', 'finished' => true, 'reply' => 'At last.']]);
worker_at(at(5));
$d = dispatch_row($id);
ok($posts() === 2 && $d['status'] === 'answered' && (int) $d['attempts'] === 2, 'five minutes on it is called again and answered');
$f = activity('agent.fail', $since);
ok(count($f) === 1 && (after_of($f[0])['final'] ?? null) === false && (int) (after_of($f[0])['attempts'] ?? 0) === 2, 'agent.fail logged for the failed try (not final)');

echo "3. 500 five times: failed for good\n";
chat_state(['chat_status' => 500]);
$id = $ask();
chat_clear_log();
$since = last_activity_id();
foreach ([null, at(5), at(14), at(31), at(64)] as $t) { worker_at($t); }
$d = dispatch_row($id);
ok($posts() === 5 && $d['status'] === 'failed' && (int) $d['attempts'] === 5 && $d['detail'] === 'The kernel is not reachable right now.' && $d['answered_at'] !== null, 'five calls, then failed (attempts 5): "' . $d['detail'] . '"');
worker_at(at(300));
ok($posts() === 5, 'a failed one is not called again');
$f = activity('agent.fail', $since);
$finals = array_filter($f, static fn ($r) => (after_of($r)['final'] ?? false) === true);
ok(count($f) === 5 && count($finals) === 1 && in_log('The kernel is not reachable') && !in_log('propose-focus-areas'), 'agent.fail logged five times, once final (the detail, never the utterance)');

echo "4. 403: refused with the kernel's sentence\n";
chat_state(['chat_status' => 403, 'chat_message' => 'This person may not use the expert.']);
$id = $ask();
chat_clear_log();
worker_at();
$d = dispatch_row($id);
ok($d['status'] === 'refused' && $d['detail'] === 'This person may not use the expert.' && $posts() === 1, '403 → refused with "' . $d['detail'] . '"');
worker_at(at(60));
ok($posts() === 1, 'a refused one is not called again');

echo "5. Awaiting approval\n";
chat_state(['chat' => ['run_id' => 6105, 'request_id' => 'req-6105', 'status' => 'awaiting_approval', 'finished' => true, 'reply' => '', 'approval_request_id' => 77]]);
$id = $ask();
worker_at();
$d = dispatch_row($id);
ok($d['status'] === 'awaiting_approval' && $d['detail'] === 'waiting for approval request 77', 'awaiting_approval → "' . $d['detail'] . '"');
$h = page(as_member(OWNER), '/admin/dispatches?status=awaiting_approval')['body'];
ok(str_contains($h, 'waiting for approval request 77') && str_contains($h, 'waiting for approval'), 'the dispatches screen shows it with the request id');

echo "6. A run that never finishes\n";
chat_state(['chat_http' => 202, 'chat' => ['run_id' => 6106, 'status' => 'running', 'finished' => false]]);
$id = $ask();
worker_at();
ok(dispatch_row($id)['status'] === 'sent', 'running');
pdo()->exec("UPDATE agent_dispatches SET created_at = now() - interval '31 minutes' WHERE id = $id");
worker_at();
$d = dispatch_row($id);
ok($d['status'] === 'failed' && $d['detail'] === 'the run did not finish', 'back-dated 31 minutes and still running → failed "' . $d['detail'] . '"');

echo "7. Two workers at once: one call\n";
chat_state(['chat' => ['run_id' => 6107, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Once.']]);
$id = $ask();
chat_clear_log();
shell_exec('cd ' . escapeshellarg(APP_ROOT) . ' && (php bin/worker.php >/dev/null 2>&1 & php bin/worker.php >/dev/null 2>&1 & php bin/worker.php >/dev/null 2>&1 & wait)');
ok($posts() === 1 && dispatch_row($id)['status'] === 'answered', 'three workers started together → one call to the kernel, the row answered');

echo "8. The admin's Retry\n";
$admin = as_member(OWNER);
chat_state(['chat_status' => 403, 'chat_message' => 'This person may not use the expert.']);
$rid = $ask();
worker_at();
$since = last_activity_id();
chat_state(['chat' => ['run_id' => 6108, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Second time lucky.']]);
chat_clear_log();
[$c, $b] = act(as_member(PRIYA), '/admin/dispatches/retry.php', ['dispatch' => $rid]);
ok($c === 403, 'a Member\'s Retry → 403 ("' . msg($b) . '")');
[$c, $b] = act($admin, '/admin/dispatches/retry.php', ['dispatch' => $rid]);
$d = dispatch_row($rid);
ok($c === 200 && $d['status'] === 'sent' && $d['run_id'] === null && $d['detail'] === null && $d['answered_at'] === null, 'the admin\'s Retry of the refused one → sent, run and detail cleared');
$ev = activity('agent.dispatch', $since);
ok(count($ev) === 1 && (after_of($ev[0])['retry'] ?? false) === true && $ev[0]['source'] === 'web', 'agent.dispatch logged with retry true (source web)');
worker_at();
ok(dispatch_row($rid)['status'] === 'answered' && $posts() === 1, 'the next pass calls it and it is answered');
[$c, $b] = act($admin, '/admin/dispatches/retry.php', ['dispatch' => $rid]);
ok($c === 422, 'Retry of an answered one → 422: "' . msg($b) . '"');
[$c] = act($admin, '/admin/dispatches/retry.php', ['dispatch' => 999999]);
ok($c === 404, 'an unknown dispatch → 404');
finish();
