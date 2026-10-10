<?php
declare(strict_types=1);

/** Feeds — what a screen shows and what JSON answers (slice 8). */

/** [words, colour] of a feed's last sync. */
function feed_status_chip(array $f): array
{
    return match ($f['last_status']) {
        'ok' => ['ok', 'success'], 'failed' => ['failed', 'danger'], 'no_connection' => ['no connection', 'warning'], 'refused' => ['refused', 'dark'], default => ['never synced', 'secondary'],
    };
}

/** The arguments in words: "Space: product", "Category: setup", "Every published article". */
function feed_arguments_words(array $f): string
{
    $a = $f['arguments'];
    if ($f['provider'] === 'spaces') {
        return 'Space: ' . (string) ($a['space'] ?? '—');
    }
    if ($f['provider'] === 'helpdesk') {
        return ($a['category'] ?? '') !== '' ? 'Category: ' . (string) $a['category'] : 'Every published article';
    }
    return $a === [] ? 'No arguments' : (string) json_encode($a, JSON_UNESCAPED_UNICODE);
}

function feed_source_words(array $f): string
{
    return (FEED_TOOL_SPECS[$f['provider'] . '.' . $f['tool']]['label'] ?? $f['provider'] . ' · ' . $f['tool']);
}

/** "every hour", "every 90 minutes", "every day". */
function feed_every_words(int $minutes): string
{
    if ($minutes === 60) {
        return 'every hour';
    }
    if ($minutes % 1440 === 0) {
        return $minutes === 1440 ? 'every day' : 'every ' . ($minutes / 1440) . ' days';
    }
    if ($minutes % 60 === 0) {
        return 'every ' . ($minutes / 60) . ' hours';
    }
    return 'every ' . $minutes . ' minutes';
}

function present_feed(array $f): array
{
    return [
        'feed_id' => (int) $f['feed_id'], 'base_id' => (int) $f['base_id'], 'provider' => $f['provider'], 'tool' => $f['tool'], 'arguments' => $f['arguments'], 'arguments_words' => feed_arguments_words($f),
        'focus_area_id' => $f['focus_area_id'] === null ? null : (int) $f['focus_area_id'], 'area' => $f['area_name'] ?? null, 'every_minutes' => (int) $f['every_minutes'], 'enabled' => (bool) $f['enabled'],
        'last_sync_at' => json_ts($f['last_sync_at']), 'next_sync_at' => json_ts($f['next_sync_at']), 'last_status' => $f['last_status'], 'last_error' => $f['last_error'], 'items' => (int) $f['items'],
    ];
}

function feed_notices(): array
{
    return [
        'saved'   => ['success', 'Saved the feed — it syncs on the next worker pass.'],
        'synced'  => ['success', 'Sync requested — the next worker pass reads the feed.'],
        'deleted' => ['warning', 'Stopped following that feed; the sources it brought were removed.'],
    ];
}
