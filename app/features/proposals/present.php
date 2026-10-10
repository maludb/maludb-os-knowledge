<?php
declare(strict_types=1);

/** The Archivist's proposals — what a screen shows and what JSON answers (slice 8). */

/** [words, icon] per kind. */
const PROPOSAL_KIND_CHIPS = [
    'area' => ['A new area', 'feather-target'], 'merge' => ['Duplicate subjects', 'feather-git-merge'], 'gap' => ['A gap', 'feather-help-circle'],
    'stale_source' => ['Needs a build', 'feather-refresh-cw'], 'failed_source' => ['A source failed', 'feather-alert-triangle'], 'review_pin' => ['A pin to review', 'feather-bookmark'],
    'unassigned' => ['In no area', 'feather-inbox'], 'unverified' => ['An unverified answer', 'feather-shield-off'], 'feed_stopped' => ['A feed stopped', 'feather-rss'],
    'limit_near' => ['Limit nearly spent', 'feather-bar-chart-2'], 'url_changed' => ['A page changed', 'feather-link'],
];
const PROPOSAL_STATUS_CHIPS = ['proposed' => ['proposed', 'info'], 'accepted' => ['accepted', 'success'], 'dismissed' => ['dismissed', 'secondary']];

function proposal_kind_chip(string $kind): array
{
    return [PROPOSAL_KIND_CHIPS[$kind][0] ?? $kind, 'light'];
}

function proposal_status_chip(string $status): array
{
    return PROPOSAL_STATUS_CHIPS[$status] ?? [$status, 'secondary'];
}

/** What Accept does, in words, beside its button (the screen's hint and the JSON's `accept`). */
function proposal_accept_words(array $p): string
{
    $reason = $p['payload']['reason'] ?? null;
    return match ($p['kind']) {
        'failed_source' => $reason === 'no_text' ? 'Opens the source — replace it or remove it there.' : 'Reads the source again.',
        'stale_source' => 'Starts a build of the new and stale sections.',
        'url_changed' => 'Opens the replace page for the source.',
        'feed_stopped' => 'Syncs the feed on the next worker pass.',
        'limit_near' => 'Opens the base\'s settings, where the limit is.',
        'gap' => $reason === 'thumbs_down' ? 'Opens the question.' : 'Opens the add-a-source page.',
        'unverified' => 'Opens the question.',
        'review_pin' => 'Verifies the pinned answer again.',
        'merge' => 'Folds each ticked group into one term of the vocabulary; the base is then marked for a rebuild.',
        'unassigned' => $p['subject_type'] === 'focus_area' ? 'Opens the area, where its sources are assigned.' : 'Opens the source, where its areas are set.',
        'area' => 'Creates the area (edit it first if you like).',
        default => 'Accepts the proposal.',
    };
}

/** A proposal for JSON: its facts and payload (the Archivist's own words), who decided. */
function present_proposal(array $p): array
{
    return [
        'proposal_id' => (int) $p['proposal_id'], 'base_id' => (int) $p['base_id'], 'base' => $p['base_name'] ?? null, 'kind' => $p['kind'], 'status' => $p['status'],
        'subject_type' => $p['subject_type'], 'subject_id' => $p['subject_id'] === null ? null : (int) $p['subject_id'], 'subject' => $p['subject']['label'] ?? null,
        'title' => $p['title'], 'reason' => $p['reason'], 'payload' => $p['payload'], 'accept' => proposal_accept_words($p),
        'proposed_by' => $p['proposed_by_name'] ?? null, 'created_at' => json_ts($p['created_at']), 'decided_at' => json_ts($p['decided_at']),
    ];
}

function proposal_notices(): array
{
    return [
        'accepted'  => ['success', 'Accepted.'],
        'dismissed' => ['success', 'Dismissed.'],
        'built'     => ['success', 'Accepted — the build is queued.'],
        'nothing'   => ['info', 'Accepted — nothing was waiting for a build any more.'],
    ];
}
