<?php
declare(strict_types=1);

/**
 * The expert, woken (slice 8, docs/build-specs/agents-feeds.md "The expert, woken"): the worker's three steps and the person's request.
 *   dispatch_after_build()   a base's first build is done → propose_areas (and draft_guidelines) dispatches for the expert, acting as the base's owner
 *   dispatches_pass()        the queue (status sent, no run yet): one chat turn each through the kernel's chat endpoint (A6) — 200 finished, 202 running, 403/404/422
 *                            refused, 409 / 5xx / unreachable / a failed run retried 4, 8, 16, 32 minutes later, the fifth failure final
 *   dispatch_poll_pass()     the 202'd turns: GET ?run= until finished; thirty minutes with no finish is a failure
 *   request_dispatch()       a person's "Propose areas" / "Draft guidelines from a sample" (the same row, acting as them)
 *   retry_dispatch()         the admin's Retry of a failed or refused one
 * The worker has no member: it reads base tables, logs with actor null and source cron. Rows of the trail carry ids, kinds, counts, a cost and a
 * status — never the utterance, the reply or a detail longer than 200 characters. K8 (the kernel waking an agent) is owed: when it lands the
 * call below becomes the wake call (via 'wake') and nothing else changes.
 *
 * The worker's queue and poll take the two kinds this slice makes (propose_areas, draft_guidelines) only: an `ask` dispatch (slice 5) is called
 * by its own request, in the instant between its insert and the kernel's answer it is not the worker's to call twice.
 */
require_once APP_ROOT . '/app/features/ask/expert.php';

const DISPATCH_WORKER_KINDS = ['propose_areas', 'draft_guidelines'];
const DISPATCH_MAX_ATTEMPTS = 5;
const DISPATCH_LEASE_MINUTES = 15;
const DISPATCH_LATE_MINUTES = 30;
const DISPATCH_FIRST_BUILD_DAYS = 7;
const DISPATCH_NO_EXPERT = 'no expert named';
const DISPATCH_NO_PERSON = 'no person to act for';

/** A timestamp for a bound CAST(:now AS timestamptz). */
function agent_ts(DateTimeImmutable $now): string
{
    return $now->format('Y-m-d\TH:i:s.uP');
}

/** A row of the worker's trail about a dispatch (source cron, actor none, base_id when it has one). */
function agent_log(PDO $pdo, string $action, array $d, array $after, array $opts = []): void
{
    $o = ['actor_member_id' => null, 'source' => 'cron', 'after' => $after] + $opts;
    if (!empty($d['base_id'])) {
        $o['base_id'] = (int) $d['base_id'];
    }
    log_activity($pdo, $action, 'agent_dispatch', isset($d['id']) ? (int) $d['id'] : null, $o);
}

/** agent.fail once per base and reason (a base waiting for the expert to be named is not logged every minute). */
function agent_fail_once(PDO $pdo, int $baseId, string $detail): void
{
    $seen = one_value($pdo, "SELECT 1 FROM activity_log WHERE action = 'agent.fail' AND base_id = :b AND after->>'detail' = :d LIMIT 1", ['b' => $baseId, 'd' => $detail]);
    if ($seen === null) {
        log_activity($pdo, 'agent.fail', 'base', $baseId, ['actor_member_id' => null, 'source' => 'cron', 'base_id' => $baseId, 'after' => ['base_id' => $baseId, 'detail' => $detail, 'attempts' => 0]]);
    }
}

/** The person a base's dispatch acts for: its owner when an active human, else the first active human among its curators; null when there is none. */
function dispatch_acting_member(PDO $pdo, int $baseId, ?int $ownerId): ?int
{
    if ($ownerId !== null && one_value($pdo, "SELECT 1 FROM members WHERE id = :m AND member_kind = 'human' AND status = 'active'", ['m' => $ownerId]) !== null) {
        return $ownerId;
    }
    $v = one_value($pdo, "SELECT m.id FROM kn_base_curator_ids(:b) AS c(id) JOIN members m ON m.id = c.id WHERE m.member_kind = 'human' AND m.status = 'active' ORDER BY m.id LIMIT 1", ['b' => $baseId]);
    return $v === null ? null : (int) $v;
}

/**
 * Step dispatch_after_build: every base whose FIRST extract / rebuild_all build is done (and finished inside the last seven days — an older graph is
 * not woken by an upgrade), whose template proposes areas from communities, that has no area at all and was never sent propose_areas, gets one
 * propose_areas dispatch and — when every guideline is still the template's and none was drafted — a draft_guidelines dispatch. The NOT EXISTS
 * of each INSERT is in its own statement: a second pass inserts nothing. ['bases' => qualifying, 'inserted' => rows].
 */
function dispatch_after_build(PDO $pdo, DateTimeImmutable $now): array
{
    $out = ['bases' => 0, 'inserted' => 0];
    $st = $pdo->prepare("SELECT b.id, b.owner_member_id, fb.id AS build_id
                           FROM knowledge_bases b
                           JOIN base_templates t ON t.key = b.template AND t.area_rule = 'communities'
                           JOIN LATERAL (SELECT x.id, x.finished_at FROM builds x WHERE x.base_id = b.id AND x.kind IN ('extract', 'rebuild_all') AND x.status = 'done'
                                          ORDER BY x.finished_at, x.id LIMIT 1) fb ON true
                          WHERE b.archived_at IS NULL AND fb.finished_at >= CAST(:now AS timestamptz) - make_interval(days => " . DISPATCH_FIRST_BUILD_DAYS . ")
                            AND NOT EXISTS (SELECT 1 FROM focus_areas a WHERE a.base_id = b.id)
                            AND NOT EXISTS (SELECT 1 FROM agent_dispatches d WHERE d.base_id = b.id AND d.kind = 'propose_areas')
                          ORDER BY b.id");
    $st->execute(['now' => agent_ts($now)]);
    $expert = expert_member_id($pdo);
    $insert = $pdo->prepare("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, base_id, build_id, status, next_attempt_at)
                              SELECT 'base', CAST(:b AS bigint), CAST(:agent AS bigint), CAST(:kind AS text), 'chat', CAST(:acting AS bigint), CAST(:b AS bigint), CAST(:build AS bigint), 'sent', CAST(:now AS timestamptz)
                               WHERE NOT EXISTS (SELECT 1 FROM agent_dispatches d WHERE d.base_id = CAST(:b AS bigint) AND d.kind = CAST(:kind AS text))
                                 AND (CAST(:kind AS text) <> 'propose_areas' OR NOT EXISTS (SELECT 1 FROM focus_areas a WHERE a.base_id = CAST(:b AS bigint)))
                                 AND (CAST(:kind AS text) <> 'draft_guidelines' OR NOT EXISTS (SELECT 1 FROM guidelines g WHERE g.base_id = CAST(:b AS bigint) AND g.origin <> 'template'))
                              RETURNING id");
    foreach ($st->fetchAll() as $b) {
        $out['bases']++;
        $bid = (int) $b['id'];
        if ($expert === null) {
            agent_fail_once($pdo, $bid, DISPATCH_NO_EXPERT);
            continue;
        }
        $acting = dispatch_acting_member($pdo, $bid, $b['owner_member_id'] === null ? null : (int) $b['owner_member_id']);
        if ($acting === null) {
            agent_fail_once($pdo, $bid, DISPATCH_NO_PERSON);
            continue;
        }
        foreach (DISPATCH_WORKER_KINDS as $kind) {
            $insert->execute(['b' => $bid, 'agent' => $expert, 'kind' => $kind, 'acting' => $acting, 'build' => (int) $b['build_id'], 'now' => agent_ts($now)]);   // due at this very pass
            $id = $insert->fetchColumn();
            $insert->closeCursor();
            if ($id !== false) {
                $out['inserted']++;
                agent_log($pdo, 'agent.dispatch', ['id' => (int) $id, 'base_id' => $bid], ['dispatch_id' => (int) $id, 'agent_member_id' => $expert, 'kind' => $kind, 'base_id' => $bid, 'build_id' => (int) $b['build_id']]);
            }
        }
    }
    return $out;
}

/** What the expert is told (the utterance — never logged). $d: a dispatch row, with base_name when the caller has it. */
function dispatch_utterance(array $d): string
{
    $name = (string) ($d['base_name'] ?? one_value(db(), 'SELECT name FROM knowledge_bases WHERE id = :b', ['b' => (int) ($d['base_id'] ?? 0)]) ?? '');
    $bid = (int) ($d['base_id'] ?? 0);
    $head = 'The first build of the knowledge base «' . $name . '» (base id ' . $bid . ') has finished. ';
    if (($d['kind'] ?? '') === 'draft_guidelines') {
        return $head . 'Following your runbook draft-guidelines-from-a-sample, read five to eight sections across its sources and save draft guidelines with guideline_save, every one with is_active false, '
            . 'at most one of each kind; list the vocabulary terms you would add in your reply instead of saving them. A curator will review them.';
    }
    return $head . 'Following your runbook propose-focus-areas, read its most connected subjects and their neighbourhoods with the graph tools (and MaluDB\'s communities when it computes them), '
        . 'and propose at most eight focus areas with focus_area_propose — each with a name, a one-sentence description, five to twelve seed terms and three example questions. Do not activate any.';
}

/**
 * Step dispatches: claim up to $limit queued turns in one transaction (SKIP LOCKED, a 15-minute lease on next_attempt_at so a pass that dies mid-call
 * cannot have its turn re-run sooner), commit, then call the kernel for each. ['called', 'answered', 'running', 'refused', 'retried', 'failed'].
 */
function dispatches_pass(PDO $pdo, DateTimeImmutable $now, int $limit = 5): array
{
    $c = ['called' => 0, 'answered' => 0, 'running' => 0, 'refused' => 0, 'retried' => 0, 'failed' => 0];
    $kinds = pg_array_literal(DISPATCH_WORKER_KINDS);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT d.*, b.name AS base_name FROM agent_dispatches d LEFT JOIN knowledge_bases b ON b.id = d.base_id
                              WHERE d.status = 'sent' AND d.run_id IS NULL AND d.via = 'chat' AND d.kind = ANY (CAST(:kinds AS text[])) AND d.next_attempt_at <= CAST(:now AS timestamptz)
                              ORDER BY d.next_attempt_at, d.id LIMIT " . max(1, $limit) . ' FOR UPDATE OF d SKIP LOCKED');
        $st->execute(['kinds' => $kinds, 'now' => agent_ts($now)]);
        $rows = $st->fetchAll();
        if ($rows !== []) {
            $pdo->prepare('UPDATE agent_dispatches SET next_attempt_at = CAST(:now AS timestamptz) + make_interval(mins => ' . DISPATCH_LEASE_MINUTES . ') WHERE id = ANY (CAST(:ids AS bigint[]))')
                ->execute(['now' => agent_ts($now), 'ids' => pg_array_literal(array_map(static fn (array $r): int => (int) $r['id'], $rows))]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    foreach ($rows as $d) {
        $c['called']++;
        $r = dispatch_turn($pdo, $d, $now);
        $key = match ($r['outcome']) { 'answered', 'awaiting_approval' => 'answered', default => $r['outcome'] };
        $c[$key] = ($c[$key] ?? 0) + 1;
    }
    return $c;
}

/** One turn: the kernel called as the expert for the acting person, the answer taken. ['outcome' => answered | awaiting_approval | running | refused | retried | failed]. */
function dispatch_turn(PDO $pdo, array $d, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($d['acting_member_id'] === null) {
        return dispatch_fail_for_good($pdo, $d, DISPATCH_NO_PERSON, $now);
    }
    $r = kernel_call('POST', '/api/v1/agents/chat.php?agent=' . (int) $d['agent_member_id'], [
        'utterance' => dispatch_utterance($d), 'screen' => 'base-view',
        'context' => ['application' => app_key(), 'entity' => 'base', 'record_id' => (int) $d['base_id']],
        'conversation_id' => 'kd-' . (int) $d['id'], 'wait' => 25,
    ], ['X-Acting-Member: ' . (int) $d['acting_member_id']], 40);
    return dispatch_take($pdo, $d, $r, $now);
}

/** The kernel's answer to a POST or a GET ?run= → the dispatch updated. */
function dispatch_take(PDO $pdo, array $d, ?array $r, DateTimeImmutable $now): array
{
    if ($r === null || $r['status'] === 0 || $r['status'] >= 500) {
        return dispatch_retry_later($pdo, $d, KN_EXPERT_UNREACHABLE, $now);
    }
    $body = $r['body'] ?? [];
    $status = (int) $r['status'];
    if ($status === 409) {
        return dispatch_retry_later($pdo, $d, KN_EXPERT_BUSY, $now);
    }
    if ($status >= 400) {                                           // 401, 403, 404, 422 …: the kernel's own sentence
        return dispatch_refuse($pdo, $d, (string) ($body['error']['message'] ?? 'The kernel refused.'), $now);
    }
    $run = expert_run_status($body);
    if ($status === 202 || $run === null) {                          // still running: keep the run, stay sent (divergence 5)
        if (isset($body['run_id'])) {
            $pdo->prepare("UPDATE agent_dispatches SET run_id = :r, request_id = COALESCE(:rid, request_id) WHERE id = :id AND status = 'sent'")
                ->execute(['r' => (int) $body['run_id'], 'rid' => $r['request_id'] ?? ($body['request_id'] ?? null), 'id' => (int) $d['id']]);
        }
        return ['outcome' => 'running'];
    }
    if ($run === 'failed') {
        if ((string) ($body['status'] ?? '') === 'cancelled') {
            return dispatch_fail_for_good($pdo, $d, 'The run was cancelled.', $now);
        }
        return dispatch_retry_later($pdo, $d, mb_substr((string) ($body['error'] ?? 'The run failed.'), 0, 200), $now);
    }
    return dispatch_finish($pdo, $d, $run, $body, $r['request_id'] ?? null, $now);
}

/** A finished run: answered or awaiting approval — written once, logged, the curators told. */
function dispatch_finish(PDO $pdo, array $d, string $run, array $body, ?string $requestId, DateTimeImmutable $now): array
{
    $reply = (string) ($body['reply'] ?? '');
    $detail = null;
    if ($run === 'awaiting_approval') {
        $detail = 'waiting for approval request ' . (string) ($body['approval_request_id'] ?? $body['approval_id'] ?? $requestId ?? $body['request_id'] ?? $d['request_id'] ?? '');
    }
    $st = $pdo->prepare("UPDATE agent_dispatches SET status = :s, run_id = COALESCE(:run, run_id), request_id = COALESCE(:rid, request_id), reply_excerpt = :ex, detail = :detail,
                                answered_at = CAST(:now AS timestamptz) WHERE id = :id AND status = 'sent'");
    $st->execute(['s' => $run, 'run' => isset($body['run_id']) ? (int) $body['run_id'] : null, 'rid' => $requestId ?? ($body['request_id'] ?? null), 'ex' => mb_substr($reply, 0, 200),
                  'detail' => $detail, 'now' => agent_ts($now), 'id' => (int) $d['id']]);
    if ($st->rowCount() === 1) {
        agent_log($pdo, 'agent.reply', $d, ['dispatch_id' => (int) $d['id'], 'status' => $run, 'run_id' => isset($body['run_id']) ? (int) $body['run_id'] : ($d['run_id'] === null ? null : (int) $d['run_id']),
            'request_id' => $requestId ?? ($body['request_id'] ?? $d['request_id']), 'reply_length' => mb_strlen($reply), 'cost' => $body['cost'] ?? null, 'currency' => $body['currency'] ?? null]);
        if ($run === 'answered') {
            dispatch_tell_curators($pdo, $d);
        }
    }
    return ['outcome' => $run];
}

/** After an answered propose_areas / draft_guidelines: the curators are told once, with how many; nothing made → nobody is told and the row says so. */
function dispatch_tell_curators(PDO $pdo, array $d): void
{
    if (!in_array($d['kind'], DISPATCH_WORKER_KINDS, true) || $d['base_id'] === null) {
        return;
    }
    $bid = (int) $d['base_id'];
    $agent = (int) $d['agent_member_id'];
    $name = (string) one_value($pdo, 'SELECT name FROM knowledge_bases WHERE id = :b', ['b' => $bid]);
    if ($d['kind'] === 'propose_areas') {
        $n = (int) one_value($pdo, "SELECT count(*) FROM focus_areas WHERE base_id = :b AND status = 'proposed' AND proposed_by = :a AND created_at >= (SELECT created_at FROM agent_dispatches WHERE id = :d)",
            ['b' => $bid, 'a' => $agent, 'd' => (int) $d['id']]);
        $title = 'The Knowledge Expert proposed ' . $n . ' focus area' . ($n === 1 ? '' : 's') . ' for «' . $name . '»';
        $key = 'areas-proposed:';
        $none = 'The expert proposed no focus areas.';
    } else {
        $n = (int) one_value($pdo, "SELECT count(*) FROM guidelines WHERE base_id = :b AND origin = 'agent' AND created_by = :a AND created_at >= (SELECT created_at FROM agent_dispatches WHERE id = :d)",
            ['b' => $bid, 'a' => $agent, 'd' => (int) $d['id']]);
        $title = 'The Knowledge Expert drafted ' . $n . ' guideline' . ($n === 1 ? '' : 's') . ' for «' . $name . '»';
        $key = 'guidelines-drafted:';
        $none = 'The expert drafted no guidelines.';
    }
    if ($n === 0) {
        $pdo->prepare('UPDATE agent_dispatches SET detail = :t WHERE id = :d')->execute(['t' => $none, 'd' => (int) $d['id']]);
        return;
    }
    $cur = $pdo->prepare('SELECT kn_base_curator_ids(:b)');
    $cur->execute(['b' => $bid]);
    $notify = $pdo->prepare("SELECT kn_notify(:m, 'proposal', :t, NULL, 'base', :b, :k)");
    foreach ($cur->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $notify->execute(['m' => (int) $m, 't' => $title, 'b' => $bid, 'k' => $key . (int) $d['id'] . ':' . (int) $m]);
    }
}

/** A refusal: the kernel's sentence kept (500 characters), final. */
function dispatch_refuse(PDO $pdo, array $d, string $words, DateTimeImmutable $now): array
{
    $st = $pdo->prepare("UPDATE agent_dispatches SET status = 'refused', detail = :t, answered_at = CAST(:now AS timestamptz) WHERE id = :id AND status = 'sent'");
    $st->execute(['t' => mb_substr($words, 0, 500), 'now' => agent_ts($now), 'id' => (int) $d['id']]);
    if ($st->rowCount() === 1) {
        agent_log($pdo, 'agent.fail', $d, ['dispatch_id' => (int) $d['id'], 'attempts' => (int) $d['attempts'], 'detail' => mb_substr($words, 0, 200), 'final' => true, 'status' => 'refused']);
    }
    return ['outcome' => 'refused'];
}

function dispatch_fail_for_good(PDO $pdo, array $d, string $words, DateTimeImmutable $now): array
{
    $st = $pdo->prepare("UPDATE agent_dispatches SET status = 'failed', detail = :t, answered_at = CAST(:now AS timestamptz) WHERE id = :id AND status = 'sent'");
    $st->execute(['t' => mb_substr($words, 0, 500), 'now' => agent_ts($now), 'id' => (int) $d['id']]);
    if ($st->rowCount() === 1) {
        agent_log($pdo, 'agent.fail', $d, ['dispatch_id' => (int) $d['id'], 'attempts' => (int) $d['attempts'], 'detail' => mb_substr($words, 0, 200), 'final' => true, 'status' => 'failed']);
    }
    return ['outcome' => 'failed'];
}

/**
 * A failure worth another try: attempts + 1 and the next call 2^attempts minutes later (4, 8, 16, 32); the fifth attempt's failure is final.
 * The run (if any) is cleared so the queue takes the row again.
 */
function dispatch_retry_later(PDO $pdo, array $d, string $words, DateTimeImmutable $now): array
{
    $attempts = (int) $d['attempts'];
    if ($attempts >= DISPATCH_MAX_ATTEMPTS) {
        return dispatch_fail_for_good($pdo, $d, $words, $now);
    }
    $n = $attempts + 1;
    $st = $pdo->prepare("UPDATE agent_dispatches SET attempts = :n, run_id = NULL, request_id = NULL, detail = :t,
                                next_attempt_at = CAST(:now AS timestamptz) + make_interval(mins => :mins) WHERE id = :id AND status = 'sent'");
    $st->execute(['n' => $n, 't' => mb_substr($words, 0, 500), 'now' => agent_ts($now), 'mins' => 2 ** $n, 'id' => (int) $d['id']]);
    if ($st->rowCount() === 1) {
        agent_log($pdo, 'agent.fail', $d, ['dispatch_id' => (int) $d['id'], 'attempts' => $n, 'detail' => mb_substr($words, 0, 200), 'final' => false, 'status' => 'retry']);
    }
    return ['outcome' => 'retried'];
}

/**
 * Step dispatch_poll: the 202'd turns (status sent, a run). The kernel is asked again with GET ?run=; finished → the same mapping as a turn; still going
 * → nothing; created more than thirty minutes ago and not finished → failed "the run did not finish". ['polled', 'answered', 'running', 'refused', 'retried', 'failed'].
 */
function dispatch_poll_pass(PDO $pdo, DateTimeImmutable $now): array
{
    $c = ['polled' => 0, 'answered' => 0, 'running' => 0, 'refused' => 0, 'retried' => 0, 'failed' => 0];
    $st = $pdo->prepare("SELECT * FROM agent_dispatches WHERE status = 'sent' AND run_id IS NOT NULL AND kind = ANY (CAST(:kinds AS text[])) ORDER BY id LIMIT 20");
    $st->execute(['kinds' => pg_array_literal(DISPATCH_WORKER_KINDS)]);
    foreach ($st->fetchAll() as $d) {
        $c['polled']++;
        $r = $d['acting_member_id'] === null ? null : kernel_call('GET', '/api/v1/agents/chat.php?run=' . (int) $d['run_id'], null, ['X-Acting-Member: ' . (int) $d['acting_member_id']], 15);
        $old = strtotime((string) $d['created_at']) < $now->getTimestamp() - DISPATCH_LATE_MINUTES * 60;
        $res = null;
        if ($r !== null && $r['status'] > 0 && $r['status'] < 500) {
            $res = dispatch_take($pdo, $d, $r, $now);
        }
        if ($res === null || $res['outcome'] === 'running') {
            if ($old) {
                $res = dispatch_fail_for_good($pdo, $d, 'the run did not finish', $now);
            } else {
                $c['running']++;
                continue;
            }
        }
        $key = match ($res['outcome']) { 'answered', 'awaiting_approval' => 'answered', default => $res['outcome'] };
        $c[$key] = ($c[$key] ?? 0) + 1;
    }
    return $c;
}

/** The admin's Retry: a failed or refused dispatch of this slice's kinds goes back in the queue, due now, its attempts kept. */
function retry_dispatch(PDO $pdo, int $id, int $by): void
{
    $d = $pdo->prepare('SELECT status, kind FROM agent_dispatches WHERE id = :id FOR UPDATE');
    $d->execute(['id' => $id]);
    $row = $d->fetch() ?: throw new DomainException('Not found.');
    if (!in_array($row['kind'], DISPATCH_WORKER_KINDS, true)) {
        throw new DomainException('An asked question goes to the expert again from the question itself.');
    }
    if (!in_array($row['status'], ['failed', 'refused'], true)) {
        throw new DomainException('Only a dispatch that failed or was refused is tried again.');
    }
    $pdo->prepare("UPDATE agent_dispatches SET status = 'sent', run_id = NULL, request_id = NULL, detail = NULL, answered_at = NULL, next_attempt_at = now() WHERE id = :id")->execute(['id' => $id]);
}

/**
 * A person asks for it (areas: propose_areas; guidelines: draft_guidelines): the expert named, the base with at least one extracted section; a live one
 * of that kind for the base is returned, not doubled. ['dispatch_id', 'existing'].
 */
function request_dispatch(PDO $pdo, int $baseId, string $kind, int $by): array
{
    if (!in_array($kind, DISPATCH_WORKER_KINDS, true)) {
        throw new DomainException('The expert proposes areas or drafts guidelines.');
    }
    $expert = expert_member_id($pdo) ?? throw new DomainException(KN_EXPERT_NOT_NAMED);
    $live = one_value($pdo, "SELECT id FROM agent_dispatches WHERE base_id = :b AND kind = :k AND status = 'sent' ORDER BY id DESC LIMIT 1", ['b' => $baseId, 'k' => $kind]);
    if ($live !== null) {
        return ['dispatch_id' => (int) $live, 'existing' => true];
    }
    if ((int) one_value($pdo, "SELECT count(*) FROM sections WHERE base_id = :b AND status = 'extracted'", ['b' => $baseId]) === 0) {
        throw new DomainException('Build the base first — the expert reads the graph, and nothing is in it yet.');
    }
    $st = $pdo->prepare("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, base_id, status)
                         VALUES ('base', :b, :a, :k, 'chat', :m, :b, 'sent') RETURNING id");
    $st->execute(['b' => $baseId, 'a' => $expert, 'k' => $kind, 'm' => $by]);
    return ['dispatch_id' => (int) $st->fetchColumn(), 'existing' => false];
}
