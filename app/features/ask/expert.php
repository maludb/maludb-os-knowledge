<?php
declare(strict_types=1);

/**
 * "Ask the expert" (slice 5, ask.md "Conversations, suggestions, feedback, pins, the expert"): an evidence-only question handed to the
 * application's expert through the kernel's chat endpoint (A6). One dispatch row per hand-off (agent_dispatches); the expert's reply is
 * the kernel's run — the row keeps 200 characters and the window reads the rest with GET ?run= (divergence 2).
 */

const KN_EXPERT_BUSY = 'The expert is busy — try again in a minute.';
const KN_EXPERT_UNREACHABLE = 'The kernel is not reachable right now.';
const KN_EXPERT_LATE = 'The expert did not finish.';
const KN_EXPERT_NOT_NAMED = 'No expert is named yet — an admin names it under Admin › Agents.';

/** The agent member named as the expert (kn_settings.expert_member_id), read from the base table: the name is not a secret, the setting is the admin's. */
function expert_member_id(PDO $pdo): ?int
{
    $v = one_value($pdo, 'SELECT expert_member_id FROM kn_settings WHERE id = 1');
    return $v === null ? null : (int) $v;
}

/** The kernel's answer about a run → the dispatch's status: answered | awaiting_approval | failed | null (still running). */
function expert_run_status(array $body): ?string
{
    $s = (string) ($body['status'] ?? '');
    if (empty($body['finished']) && !in_array($s, ['succeeded', 'failed', 'awaiting_approval', 'cancelled'], true)) {
        return null;
    }
    return match ($s) { 'succeeded' => 'answered', 'awaiting_approval' => 'awaiting_approval', default => 'failed' };
}

/** Write what a finished run says onto the dispatch (once): status, run, request, the excerpt, answered_at. Answers the update's row count. */
function expert_record_finish(PDO $pdo, int $dispatchId, array $body, ?string $requestId): int
{
    $status = expert_run_status($body) ?? 'failed';
    $st = $pdo->prepare("UPDATE agent_dispatches SET status = :s, run_id = COALESCE(:run, run_id), request_id = COALESCE(:rid, request_id), reply_excerpt = :ex,
                                detail = :detail, answered_at = now() WHERE id = :d AND status = 'sent'");
    $st->execute(['s' => $status, 'run' => isset($body['run_id']) ? (int) $body['run_id'] : null, 'rid' => $requestId ?? ($body['request_id'] ?? null),
                  'ex' => mb_substr((string) ($body['reply'] ?? ''), 0, 200), 'detail' => $status === 'failed' ? mb_substr((string) ($body['error'] ?? 'The run failed.'), 0, 200) : null, 'd' => $dispatchId]);
    return $st->rowCount();
}

/**
 * Start (or return the live) dispatch for a question. Refuses with DomainException sentences (kn_guard makes them 422 / 404). Answers
 * ['dispatch_id', 'status', 'reply' (the full reply when the kernel answered at once), 'detail', 'existing' (a live one returned), 'body'].
 */
function ask_expert_start(PDO $pdo, int $questionId, int $by): array
{
    $q = find_question($pdo, $questionId) ?? throw new DomainException('Not found.');
    $mine = (int) $q['asked_by_member_id'] === $by;
    if (!$mine && !db_bool($pdo, 'SELECT kn_can_curate_base(:b)', ['b' => (int) $q['base_id']])) {
        throw new DomainException('Not found.');
    }
    if ($q['status'] !== 'evidence_only') {
        throw new DomainException('The expert is asked about passages without an answer only.');
    }
    $expert = expert_member_id($pdo) ?? throw new DomainException(KN_EXPERT_NOT_NAMED);
    $live = $pdo->prepare("SELECT * FROM agent_dispatches WHERE record_type = 'question' AND record_id = :q AND kind = 'ask' AND status = 'sent' ORDER BY id DESC LIMIT 1");
    $live->execute(['q' => $questionId]);
    if ($row = $live->fetch(PDO::FETCH_ASSOC)) {
        return ['dispatch_id' => (int) $row['id'], 'status' => 'sent', 'reply' => null, 'detail' => null, 'existing' => true, 'body' => null];
    }
    $base = find_base($pdo, (int) $q['base_id']);
    $st = $pdo->prepare("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, base_id, status)
                         VALUES ('question', :q, :a, 'ask', 'chat', :m, :b, 'sent') RETURNING id");
    $st->execute(['q' => $questionId, 'a' => $expert, 'm' => $by, 'b' => (int) $q['base_id']]);
    $did = (int) $st->fetchColumn();
    $utterance = 'A person asked the knowledge base «' . ($base['name'] ?? '') . '» (base id ' . (int) $q['base_id'] . '): «' . $q['question'] . '». The engine had passages but no composed answer (question id '
        . $questionId . '). Answer from base ' . (int) $q['base_id'] . ' only, using the ask and search tools, citing the sections; if the base does not cover it, say so.';
    $r = kernel_call('POST', '/api/v1/agents/chat.php?agent=' . $expert, ['utterance' => $utterance, 'screen' => 'ask',
        'context' => ['application' => app_key(), 'entity' => 'question', 'record_id' => $questionId], 'conversation_id' => 'kq-' . $questionId, 'wait' => 15],
        ['X-Acting-Member: ' . $by], 25);
    return ['dispatch_id' => $did, 'existing' => false] + expert_take_answer($pdo, $did, $r);
}

/** The kernel's answer to a POST or a GET ?run= → the dispatch updated: ['status', 'reply', 'detail', 'body', 'finished_now']. */
function expert_take_answer(PDO $pdo, int $did, ?array $r): array
{
    $fail = static function (string $status, string $detail) use ($pdo, $did): array {
        $st = $pdo->prepare("UPDATE agent_dispatches SET status = :s, detail = :d, answered_at = now() WHERE id = :id AND status = 'sent'");
        $st->execute(['s' => $status, 'd' => mb_substr($detail, 0, 200), 'id' => $did]);
        return ['status' => $status, 'reply' => null, 'detail' => $detail, 'body' => null, 'finished_now' => $st->rowCount() === 1];
    };
    if ($r === null || $r['status'] >= 500 || $r['status'] === 0) {
        return $fail('failed', KN_EXPERT_UNREACHABLE);
    }
    $body = $r['body'] ?? [];
    if ($r['status'] === 409) {
        return $fail('failed', KN_EXPERT_BUSY);
    }
    if (in_array($r['status'], [401, 403, 404, 422], true)) {
        return $fail('refused', (string) ($body['error']['message'] ?? 'The kernel refused.'));
    }
    if ($r['status'] === 202 || expert_run_status($body) === null) {
        if (isset($body['run_id'])) {
            $pdo->prepare('UPDATE agent_dispatches SET run_id = :r, request_id = COALESCE(:rid, request_id) WHERE id = :id')->execute(['r' => (int) $body['run_id'], 'rid' => $r['request_id'] ?? null, 'id' => $did]);
        }
        return ['status' => 'sent', 'reply' => null, 'detail' => null, 'body' => $body, 'finished_now' => false];
    }
    $n = expert_record_finish($pdo, $did, $body, $r['request_id'] ?? null);
    $status = expert_run_status($body);
    return ['status' => $status, 'reply' => (string) ($body['reply'] ?? ''), 'detail' => $status === 'failed' ? (string) ($body['error'] ?? 'The run failed.') : null, 'body' => $body, 'finished_now' => $n === 1];
}

/**
 * The poll: the dispatch (mcp_agent_dispatches — the caller acting, or a curator); while sent with a run, the kernel asked again; ten minutes
 * with no finish → failed. Answers ['dispatch' => row, 'status', 'reply', 'detail', 'finished_now', 'running' (bool)] or throws 'Not found.'.
 */
function ask_expert_poll(PDO $pdo, int $dispatchId, int $by): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_agent_dispatches WHERE dispatch_id = :d');
    $st->execute(['d' => $dispatchId]);
    $d = $st->fetch(PDO::FETCH_ASSOC) ?: throw new DomainException('Not found.');
    if ((int) $d['acting_member_id'] !== $by && !($d['base_id'] !== null && db_bool($pdo, 'SELECT kn_can_curate_base(:b)', ['b' => (int) $d['base_id']]))) {
        throw new DomainException('Not found.');
    }
    if ($d['status'] === 'sent') {
        if (strtotime((string) $d['created_at']) < time() - 600) {
            $up = $pdo->prepare("UPDATE agent_dispatches SET status = 'failed', detail = :t, answered_at = now() WHERE id = :d AND status = 'sent'");
            $up->execute(['t' => KN_EXPERT_LATE, 'd' => $dispatchId]);
            return ['dispatch' => $d, 'status' => 'failed', 'reply' => null, 'detail' => KN_EXPERT_LATE, 'finished_now' => $up->rowCount() === 1, 'running' => false];
        }
        if ($d['run_id'] === null) {
            return ['dispatch' => $d, 'status' => 'sent', 'reply' => null, 'detail' => null, 'finished_now' => false, 'running' => true];
        }
        $r = kernel_call('GET', '/api/v1/agents/chat.php?run=' . (int) $d['run_id'], null, ['X-Acting-Member: ' . (int) $d['acting_member_id']], 10);
        if ($r === null || $r['status'] >= 500) {
            return ['dispatch' => $d, 'status' => 'sent', 'reply' => null, 'detail' => null, 'finished_now' => false, 'running' => true];   // try again at the next poll
        }
        $t = expert_take_answer($pdo, $dispatchId, $r);
        return ['dispatch' => $d, 'running' => $t['status'] === 'sent'] + $t;
    }
    return ['dispatch' => $d, 'running' => false, 'finished_now' => false] + expert_finished_reply($d);
}

/** A finished dispatch's reply: the run read again from the kernel (divergence 2), else the stored excerpt with a note. */
function expert_finished_reply(array $d): array
{
    $reply = null;
    $note = null;
    if ($d['status'] === 'answered' && $d['run_id'] !== null) {
        $r = kernel_call('GET', '/api/v1/agents/chat.php?run=' . (int) $d['run_id'], null, ['X-Acting-Member: ' . (int) $d['acting_member_id']], 10);
        if ($r !== null && $r['status'] === 200 && isset($r['body']['reply'])) {
            $reply = (string) $r['body']['reply'];
        } else {
            $reply = (string) $d['reply_excerpt'];
            $note = 'The full reply is in the OS\'s run log.';
        }
    }
    return ['status' => (string) $d['status'], 'reply' => $reply, 'detail' => $d['detail'], 'note' => $note];
}
