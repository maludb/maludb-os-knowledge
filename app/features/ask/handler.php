<?php
declare(strict_types=1);

/**
 * The prelude of the ask screens and handlers (slice 5): the question a request names (through mcp_questions — the asker's, or a
 * curator's; anyone else 404), the turn partial re-rendered for HTMX (a turn's thumbs, pin and expert controls swap the whole turn).
 */
require_once dirname(__DIR__) . '/bases/handler.php';
require_once APP_ROOT . '/app/knowledge/markdown.php';
require_once APP_ROOT . '/app/knowledge/ask.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/expert.php';

/** The question the request names (`question` or `id`) and its base row: [question, base]. Unseen is 404 "Question not found.". */
function question_from_request(PDO $pdo): array
{
    $id = request_integer('question') ?? request_integer('id') ?? refuse(422, 'Say which question.');
    $q = find_question($pdo, $id) ?? refuse(404, 'Question not found.');
    $b = find_base($pdo, (int) $q['base_id']) ?? refuse(404, 'Question not found.');
    return [$q, $b];
}

function question_log(PDO $pdo, string $action, array $q, array $after = [], string $entity = 'question', ?int $entityId = null): void
{
    log_activity($pdo, $action, $entity, $entityId ?? (int) $q['question_id'], ['base_id' => (int) $q['base_id'], 'after' => $after]);
}

/** May I pin on this base (answers.pin and a curator's level)? */
function may_pin(PDO $pdo, int $baseId): bool
{
    return has_right('answers.pin') && in_array(base_level($baseId), ['own', 'curate'], true);
}

/**
 * Everything a turn shows, for one question the caller sees: the row, its citations, the evidence, my rating, the pin, the dispatch and
 * what the caller may do. $ctx: 'in_window' (the window: Try again / Ask the whole base post a new turn), 'area_slug'.
 */
function ask_turn_data(PDO $pdo, array $q, array $ctx = []): array
{
    $qid = (int) $q['question_id'];
    $bid = (int) $q['base_id'];
    $level = base_level($bid);
    $curate = in_array($level, ['own', 'curate'], true);
    $dispatch = question_dispatch($pdo, $qid);
    $expert = null;
    if ($dispatch !== null) {
        $expert = $dispatch['status'] === 'sent' ? ['status' => 'sent', 'reply' => null, 'detail' => null, 'note' => null] : expert_finished_reply($dispatch);
    }
    return [
        'q' => $q, 'qid' => $qid, 'citations' => question_citations($pdo, $qid), 'evidence' => json_decode((string) $q['evidence'], true) ?: [],
        'rating' => my_rating($pdo, $qid), 'pinned' => (bool) $q['pinned'], 'dispatch' => $dispatch, 'expert' => $expert,
        'may' => ['rate' => has_right('answers.rate'), 'pin' => $q['status'] === 'answered' && !$q['pinned'] && may_pin($pdo, $bid), 'curate' => $curate,
                  'expert' => $q['status'] === 'evidence_only' && (current_member()['member_kind'] ?? '') === 'human' && ((int) $q['asked_by_member_id'] === (int) current_member_id() || $curate)],
        'expert_named' => expert_member_id($pdo) !== null, 'in_window' => !empty($ctx['in_window']),
        'area' => $q['focus_area_id'] !== null ? (one_value($pdo, 'SELECT name FROM focus_areas WHERE id = :a', ['a' => (int) $q['focus_area_id']]) ?? null) : null,
    ];
}

/** The turn partial's HTML for a question (or '' when it is not the caller's to see). */
function ask_turn_html(PDO $pdo, int $questionId, array $ctx = []): string
{
    $q = find_question($pdo, $questionId);
    return $q === null ? '' : view('ask/partials/turn.php', ask_turn_data($pdo, $q, $ctx));
}

/** A window control posted by HTMX with partial=turn answers the re-rendered turn; anything else lands as kn_done() says. */
function ask_turn_or_done(PDO $pdo, int $questionId, string $did, int|string|null $recordId, string $land, string $event, array $extra = []): never
{
    if (is_htmx_request() && (string) ($_POST['partial'] ?? '') === 'turn' && !wants_json()) {
        emit_action_status(true, ['did' => $did, 'record_id' => $recordId, 'refresh' => $event] + $extra);
        hx_trigger($event);
        header('Vary: HX-Request');
        echo ask_turn_html($pdo, $questionId, ['in_window' => true]);
        exit;
    }
    kn_done($did, $recordId, $land, $event, $extra);
}

/** agent.reply or agent.fail, once — when the kernel's answer finished the dispatch now ($r: expert_take_answer()/ask_expert_poll()). */
function expert_log_finish(PDO $pdo, array $q, int $dispatchId, array $r): void
{
    if (empty($r['finished_now'])) {
        return;
    }
    $opts = ['base_id' => (int) $q['base_id']];
    if (in_array($r['status'], ['answered', 'awaiting_approval'], true)) {
        $body = $r['body'] ?? [];
        log_activity($pdo, 'agent.reply', 'agent_dispatch', $dispatchId, $opts + ['after' => ['dispatch_id' => $dispatchId, 'run_id' => isset($body['run_id']) ? (int) $body['run_id'] : null,
            'request_id' => $body['request_id'] ?? null, 'reply_length' => mb_strlen((string) ($r['reply'] ?? '')), 'cost' => $body['cost'] ?? null, 'currency' => $body['currency'] ?? null, 'status' => $r['status']]]);
    } else {
        log_activity($pdo, 'agent.fail', 'agent_dispatch', $dispatchId, $opts + ['after' => ['dispatch_id' => $dispatchId, 'status' => $r['status'], 'detail' => mb_substr((string) ($r['detail'] ?? ''), 0, 200)]]);
    }
}

/** The base's log and gaps are its curators' (questions.review and a curator's level): a reader is told so (403), anyone else 404. */
function require_questions_review(int $baseId): void
{
    if (base_level($baseId) === 'read') {
        refuse(403, 'The questions asked of a base are its curators\' to review.');
    }
    require_base_right($baseId, 'questions.review');
}
