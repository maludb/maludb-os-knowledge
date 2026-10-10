<?php
declare(strict_types=1);

/** The JSON shapes of a person's settings — whitelists, never a raw row; never a token's value or hash. */

function present_prefs(array $p): array
{
    return ['email_enabled' => (bool) $p['email_enabled'], 'text_enabled' => (bool) $p['text_enabled'], 'kinds' => array_values($p['kinds']),
            'text_kinds' => array_values($p['text_kinds']), 'saved' => (bool) ($p['saved'] ?? true)];
}

function present_token(array $t): array
{
    return ['token_id' => (int) $t['id'], 'label' => $t['label'], 'scope' => $t['scope'], 'created_at' => json_ts($t['created_at']),
            'last_used_at' => json_ts($t['last_used_at'] ?? null), 'expires_at' => json_ts($t['expires_at'] ?? null), 'revoked' => ($t['revoked_at'] ?? null) !== null];
}

function present_notification(array $n): array
{
    return ['notification_id' => (int) $n['notification_id'], 'kind' => $n['kind'], 'record_type' => $n['record_type'],
            'record_id' => $n['record_id'] !== null ? (int) $n['record_id'] : null, 'title' => $n['title'], 'body' => $n['body'],
            'read' => $n['read_at'] !== null, 'created_at' => json_ts($n['created_at']), 'url' => notification_record_url($n)];
}

/**
 * The screen a notification points at, by the record it names (null when none): a build → its build-view, a proposal → its base's proposals, a question → question-view, a pin → the pinned
 * answers due, a base → the base (the Monday note: its proposals), a source → source-view, an area → area-view.
 */
function notification_record_url(array $n): ?string
{
    $id = ($n['record_id'] ?? null) !== null ? (int) $n['record_id'] : null;
    if ($id === null) {
        return null;
    }
    $type = $n['record_type'] ?? null;
    if ($type === 'base' && ($n['kind'] ?? '') === 'monday_note') {
        return '/bases/' . $id . '/proposals';
    }
    if ($type === 'base' && ($n['kind'] ?? '') === 'proposal') {
        return '/bases/' . $id . '/proposals';
    }
    if ($type === 'archivist_proposal') {
        $b = one_value(db(), 'SELECT base_id FROM mcp_archivist_proposals WHERE proposal_id = :p', ['p' => $id]);
        return $b === null ? null : '/bases/' . (int) $b . '/proposals';
    }
    return match ($type) {
        'base' => '/bases/' . $id,
        'source' => '/sources/' . $id,
        'build' => '/builds/' . $id,
        'question' => '/questions/' . $id,
        'pinned_answer' => '/pinned?due=1',
        'focus_area' => '/areas/' . $id,
        default => null,
    };
}
