<?php
declare(strict_types=1);

/**
 * Feeds — the writes of slice 8 (Knowledge as a consumer of K7): a base follows a sibling's shared index tool; each record the provider names becomes a `feed`
 * source keyed by the provider's id. The database is the referee (db/012): one feed per base, provider, tool and arguments; a feed's area is an area of its base;
 * one live source per feed item; a feed's item count follows its sources. The worker's sync is feeds/sync.php; these are what a person or an agent does and what
 * the sync shares: add_feed_source(), forget_feed_source().
 */
require_once APP_ROOT . '/app/features/sources/write.php';

/** Insert (no $id) or update a feed. $fields: provider, tool, arguments (array), focus_area_id (?int), every_minutes, enabled (bool). A new feed — or one whose provider, tool or arguments changed — is due at once. */
function save_feed(PDO $pdo, ?int $id, int $baseId, array $fields, int $by): int
{
    $args = json_encode($fields['arguments'] === [] ? new stdClass() : $fields['arguments'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $bind = ['p' => $fields['provider'], 't' => $fields['tool'], 'a' => $args, 'area' => $fields['focus_area_id'], 'e' => $fields['every_minutes'], 'en' => $fields['enabled'] ? 'true' : 'false'];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO feeds (base_id, provider, tool, arguments, focus_area_id, every_minutes, enabled, created_by, next_sync_at)
                             VALUES (:b, :p, :t, CAST(:a AS jsonb), :area, :e, :en, :by, now()) RETURNING id');
        $st->execute($bind + ['b' => $baseId, 'by' => $by]);
        return (int) $st->fetchColumn();
    }
    $cur = $pdo->prepare('SELECT provider, tool, arguments::text AS arguments FROM feeds WHERE id = :id FOR UPDATE');
    $cur->execute(['id' => $id]);
    $was = $cur->fetch() ?: throw new DomainException('Not found.');
    $moved = $was['provider'] !== $fields['provider'] || $was['tool'] !== $fields['tool'] || json_decode((string) $was['arguments'], true) != $fields['arguments'];
    $pdo->prepare('UPDATE feeds SET provider = :p, tool = :t, arguments = CAST(:a AS jsonb), focus_area_id = :area, every_minutes = :e, enabled = :en' . ($moved ? ', next_sync_at = now()' : '') . ' WHERE id = :id')
        ->execute($bind + ['id' => $id]);
    return $id;
}

/** "Sync now": due at the next worker pass. */
function request_feed_sync(PDO $pdo, int $id, int $by): void
{
    $st = $pdo->prepare('UPDATE feeds SET next_sync_at = now() WHERE id = :id');
    $st->execute(['id' => $id]);
    if ($st->rowCount() === 0) {
        throw new DomainException('Not found.');
    }
}

/** Delete a feed: its live sources are forgotten first (so the engine forgets what they taught), then the row. ['forgotten' => how many]. */
function delete_feed(PDO $pdo, int $id, int $by): array
{
    $live = $pdo->prepare("SELECT id FROM sources WHERE feed_id = :f AND status <> 'forgotten' ORDER BY id");
    $live->execute(['f' => $id]);
    $ids = $live->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $sid) {
        forget_feed_source($pdo, (int) $sid, 'feed_deleted');
    }
    $pdo->prepare('DELETE FROM feeds WHERE id = :id')->execute(['id' => $id]);
    return ['forgotten' => count($ids)];
}

/**
 * A fed source forgotten: status forgotten (the trigger forgets its sections, the worker the engine's documents) and its content wiped. Logged `source.forget`
 * (source_id, feed_id, reason) by whoever is acting — cron for the sync, web for a person's delete. $reason: gone | replaced | feed_deleted.
 */
function forget_feed_source(PDO $pdo, int $sourceId, string $reason = 'gone'): void
{
    $src = source_row($pdo, $sourceId);
    if ($src === null || $src['status'] === 'forgotten') {
        return;
    }
    $sections = (int) one_value($pdo, "SELECT count(*) FROM sections WHERE source_id = :id AND status <> 'forgotten'", ['id' => $sourceId]);
    $pdo->prepare("UPDATE sources SET status = 'forgotten' WHERE id = :id")->execute(['id' => $sourceId]);
    delete_files_after_commit([wipe_source_content($pdo, $sourceId)]);
    log_activity($pdo, 'source.forget', 'source', $sourceId, ['base_id' => (int) $src['base_id'], 'after' => ['source_id' => $sourceId, 'feed_id' => $src['feed_id'] === null ? null : (int) $src['feed_id'],
        'reason' => $reason, 'sections' => $sections]]);
}

/**
 * A feed's item as a source (status uploaded; the worker cleans and cuts the Markdown as it does pasted text): kind feed, origin feed:<provider>.<tool>#<id>,
 * keyed by (feed_id, feed_item_key), url_etag the provider's version, the feed's area assigned. Logged `source.add` (ids and kind — never the title or the text).
 * $item: id, title, version?; the source's id.
 */
function add_feed_source(PDO $pdo, array $feed, array $item, string $markdown): int
{
    $title = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $item['title'])), 0, 300);
    $st = $pdo->prepare("INSERT INTO sources (base_id, kind, title, origin, feed_id, feed_item_key, url_etag, status)
                         VALUES (:b, 'feed', :t, :o, :f, :k, :v, 'uploaded') RETURNING id");
    $st->execute(['b' => (int) $feed['base_id'], 't' => $title, 'o' => mb_substr('feed:' . $feed['provider'] . '.' . $feed['tool'] . '#' . $item['id'], 0, 500), 'f' => (int) $feed['id'],
                  'k' => (string) $item['id'], 'v' => $item['version'] === null ? null : (string) $item['version']]);
    $id = (int) $st->fetchColumn();
    $pdo->prepare("INSERT INTO source_text (source_id, text, extractor) VALUES (:s, :x, 'feed')")->execute(['s' => $id, 'x' => $markdown]);
    if ($feed['focus_area_id'] !== null) {
        $pdo->prepare('INSERT INTO source_focus_areas (source_id, focus_area_id) VALUES (:s, :a) ON CONFLICT DO NOTHING')->execute(['s' => $id, 'a' => (int) $feed['focus_area_id']]);
    }
    log_activity($pdo, 'source.add', 'source', $id, ['base_id' => (int) $feed['base_id'], 'after' => ['source_id' => $id, 'kind' => 'feed', 'feed_id' => (int) $feed['id'], 'feed_item_key' => (string) $item['id']]]);
    return $id;
}
