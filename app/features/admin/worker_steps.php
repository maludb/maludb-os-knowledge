<?php
declare(strict_types=1);

/**
 * The worker's steps of slice 9 (docs/build-specs/home-admin.md "The worker's steps"), called by bin/worker.php with its clock ($now: the proofs' KN_WORKER_NOW, honoured only when APP_ENV is dev).
 * The worker has no member, so these read and write the base tables as the writer role and write only notifications, the outbox and the purge — never a screen. Each returns its counts, which
 * `cron.run` lists under after.steps; a daily step that is not due returns ['due' => false] and the worker lists it as "not_due".
 *   outbox_pass()        every pass: the queued notification_outbox rows, 20 at a time, each claimed FOR UPDATE SKIP LOCKED — email through MaluMail, a text through the kernel's K6
 *   pins_due_pass()      once a day: the owner of each active pin past its review date is told once per review date
 *   limits_near_pass()   once a day: a base's curators are told once a month when its month's text reaches the warning percent
 *   retention_pass()     once a day: questions past the retention setting are purged (a pinned answer's question stays)
 *   daily_step_due()     the first pass of the day in kn_settings.timezone: no cron.run row of that day lists the step as run
 * No notification body, outbox text, address, secret or token is ever written to the log; there is no per-message activity row.
 */

const OUTBOX_MAX_ATTEMPTS = 5;
const OUTBOX_KERNEL_REFUSALS = ['not_held', 'no_verified_phone', 'opted_out', 'rate_limited', 'no_sender', 'invalid'];

/** The calendar day of $now in the business's time zone (kn_settings.timezone), 'Y-m-d'. */
function worker_local_day(PDO $pdo, DateTimeImmutable $now): string
{
    $tz = (string) one_value($pdo, 'SELECT timezone FROM kn_settings WHERE id = 1');
    try {
        $zone = new DateTimeZone($tz !== '' ? $tz : 'UTC');
    } catch (Throwable) {
        $zone = new DateTimeZone('UTC');
    }
    return $now->setTimezone($zone)->format('Y-m-d');
}

/** A moment for SQL: 'Y-m-d H:i:s.uP'. */
function worker_ts(DateTimeImmutable $t): string
{
    return $t->format('Y-m-d H:i:s.uP');
}

/** Is the step due? True unless a cron.run row of this local day lists it as run (an object of counts; "not_due" is a string). */
function daily_step_due(PDO $pdo, string $step, DateTimeImmutable $now): bool
{
    $day = worker_local_day($pdo, $now);
    return one_value($pdo, "SELECT 1 FROM activity_log WHERE action = 'cron.run' AND after->>'day' = :d AND jsonb_typeof(after->'steps'->CAST(:s AS text)) = 'object' LIMIT 1", ['d' => $day, 's' => $step]) === null;
}

// ---------------------------------------------------------------------------------------------------------------------
// The outbox
// ---------------------------------------------------------------------------------------------------------------------

/** Send what is due. Answers ['sent', 'skipped', 'failed', 'retried']. */
function outbox_pass(PDO $pdo, DateTimeImmutable $now, int $limit = 20): array
{
    $out = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'retried' => 0];
    $stamp = worker_ts($now);
    for ($i = 0; $i < max(1, $limit); $i++) {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT * FROM notification_outbox WHERE status = 'queued' AND send_after <= CAST(:now AS timestamptz) ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED");
        $st->execute(['now' => $stamp]);
        $row = $st->fetch();
        if ($row === false) {
            $pdo->commit();
            break;
        }
        [$outcome, $code, $ref] = $row['channel'] === 'text' ? outbox_send_text($row) : outbox_send_email($row);
        $attempts = (int) $row['attempts'] + 1;
        if ($outcome === 'retry') {
            if ($attempts >= OUTBOX_MAX_ATTEMPTS) {
                $outcome = 'failed';
                $code = 'failed:' . $code;
            } else {
                $pdo->prepare('UPDATE notification_outbox SET attempts = :a, detail = :d, send_after = CAST(:now AS timestamptz) + make_interval(mins => :m) WHERE id = :id')
                    ->execute(['a' => $attempts, 'd' => 'retry:' . $code, 'now' => $stamp, 'm' => 2 ** $attempts, 'id' => (int) $row['id']]);
                $pdo->commit();
                $out['retried']++;
                continue;
            }
        }
        $pdo->prepare("UPDATE notification_outbox SET status = :s, attempts = :a, detail = :d, provider_ref = :p, sent_at = CASE WHEN :s2 = 'sent' THEN CAST(:now AS timestamptz) ELSE sent_at END WHERE id = :id")
            ->execute(['s' => $outcome, 'a' => $attempts, 'd' => $code, 'p' => $ref, 's2' => $outcome, 'now' => $stamp, 'id' => (int) $row['id']]);
        $pdo->commit();
        $out[$outcome]++;
    }
    return $out;
}

/** [outcome, detail, provider_ref] for an email row: sent | skipped | retry. */
function outbox_send_email(array $row): array
{
    if ((string) env('MALUMAIL_API_KEY', '') === '') {
        return ['skipped', 'no_mail_key', null];
    }
    $to = (string) ($row['to_email'] ?? '');
    if ($to === '') {
        return ['skipped', 'no_address', null];
    }
    $text = (string) $row['body'] . "\n\n— " . app_name() . ' · ' . app_url('/notifications');
    $mail = ['from' => (string) env('MAIL_FROM', 'noreply@localhost'), 'from_name' => (string) env('MAIL_FROM_NAME', app_name()), 'to' => $to, 'subject' => (string) ($row['subject'] ?? $row['kind']), 'text' => $text];
    if (($row['body_html'] ?? '') !== '') {
        $mail['html'] = (string) $row['body_html'];
    }
    try {
        $r = malumail_send($mail);
    } catch (RuntimeException) {
        return ['retry', 'transport', null];
    }
    $rejected = (array) ($r['body']['rejected'] ?? []);
    if ($r['status'] >= 200 && $r['status'] < 300 && $rejected === []) {
        return ['sent', null, $r['message_id']];
    }
    if ($r['status'] === 400 || ($r['status'] >= 200 && $r['status'] < 300)) {
        $why = (string) ($rejected[0]['reason'] ?? ($r['body']['error'] ?? 'undeliverable'));
        return ['skipped', 'undeliverable:' . mb_substr($why, 0, 80), null];
    }
    return ['retry', (string) $r['status'], null];
}

/** [outcome, detail, provider_ref] for a text row through the kernel's K6 (the kernel holds the phone and the opt-out): sent | skipped | retry. */
function outbox_send_text(array $row): array
{
    $a = kernel_call('POST', '/api/v1/notify/sms.php', ['member_id' => (int) $row['member_id'], 'text' => (string) $row['body']], [], 15);
    if ($a === null) {
        return ['retry', 'unreachable', null];
    }
    $status = (int) $a['status'];
    if ($status === 202 || $status === 200) {
        $id = $a['body']['notification']['id'] ?? null;
        return ['sent', null, $id === null ? null : 'kernel:' . $id];
    }
    $code = (string) ($a['body']['error']['code'] ?? ('http_' . $status));
    if (in_array($code, OUTBOX_KERNEL_REFUSALS, true)) {
        return ['skipped', $code, null];                                        // the kernel's own refusals (even no_sender, a 503): final, the email still goes
    }
    if ($status >= 500 || $status === 401 || $status === 429) {
        return ['retry', $code, null];
    }
    return ['skipped', mb_substr($code, 0, 80), null];
}

// ---------------------------------------------------------------------------------------------------------------------
// The daily steps
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Pins past their review date: the owner is told once per review date — the outbox's dedupe key names the date, and a bell notice newer than the pin's last verification stops a person whose
 * email is off (no outbox row to dedupe on) from being told every day; Verify again moves verified_at and so re-arms it. ['pins' => due and not yet told, 'told' => notices written].
 */
function pins_due_pass(PDO $pdo, DateTimeImmutable $now): array
{
    if (!daily_step_due($pdo, 'pins_due', $now)) {
        return ['due' => false];
    }
    $st = $pdo->prepare("SELECT p.id, p.title, p.owner_member_id, p.review_until, b.name AS base_name FROM pinned_answers p JOIN knowledge_bases b ON b.id = p.base_id
                          WHERE p.status = 'active' AND p.review_until <= CAST(:today AS date) AND b.archived_at IS NULL AND p.owner_member_id IS NOT NULL
                            AND NOT EXISTS (SELECT 1 FROM notifications n WHERE n.member_id = p.owner_member_id AND n.kind = 'review_due' AND n.record_type = 'pinned_answer' AND n.record_id = p.id AND n.created_at >= p.verified_at)
                          ORDER BY p.id");
    $st->execute(['today' => worker_local_day($pdo, $now)]);
    $notify = $pdo->prepare("SELECT kn_notify(:m, 'review_due', :t, :b, 'pinned_answer', :id, :d)");
    $pins = 0;
    $told = 0;
    foreach ($st->fetchAll() as $p) {
        $pins++;
        $notify->execute(['m' => (int) $p['owner_member_id'], 't' => 'Pinned answer due for review: ' . mb_substr((string) $p['title'], 0, 150), 'b' => mb_substr((string) $p['base_name'], 0, 200),
                          'id' => (int) $p['id'], 'd' => 'review:' . (int) $p['id'] . ':' . $p['review_until']]);
        $told += $notify->fetchColumn() !== null ? 1 : 0;
    }
    return ['pins' => $pins, 'told' => $told];
}

/** A base whose month's text reached the warning percent: each curator not yet told this month is told once. ['bases' => at the percent, 'told' => notices written]. */
function limits_near_pass(PDO $pdo, DateTimeImmutable $now): array
{
    if (!daily_step_due($pdo, 'limits_near', $now)) {
        return ['due' => false];
    }
    $day = worker_local_day($pdo, $now);
    $month = substr($day, 0, 7);
    $pct = (int) one_value($pdo, 'SELECT limit_warn_pct FROM kn_settings WHERE id = 1');
    $st = $pdo->prepare("SELECT b.id, b.name, b.monthly_text_mb_limit, COALESCE(u.chars, 0) AS used FROM knowledge_bases b
                           LEFT JOIN ai_usage u ON u.base_id = b.id AND u.kind = 'extract' AND u.month = CAST(:m AS date) WHERE b.archived_at IS NULL ORDER BY b.id");
    $st->execute(['m' => $month . '-01']);
    $cur = $pdo->prepare('SELECT kn_base_curator_ids(:b)');
    $told = $pdo->prepare("SELECT 1 FROM notifications WHERE member_id = :m AND kind = 'limit_near' AND record_type = 'base' AND record_id = :b AND created_at >= CAST(:ms AS date)");
    $notify = $pdo->prepare("SELECT kn_notify(:m, 'limit_near', :t, :body, 'base', :b, :d)");
    $bases = 0;
    $n = 0;
    foreach ($st->fetchAll() as $b) {
        $limit = (int) $b['monthly_text_mb_limit'] * 1048576;
        if ($limit <= 0 || (int) $b['used'] * 100 < $limit * $pct) {
            continue;
        }
        $bases++;
        $share = (int) floor((int) $b['used'] * 100 / $limit);
        $cur->execute(['b' => (int) $b['id']]);
        foreach ($cur->fetchAll(PDO::FETCH_COLUMN) as $m) {
            $told->execute(['m' => (int) $m, 'b' => (int) $b['id'], 'ms' => $month . '-01']);
            if ($told->fetchColumn() !== false) {
                continue;
            }
            $notify->execute(['m' => (int) $m, 't' => 'Knowledge base "' . mb_substr((string) $b['name'], 0, 120) . '" has used ' . $share . '% of its monthly text',
                              'body' => 'A build stops when the limit is reached; the admin can raise it.', 'b' => (int) $b['id'], 'd' => 'limit:' . (int) $b['id'] . ':' . $month . ':' . (int) $m]);
            $n += $notify->fetchColumn() !== null ? 1 : 0;
        }
    }
    return ['bases' => $bases, 'told' => $n];
}

/** Questions past the retention setting are purged (a question behind an active pin stays). ['purged' => n]; question.purge is logged by cron when any went. */
function retention_pass(PDO $pdo, DateTimeImmutable $now): array
{
    if (!daily_step_due($pdo, 'retention', $now)) {
        return ['due' => false];
    }
    $n = (int) one_value($pdo, 'SELECT kn_purge_questions()');
    if ($n > 0) {
        log_activity($pdo, 'question.purge', 'settings', 1, ['actor_member_id' => null, 'source' => 'cron',
            'after' => ['older_than_days' => (int) one_value($pdo, 'SELECT question_retention_days FROM kn_settings WHERE id = 1'), 'purged' => $n]]);
    }
    return ['purged' => $n];
}
