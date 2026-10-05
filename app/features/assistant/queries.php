<?php
declare(strict_types=1);

/**
 * The command bar's one call (sso-shell.md): the utterance goes to the kernel's chat endpoint as the acting person; ONE turn of
 * Knowledge's expert answers. Knowledge holds no model key. A long run is polled (GET ?run=) until finished or the wait is spent.
 * Answers ['reply', 'actions', 'navigate', 'run_id', 'status', 'finished', 'approval', 'cost', 'http', 'error'] — `error` set
 * when the kernel refused or could not be reached (its own words when it had any), `navigate` a local path the reply asks the
 * screen to open.
 */
function ask_assistant(PDO $pdo, int $memberId, string $utterance, array $context): array
{
    $conversation = (string) ($context['conversation_id'] ?? '');
    $answer = kernel_call('POST', '/api/v1/agents/chat.php?agent=expert', [
        'utterance' => $utterance, 'screen' => (string) ($context['screen'] ?? ''),
        'context' => ['entity' => (string) ($context['entity'] ?? ''), 'record_id' => $context['record_id'] ?? null, 'application' => app_key()],
        'conversation_id' => $conversation, 'wait' => 60,
    ], ['X-Acting-Member: ' . $memberId], 75);                     // the kernel holds the request up to `wait`
    $fallback = [
        400 => 'The kernel did not know who was asking.',
        403 => 'You are not allowed to use the assistant here.',
        404 => 'Knowledge has no expert yet — a super-admin names one in the kernel.',
        409 => 'The expert is busy; try again in a moment.',
        422 => 'Say what you want in a sentence or two.',
    ];
    $out = ['reply' => '', 'actions' => [], 'navigate' => null, 'run_id' => null, 'status' => null, 'finished' => false, 'approval' => null, 'cost' => null, 'http' => $answer['status'] ?? null, 'error' => null];
    if ($answer === null || $answer['status'] === 401 || $answer['status'] >= 500) {
        $out['error'] = 'The kernel is not reachable right now.';
        $out['http'] = $answer === null ? 503 : $answer['status'];
        return $out;
    }
    $body = $answer['body'] ?? [];
    if ($answer['status'] >= 400) {
        $out['error'] = (string) ($body['error']['message'] ?? ($fallback[$answer['status']] ?? 'The assistant could not answer.'));
        return $out;
    }
    $deadline = time() + 55;
    while ($answer['status'] === 202 && empty($body['finished']) && time() < $deadline && !empty($body['run_id'])) {
        usleep(1500000);
        $answer = kernel_call('GET', '/api/v1/agents/chat.php?run=' . (int) $body['run_id'], null, [], 20);
        if ($answer === null || $answer['status'] >= 400) {
            break;
        }
        $body = $answer['body'] ?? [];
    }
    $out['reply'] = (string) ($body['reply'] ?? '');
    $out['actions'] = is_array($body['actions'] ?? null) ? $body['actions'] : [];
    $out['run_id'] = isset($body['run_id']) ? (int) $body['run_id'] : null;
    $out['status'] = isset($body['status']) ? (string) $body['status'] : null;
    $out['finished'] = !empty($body['finished']);
    $out['approval'] = $body['approval_request_id'] ?? null;
    $out['cost'] = $body['cost'] ?? null;
    $out['navigate'] = safe_local_path(is_string($body['navigate'] ?? null) ? $body['navigate'] : null);
    return $out;
}

/** A tool `{entity}_{verb}` that succeeded refreshes the screens listening for `{entity}Changed` (chat-actions, Pattern D). */
function assistant_refresh_events(array $actions): array
{
    $families = ['base' => 'base', 'source' => 'source', 'section' => 'source', 'guideline' => 'guideline', 'vocabulary' => 'guideline', 'area' => 'area', 'build' => 'build',
        'question' => 'question', 'answer' => 'question', 'pin' => 'question', 'key' => 'key', 'feed' => 'feed', 'proposal' => 'proposal', 'prefs' => 'prefs',
        'token' => 'token', 'notification' => 'notification'];
    $events = [];
    foreach ($actions as $a) {
        if (($a['status'] ?? '') === 'ok' && preg_match('/^([a-z]+)(?:_[a-z_]+)?$/', (string) ($a['tool'] ?? ''), $m) && isset($families[$m[1]])) {
            $events[] = $families[$m[1]] . 'Changed';
        }
    }
    return array_values(array_unique($events));
}
