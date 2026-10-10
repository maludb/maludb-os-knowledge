<?php
declare(strict_types=1);

/**
 * The worker's step `feeds` (slice 8, agents-feeds.md "Feeds"): Knowledge as a consumer of K7. Each due feed reads its provider's index tool through the kernel's
 * POST /api/v1/apps/read.php (the application's token; the kernel checks the approved connection), every record becomes — or updates, or is forgotten as — a `feed`
 * source keyed by the provider's id, and the feed row says how it went. The worker has no member: it reads base tables, logs with actor null and source cron; no
 * row it writes carries a title or a word of a fed record.
 */
require_once APP_ROOT . '/app/features/feeds/write.php';
require_once APP_ROOT . '/app/features/feeds/queries.php';
require_once APP_ROOT . '/app/knowledge/extract/extract.php';

const FEED_ITEMS_PER_PASS = 20;
/** Keys under which a provider's answer may carry its records (the kernel's resolvers accept the same). */
const FEED_ENVELOPES = ['rows', 'items', 'results', 'candidates', 'pages', 'articles'];
/** A key in the answer that says there is more than this page. */
const FEED_PAGING = ['next', 'next_cursor', 'next_page', 'cursor', 'has_more', 'more', 'truncated'];

/**
 * The provider's answer as items: a bare list or an envelope (FEED_ENVELOPES). Per item id (id · uuid · page_id · article_id), title (title · name), version (updated_at ·
 * version · revised_at), body (markdown · body_md · body). An item with no id or no title is skipped and counted. ['items' => [{id, title, version, body}], 'skipped', 'complete'
 * (false when the answer carries a paging marker)].
 */
function feed_items(mixed $result): array
{
    $list = [];
    $complete = true;
    if (is_array($result) && array_is_list($result)) {
        $list = $result;
    } elseif (is_array($result)) {
        foreach (FEED_ENVELOPES as $k) {
            if (isset($result[$k]) && is_array($result[$k])) {
                $list = $result[$k];
                break;
            }
        }
        foreach (FEED_PAGING as $k) {
            if (!empty($result[$k])) {
                $complete = false;
            }
        }
    }
    $items = [];
    $skipped = 0;
    $pick = static function (array $row, array $keys): ?string {
        foreach ($keys as $k) {
            if (isset($row[$k]) && (is_string($row[$k]) || is_int($row[$k]) || is_float($row[$k])) && trim((string) $row[$k]) !== '') {
                return trim((string) $row[$k]);
            }
        }
        return null;
    };
    foreach ($list as $row) {
        if (!is_array($row)) {
            $skipped++;
            continue;
        }
        $id = $pick($row, ['id', 'uuid', 'page_id', 'article_id']);
        $title = $pick($row, ['title', 'name']);
        if ($id === null || $title === null) {
            $skipped++;
            continue;
        }
        $body = null;
        foreach (['markdown', 'body_md', 'body'] as $k) {
            if (isset($row[$k]) && is_string($row[$k]) && trim($row[$k]) !== '') {
                $body = $row[$k];
                break;
            }
        }
        $items[] = ['id' => $id, 'title' => $title, 'version' => $pick($row, ['updated_at', 'version', 'revised_at']), 'body' => $body];
    }
    return ['items' => $items, 'skipped' => $skipped, 'complete' => $complete];
}

/** The text a second read of the provider's markdown tool brings for one record, or null (no such tool, refused, empty). */
function feed_read_markdown(array $feed, string $itemId): ?string
{
    $spec = FEED_TOOL_SPECS[$feed['provider'] . '.' . $feed['tool']]['markdown'] ?? null;
    if ($spec === null) {
        return null;
    }
    $r = kernel_call('POST', '/api/v1/apps/read.php', ['provider' => $feed['provider'], 'tool' => $spec['tool'], 'arguments' => [$spec['argument'] => $itemId]], [], 15);
    if ($r === null || $r['status'] !== 200) {
        return null;
    }
    $res = $r['body']['result'] ?? null;
    if (is_string($res)) {
        return trim($res) === '' ? null : $res;
    }
    if (is_array($res)) {
        foreach (['markdown', 'body_md', 'text', 'body', 'content'] as $k) {
            if (isset($res[$k]) && is_string($res[$k]) && trim($res[$k]) !== '') {
                return $res[$k];
            }
        }
    }
    return null;
}

/** The hashes a fed record's text is known by: its raw Markdown and the text the worker's reader makes of it (what source_text holds once the source is cut). */
function feed_text_hashes(string $markdown, string $title): array
{
    $hashes = [hash('sha256', $markdown)];
    $ex = kn_extract_pasted_text($markdown, $title);
    if (!empty($ex['ok']) && isset($ex['text'])) {
        $hashes[] = hash('sha256', (string) $ex['text']);
    }
    return $hashes;
}

/** The feed row after a sync (or a refusal): when, how, and the next one every_minutes later. */
function feed_record_status(PDO $pdo, int $feedId, DateTimeImmutable $now, string $status, ?string $error, bool $more = false): void
{
    $pdo->prepare('UPDATE feeds SET last_sync_at = CAST(:now AS timestamptz), last_status = :s, last_error = :e,
                          next_sync_at = CAST(:now AS timestamptz) + make_interval(mins => every_minutes) WHERE id = :id')
        ->execute(['now' => agent_ts_feed($now), 's' => $status, 'e' => $error === null ? null : mb_substr($error, 0, 500), 'id' => $feedId]);
    if ($more) {                                                        // more to do than one pass takes: the next pass continues
        $pdo->prepare('UPDATE feeds SET next_sync_at = CAST(:now AS timestamptz) WHERE id = :id')->execute(['now' => agent_ts_feed($now), 'id' => $feedId]);
    }
}

function agent_ts_feed(DateTimeImmutable $now): string
{
    return $now->format('Y-m-d\TH:i:s.uP');
}

/**
 * Step feeds: up to $limit feeds due (enabled, next_sync_at ≤ now, the oldest first), each synced in turn. ['feeds', 'new', 'changed', 'gone', 'unchanged', 'skipped', 'failed'].
 */
function feeds_pass(PDO $pdo, DateTimeImmutable $now, int $limit = 5, float $deadline = INF): array
{
    $out = ['feeds' => 0, 'new' => 0, 'changed' => 0, 'gone' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0];
    $st = $pdo->prepare('SELECT f.* FROM feeds f JOIN knowledge_bases b ON b.id = f.base_id AND b.archived_at IS NULL
                          WHERE f.enabled AND f.next_sync_at <= CAST(:now AS timestamptz) ORDER BY f.next_sync_at, f.id LIMIT ' . max(1, $limit));
    $st->execute(['now' => agent_ts_feed($now)]);
    foreach ($st->fetchAll() as $f) {
        if (microtime(true) > $deadline) {
            break;
        }
        $out['feeds']++;
        $f['arguments'] = json_decode((string) $f['arguments'], true) ?: [];
        try {
            $r = feed_sync_one($pdo, $f, $now);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('feed ' . $f['id'] . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            feed_record_status($pdo, (int) $f['id'], $now, 'failed', 'The sync stopped part-way.');
            log_activity($pdo, 'feed.fail', 'feed', (int) $f['id'], ['actor_member_id' => null, 'source' => 'cron', 'base_id' => (int) $f['base_id'], 'after' => ['feed_id' => (int) $f['id'], 'status' => 'failed', 'code' => 'sync_error']]);
            $out['failed']++;
            continue;
        }
        foreach (['new', 'changed', 'gone', 'unchanged', 'skipped', 'failed'] as $k) {
            $out[$k] += $r[$k] ?? 0;
        }
    }
    return $out;
}

/** One feed: read, then new / changed / gone / unchanged; the feed row. */
function feed_sync_one(PDO $pdo, array $f, DateTimeImmutable $now): array
{
    $fid = (int) $f['id'];
    $bid = (int) $f['base_id'];
    $r = kernel_call('POST', '/api/v1/apps/read.php', ['provider' => $f['provider'], 'tool' => $f['tool'], 'arguments' => $f['arguments'] === [] ? new stdClass() : $f['arguments']], [], 15);
    $cron = ['actor_member_id' => null, 'source' => 'cron', 'base_id' => $bid];
    if ($r === null || $r['status'] === 0 || ($r['status'] !== 200 && $r['status'] < 400)) {
        feed_record_status($pdo, $fid, $now, 'failed', 'The kernel is not reachable right now.');
        log_activity($pdo, 'feed.fail', 'feed', $fid, $cron + ['after' => ['feed_id' => $fid, 'status' => 'failed', 'code' => $r === null || $r['status'] === 0 ? 'unreachable' : 'kernel_' . $r['status']]]);
        return ['failed' => 1];
    }
    if ($r['status'] !== 200) {
        $code = (string) ($r['body']['error']['code'] ?? ($r['status'] >= 500 ? 'kernel_' . $r['status'] : 'refused'));
        $words = (string) ($r['body']['error']['message'] ?? ($r['status'] >= 500 ? 'The kernel could not answer.' : 'The kernel refused.'));
        $status = match (true) {
            $code === 'no_connection' => 'no_connection',
            $r['status'] >= 500 => 'failed',                              // 502 provider_failed, 5xx
            default => 'refused',                                         // 403 not_shared / people_restricted, 422 …
        };
        feed_record_status($pdo, $fid, $now, $status, $status === 'no_connection' ? 'No approved connection to that tool — a super-admin approves it in the operating system.' : $words);
        log_activity($pdo, 'feed.fail', 'feed', $fid, $cron + ['after' => ['feed_id' => $fid, 'status' => $status, 'code' => $code]]);
        return $status === 'failed' ? ['failed' => 1] : [];
    }
    $doc = feed_items($r['body']['result'] ?? $r['body']);
    $c = ['new' => 0, 'changed' => 0, 'gone' => 0, 'unchanged' => 0, 'skipped' => $doc['skipped']];
    $seen = [];
    $done = 0;
    $more = false;
    foreach ($doc['items'] as $item) {
        $seen[$item['id']] = true;
    }
    $liveSt = $pdo->prepare("SELECT * FROM sources WHERE feed_id = :f AND feed_item_key = :k AND status <> 'forgotten'");
    foreach ($doc['items'] as $item) {
        $liveSt->execute(['f' => $fid, 'k' => $item['id']]);
        $live = $liveSt->fetch();
        $liveSt->closeCursor();
        if ($live !== false && $item['version'] !== null && $live['url_etag'] === $item['version']) {
            $c['unchanged']++;
            continue;
        }
        if ($done >= FEED_ITEMS_PER_PASS) {
            $more = true;
            continue;
        }
        $done++;
        $body = $item['body'] ?? feed_read_markdown($f, $item['id']);
        if ($body === null) {
            $c['skipped']++;
            continue;
        }
        try {
            $pdo->beginTransaction();
            if ($live === false) {
                add_feed_source($pdo, $f, $item, $body);
                $c['new']++;
            } elseif ($live['text_sha256'] !== null && in_array($live['text_sha256'], feed_text_hashes($body, $item['title']), true)) {
                if ($item['version'] !== null) {                               // the same words under a new version: only the version moves
                    $pdo->prepare('UPDATE sources SET url_etag = :v WHERE id = :id')->execute(['v' => $item['version'], 'id' => (int) $live['id']]);
                }
                $c['unchanged']++;
            } else {
                forget_feed_source($pdo, (int) $live['id'], 'replaced');             // the unique index allows one live source per feed item: the old goes first
                $new = add_feed_source($pdo, $f, $item, $body);
                $pdo->prepare('INSERT INTO source_focus_areas (source_id, focus_area_id, assigned_by) SELECT :n, focus_area_id, assigned_by FROM source_focus_areas WHERE source_id = :o ON CONFLICT DO NOTHING')
                    ->execute(['n' => $new, 'o' => (int) $live['id']]);
                $pdo->prepare('UPDATE sources SET superseded_by = :n WHERE id = :o')->execute(['n' => $new, 'o' => (int) $live['id']]);
                log_activity($pdo, 'source.replace', 'source', (int) $live['id'], $cron + ['after' => ['old_source_id' => (int) $live['id'], 'new_source_id' => $new, 'kind' => 'feed', 'feed_id' => $fid]]);
                $c['changed']++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('feed ' . $fid . ' item: ' . $e->getMessage());
            $c['skipped']++;
        }
    }
    if ($doc['complete']) {                                              // the index answered whole: a live source that is not in it is gone
        $liveAll = $pdo->prepare("SELECT id, feed_item_key FROM sources WHERE feed_id = :f AND status <> 'forgotten'");
        $liveAll->execute(['f' => $fid]);
        foreach ($liveAll->fetchAll() as $s) {
            if (!isset($seen[(string) $s['feed_item_key']])) {
                $pdo->beginTransaction();
                forget_feed_source($pdo, (int) $s['id'], 'gone');
                $pdo->commit();
                $c['gone']++;
            }
        }
    }
    feed_record_status($pdo, $fid, $now, 'ok', null, $more);
    $items = (int) one_value($pdo, 'SELECT items FROM feeds WHERE id = :id', ['id' => $fid]);
    log_activity($pdo, 'feed.sync', 'feed', $fid, $cron + ['after' => ['feed_id' => $fid, 'new' => $c['new'], 'changed' => $c['changed'], 'gone' => $c['gone'], 'unchanged' => $c['unchanged'],
        'skipped' => $c['skipped'], 'items' => $items, 'more' => $more]]);
    return $c;
}
