<?php
declare(strict_types=1);

/** The dispatches — what a screen shows and what JSON answers (slice 8). */

/** [words, colour] per status; a sent dispatch with a run is "running". */
function dispatch_status_chip(array $d): array
{
    return match ($d['status']) {
        'sent' => [$d['run_id'] !== null ? 'running' : 'queued', 'secondary'],
        'answered' => ['answered', 'success'], 'awaiting_approval' => ['waiting for approval', 'warning'],
        'refused' => ['refused', 'dark'], default => ['failed', 'danger'],
    };
}

const DISPATCH_KIND_WORDS = ['ask' => 'Ask the expert', 'propose_areas' => 'Propose areas', 'draft_guidelines' => 'Draft guidelines', 'report' => 'Report'];

/** The OS's page for a run (the admin's link): the kernel's host is the launcher's with app. → os.; null when the launcher is not an address. */
function os_run_url(int $runId): ?string
{
    $u = parse_url((string) env('OS_LAUNCHER_URL', ''));
    if (empty($u['host'])) {
        return null;
    }
    return ($u['scheme'] ?? 'https') . '://' . preg_replace('/^app\./', 'os.', (string) $u['host']) . (isset($u['port']) ? ':' . $u['port'] : '') . '/ai/runs/' . $runId;
}

/** A dispatch for JSON: its facts, never the utterance; the excerpt is the reply's first 200 characters. */
function present_dispatch(array $d): array
{
    return [
        'dispatch_id' => (int) $d['dispatch_id'], 'kind' => $d['kind'], 'status' => $d['status'], 'base_id' => $d['base_id'] === null ? null : (int) $d['base_id'], 'base' => $d['base_name'] ?? null,
        'agent_member_id' => (int) $d['agent_member_id'], 'agent' => $d['agent_name'] ?? null, 'acting_member_id' => $d['acting_member_id'] === null ? null : (int) $d['acting_member_id'],
        'acting' => $d['acting_name'] ?? null, 'run_id' => $d['run_id'] === null ? null : (int) $d['run_id'], 'request_id' => $d['request_id'], 'attempts' => (int) $d['attempts'],
        'next_attempt_at' => json_ts($d['next_attempt_at']), 'created_at' => json_ts($d['created_at']), 'answered_at' => json_ts($d['answered_at']),
        'reply_excerpt' => $d['reply_excerpt'], 'detail' => $d['detail'],
    ];
}

function dispatch_notices(): array
{
    return [
        'retried'  => ['success', 'Back in the queue — the next worker pass calls the expert again.'],
        'asked'    => ['success', 'The Knowledge Expert has been asked — its proposals appear here.'],
        'already'  => ['info', 'The expert is already on it.'],
    ];
}
