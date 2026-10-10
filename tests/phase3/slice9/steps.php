<?php
/** Slice 9 proof — the daily steps: pins due, limits near, retention (home-admin.md "Proof": steps.php). */
require __DIR__ . '/lib.php';

$w = home_world();
$hb = $w['handbook']; $pol = $w['policies'];
pdo()->exec("UPDATE pinned_answers SET status = 'retired', retired_at = now() WHERE status = 'active'");
outbox_drain();
pdo()->exec('DELETE FROM notification_prefs WHERE member_id IN (' . CARA . ', ' . BEA . ')');         // the defaults: email on, review_due and limit_near among the kinds
set_setting('timezone', 'UTC');
set_setting('question_retention_days', '365');
set_setting('limit_warn_pct', '80');
$step = static function (string $iso, string $name, array $env = []): mixed {
    $before = last_activity_id();
    $out = worker_at($iso, $env);
    $runs = cron_runs($before);
    return $runs === [] ? null : (steps_of(end($runs))[$name] ?? null);
};
$last_run = static function (): array { $r = q("SELECT * FROM activity_log WHERE action = 'cron.run' ORDER BY id DESC LIMIT 1")[0]; return ['steps' => steps_of($r), 'after' => json_decode((string) $r['after'], true), 'row' => $r]; };
$told = static fn (int $member, string $kind): int => (int) one('SELECT count(*) FROM notifications WHERE member_id = :m AND kind = :k', ['m' => $member, 'k' => $kind]);
// Fixed days well ahead of today: the pass the world's own worker ran today has already run the daily steps for today, and a month boundary must not move a proof.
$fx = static fn (string $date, string $time = '12:00:00'): string => $date . 'T' . $time . 'Z';
$D0 = $fx('2027-03-02');

echo "1. Pins due\n";
$mk = static function (string $title, int $member = CARA) use ($hb): int {
    $q = old_question($hb, PRIYA, 'SMOKE pin question ' . $title, 60, 'answered');
    return (int) one('SELECT kn_pin_answer(:q, :m, :t)', ['q' => $q, 'm' => $member, 't' => $title]);
};
$pin = $mk('SMOKE steps pin');
$future = $mk('SMOKE steps pin, not yet');
pdo()->exec("UPDATE pinned_answers SET review_until = (CAST('" . substr($D0, 0, 10) . "' AS date) - 1) WHERE id = $pin");
pdo()->exec("UPDATE pinned_answers SET review_until = (CAST('" . substr($D0, 0, 10) . "' AS date) + 20) WHERE id = $future");
$agentPin = $mk('SMOKE steps pin of an agent', SEAMUS);
pdo()->exec("UPDATE pinned_answers SET review_until = (CAST('" . substr($D0, 0, 10) . "' AS date) - 5) WHERE id = $agentPin");
$before = $told(CARA, 'review_due');
$s = $step($D0, 'pins_due');
ok($s === ['pins' => 2, 'told' => 1] || $s === ['told' => 1, 'pins' => 2], 'the first pass of the day: two pins past their date, one owner told (an agent is never notified): ' . json_encode($s));
$n = q("SELECT * FROM notifications WHERE member_id = :m AND kind = 'review_due' ORDER BY id DESC LIMIT 1", ['m' => CARA])[0];
ok($told(CARA, 'review_due') === $before + 1 && $n['title'] === 'Pinned answer due for review: SMOKE steps pin' && $n['record_type'] === 'pinned_answer' && (int) $n['record_id'] === $pin && $n['body'] === 'SMOKE Handbook', 'its owner has a review_due notice: title, the base\'s name as the body, the pin as the record');
$ob = q("SELECT channel, dedupe_key FROM notification_outbox WHERE record_type = 'pinned_answer' AND record_id = :p ORDER BY id", ['p' => $pin]);
ok(count($ob) >= 1 && str_starts_with((string) $ob[0]['dedupe_key'], 'review:' . $pin . ':' . '2027-03-01'), 'queued with the dedupe key review:<pin>:<review date>');
$s = $step($fx('2027-03-02', '12:30:00'), 'pins_due');
ok($s === 'not_due' && $told(CARA, 'review_due') === $before + 1, 'a second pass the same day: "not_due", nobody told again');
pdo()->exec("DELETE FROM notification_outbox WHERE record_type = 'pinned_answer'");          // nothing for the outbox's dedupe key to hold on to (a person whose email is off has no row)
$s = $step($fx('2027-03-03'), 'pins_due');
ok(is_array($s) && $s['pins'] === 1 && $s['told'] === 0 && $told(CARA, 'review_due') === $before + 1, 'the next day the step runs again — and tells nobody twice for the same review date, outbox row or none (only the agent\'s pin is left to tell about)');
[$c, $b] = act(as_member(CARA), '/pinned/review.php', ['pinned_answer' => $pin]);
$moved = (string) one('SELECT review_until FROM pinned_answers WHERE id = ' . $pin);
ok($c === 200 && $moved > '2027-03-04', 'Verify again moves the review date on (' . $moved . ')');
$s = $step($fx('2027-03-04'), 'pins_due');
ok(is_array($s) && $s['pins'] === 1 && $s['told'] === 0 && $told(CARA, 'review_due') === $before + 1, 'with the new date still ahead nobody is told (one pin left past its date: the agent\'s, which nobody can be told about)');
pdo()->exec("UPDATE pinned_answers SET review_until = (CAST('" . '2027-03-05' . "' AS date) - 1) WHERE id = $pin");
$s = $step($fx('2027-03-05'), 'pins_due');
ok(is_array($s) && $s['told'] === 1 && $told(CARA, 'review_due') === $before + 2, 'passes again → told again, once, for the new date');
pdo()->exec("UPDATE knowledge_bases SET archived_at = now() WHERE id = $hb");
pdo()->exec("UPDATE pinned_answers SET review_until = (CAST('" . '2027-03-06' . "' AS date) - 1) WHERE id = $pin");
$s = $step($fx('2027-03-06'), 'pins_due');
ok(is_array($s) && $s['pins'] === 0 && $told(CARA, 'review_due') === $before + 2, 'a pin on an archived base is nobody\'s to review');
pdo()->exec("UPDATE knowledge_bases SET archived_at = NULL WHERE id = $hb");
pdo()->exec("UPDATE pinned_answers SET status = 'retired', retired_at = now() WHERE status = 'active'");

echo "2. Limits near\n";
pdo()->exec('DELETE FROM ai_usage');
pdo()->exec("UPDATE knowledge_bases SET monthly_text_mb_limit = 1 WHERE id IN ($hb, $pol)");
act(as_member(CARA), '/bases/members/add.php', ['base' => $pol, 'member' => BEA, 'level' => 'curate']);
$usage = static function (int $b, int $chars, string $day) { pdo()->prepare("SELECT kn_usage_add(:b, 'extract', 1, 10, 10, :c, CAST(:d AS timestamptz))")->execute(['b' => $b, 'c' => $chars, 'd' => $day]); };
$D10 = $fx('2027-04-10');
$usage($pol, 900000, $D10);          // 85 %
$usage($hb, 700000, $D10);           // 66 %
$base = [$told(CARA, 'limit_near'), $told(BEA, 'limit_near')];
$s = $step($D10, 'limits_near');
ok(is_array($s) && $s['bases'] === 1 && $s['told'] === 2, 'a base at 85 % with the warning at 80: its two curators told (the base at 66 % is not): ' . json_encode($s));
$n = q("SELECT * FROM notifications WHERE member_id = :m AND kind = 'limit_near' ORDER BY id DESC LIMIT 1", ['m' => BEA])[0];
ok($told(CARA, 'limit_near') === $base[0] + 1 && $told(BEA, 'limit_near') === $base[1] + 1 && $n['title'] === 'Knowledge base "SMOKE Policies" has used 85% of its monthly text', 'each curator has the notice: "' . $n['title'] . '"');
ok((int) $n['record_id'] === $pol && $n['record_type'] === 'base', '…naming the base');
$s = $step($fx('2027-04-10', '13:00:00'), 'limits_near');
ok($s === 'not_due', 'once a day: a second pass is not due');
$s = $step($fx('2027-04-11'), 'limits_near');
ok(is_array($s) && $s['told'] === 0 && $told(CARA, 'limit_near') === $base[0] + 1, 'the next day it runs again and tells nobody twice this month');
pdo()->exec("UPDATE kn_settings SET limit_warn_pct = 60 WHERE id = 1");
$s = $step($fx('2027-04-12'), 'limits_near');
ok(is_array($s) && $s['bases'] === 2 && $told(CARA, 'limit_near') === $base[0] + 2, 'a lower warning percent (60) takes in the Handbook too: its curator (Cara) is told for it');
pdo()->exec("UPDATE kn_settings SET limit_warn_pct = 80 WHERE id = 1");
pdo()->exec('DELETE FROM ai_usage');
pdo()->exec("UPDATE knowledge_bases SET monthly_text_mb_limit = (SELECT default_monthly_text_mb FROM kn_settings WHERE id = 1) WHERE id IN ($hb, $pol)");

echo "3. Retention\n";
set_setting('question_retention_days', '30');
pdo()->exec('DELETE FROM questions');
$oa = old_question($hb, PRIYA, 'SMOKE retention old A ' . SENTINEL, 45 * 86400);
$ob = old_question($hb, PRIYA, 'SMOKE retention old B', 50 * 86400);
$fresh = old_question($hb, PRIYA, 'SMOKE retention fresh', 86400);
$pq = old_question($hb, PRIYA, 'SMOKE retention pinned', 90 * 86400, 'answered');
$pp = (int) one('SELECT kn_pin_answer(:q, :m, :t)', ['q' => $pq, 'm' => CARA, 't' => 'SMOKE retention pin']);
$since = last_activity_id();
$s = $step($fx('2027-05-20'), 'retention');
ok($s === ['purged' => 2], 'the daily retention step purges past the setting (30 days): two questions — not the fresh one, not the pinned one');
$ev = activity('question.purge', $since);
$a = after_of($ev[0] ?? ['after' => '{}']);
ok(count($ev) === 1 && $ev[0]['source'] === 'cron' && $ev[0]['actor_member_id'] === null && $a['purged'] === 2 && $a['older_than_days'] === 30 && !in_log(SENTINEL), 'question.purge logged by cron with the count — no words');
ok((int) one("SELECT count(*) FROM questions WHERE id IN ($fresh, $pq)") === 2 && (int) one("SELECT count(*) FROM questions WHERE id IN ($oa, $ob)") === 0, 'the two old ones are gone');
$oc = old_question($hb, PRIYA, 'SMOKE retention old C', 70 * 86400);
$s = $step($fx('2027-05-20', '15:00:00'), 'retention');
ok($s === 'not_due' && (int) one("SELECT count(*) FROM questions WHERE id = $oc") === 1, 'the same day again: not due — it purges once a day, not twice');
$since = last_activity_id();
$s = $step($fx('2027-05-21'), 'retention');
ok($s === ['purged' => 1] && count(activity('question.purge', $since)) === 1, 'the next day: it runs again and purges the one that aged in');
$since = last_activity_id();
$s = $step($fx('2027-05-22'), 'retention');
ok($s === ['purged' => 0] && activity('question.purge', $since) === [], 'a day with nothing to purge logs no question.purge');
set_setting('question_retention_days', '365');

echo "4. The pass, the clock and the time zone\n";
$r = $last_run();
$names = array_keys($r['steps']);
$want = ['forget', 'purge', 'recover', 'sources', 'builds', 'dispatch_after_build', 'dispatches', 'dispatch_poll', 'feeds', 'url_checks', 'key_usage', 'pins_due', 'limits_near', 'retention', 'outbox'];
sort($names); sort($want);
ok($names === $want, 'cron.run lists every step of the pass: ' . implode(', ', $names));
ok(isset($r['steps']['outbox']['sent']) && $r['steps']['retention'] === ['purged' => 0] && $r['after']['day'] === '2027-05-22' && $r['row']['source'] === 'cron' && $r['row']['actor_member_id'] === null, 'the daily steps carry their counts, the pass its local day (' . $r['after']['day'] . '), source cron');
$o = worker_at($fx('2027-06-01'), ['APP_ENV' => 'prod']);
$r = $last_run();
ok($r['after']['day'] === gmdate('Y-m-d') && str_contains($o, 'worker pass done'), 'KN_WORKER_NOW is honoured only when APP_ENV is exactly dev: in prod the pass keeps the real day (' . $r['after']['day'] . ')');
set_setting('timezone', 'Pacific/Auckland');
$nz = new DateTimeZone('Pacific/Auckland');
$d1 = (new DateTimeImmutable('2027-06-10' . ' 23:30:00', $nz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
$d2 = (new DateTimeImmutable('2027-06-11' . ' 00:30:00', $nz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
$s1 = $step($d1, 'retention');
$s2 = $step($d2, 'retention');
ok(is_array($s1) && is_array($s2) && substr($d1, 0, 10) === substr($d2, 0, 10), 'in Pacific/Auckland midnight comes between 23:30 and 00:30 — the step runs again though it is the same UTC day (' . $d1 . ' → ' . $d2 . ')');
$s3 = $step(gmdate('Y-m-d\TH:i:s\Z', strtotime($d2) + 3600), 'retention');
ok($s3 === 'not_due', '…and an hour later (still that local day) it does not');
set_setting('timezone', 'UTC');
finish();
