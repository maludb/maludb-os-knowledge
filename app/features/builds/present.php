<?php
declare(strict_types=1);

/** Builds — what a screen shows (slice 4, docs/build-specs/builds.md). BUILD_ERRORS: every code the worker writes into builds.error, in words. */

const BUILD_ERRORS = [
    'monthly_limit'        => 'This month\'s text limit was reached — {n} left for next month or a higher limit (the admin\'s).',
    'too_many_failures'    => 'Five sections in a row failed — the build stopped so it would not keep paying for failures. The reasons are below.',
    'engine_subject_ids'   => 'The engine refused new subjects twice (a known MaluDB defect, M7) — the build stopped. Tell the administrator.',
    'model_not_configured' => 'The engine\'s model is not set up (the admin\'s model screen).',
    'engine_unavailable'   => 'The engine could not be reached for 30 minutes.',
    'every_section_failed' => 'Every section failed — the reasons are below.',
    'vocabulary_failed'    => 'The vocabulary could not be given to the engine.',
    'stopped_guidelines'   => 'The guidelines changed during the build — {n} left for the next one.',
];
const BUILD_STATUSES = ['queued' => ['queued', 'secondary'], 'running' => ['running', 'info'], 'done' => ['done', 'success'], 'failed' => ['failed', 'danger'], 'cancelled' => ['cancelled', 'dark']];
const BUILD_ITEM_STATUSES = ['queued' => ['queued', 'secondary'], 'running' => ['running', 'info'], 'done' => ['done', 'success'], 'failed' => ['failed', 'danger'], 'skipped' => ['skipped', 'light']];

/** The words of a build's error code; {n} is the sections left (the skipped items of a finished build), "the sections left" when unknown. */
function build_error_words(?string $code, ?int $left = null): string
{
    if ($code === null || $code === '') {
        return '';
    }
    $n = $left === null ? 'the sections left' : $left . ' section' . ($left === 1 ? '' : 's');
    return str_replace('{n}', $n, BUILD_ERRORS[$code] ?? 'The build stopped: ' . $code . '.');
}

function build_status_chip(string $s): array { return BUILD_STATUSES[$s] ?? [$s, 'secondary']; }
function build_item_chip(string $s): array { return BUILD_ITEM_STATUSES[$s] ?? [$s, 'secondary']; }

/** A duration in words: 42 s, 3 min 10 s, 1 h 5 min. */
function build_duration(?int $seconds): string
{
    if ($seconds === null) {
        return '';
    }
    if ($seconds < 60) {
        return $seconds . ' s';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . ' min' . ($seconds % 60 ? ' ' . ($seconds % 60) . ' s' : '');
    }
    return intdiv($seconds, 3600) . ' h ' . intdiv($seconds % 3600, 60) . ' min';
}

function present_build(array $b): array
{
    return [
        'build_id' => (int) $b['build_id'], 'base_id' => (int) $b['base_id'], 'kind' => $b['kind'], 'status' => $b['status'], 'automatic' => $b['started_by'] === null,
        'guideline_version' => (int) $b['guideline_version'], 'started_by' => $b['started_by_name'], 'requested_at' => json_ts($b['requested_at']),
        'started_at' => json_ts($b['started_at']), 'finished_at' => json_ts($b['finished_at']), 'items_total' => (int) $b['items_total'], 'items_done' => (int) $b['items_done'],
        'items_failed' => (int) $b['items_failed'], 'chars_processed' => (int) $b['chars_processed'], 'statements_added' => (int) $b['statements_added'],
        'subjects_added' => (int) $b['subjects_added'], 'usage' => json_decode((string) $b['usage'], true) ?: new stdClass(), 'error' => $b['error'],
        'error_words' => build_error_words($b['error'], max(0, (int) $b['items_total'] - (int) $b['items_done'] - (int) $b['items_failed'])), 'url' => '/builds/' . (int) $b['build_id'],
    ];
}

/** Why the start button is disabled, or null when a build may start. $est: build_estimate(); $conn: kn_compile_connection(). */
function build_start_blocker(array $base, ?array $est, array $conn): ?string
{
    if ($base['archived_at'] !== null) {
        return 'An archived base is not built.';
    }
    if (!$conn['ok']) {
        return BUILD_ERRORS['model_not_configured'];
    }
    if ($est === null) {
        return 'Only the base\'s curators start a build.';
    }
    if ($est['running_build_id'] !== null) {
        return 'A build is already running.';
    }
    if ((int) $est['sections'] === 0) {
        return 'Nothing to build — every section is in the graph under version ' . (int) $est['guideline_version'] . '.';
    }
    if ((int) $est['remaining_chars'] === 0) {
        return 'This month\'s text limit for this base is spent.';
    }
    return null;
}

function build_notices(): array
{
    return ['started' => ['success', 'The build is queued: the worker starts it within the minute.'], 'cancelled' => ['warning', 'The build is cancelled.']];
}
