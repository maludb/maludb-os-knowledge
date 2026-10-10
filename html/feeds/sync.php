<?php
declare(strict_types=1);
/** Action `feed_sync` (feeds.manage on a curated base; free for an agent): `feed` — due now, the next worker pass reads it. Logged `feed.sync` (feed_id, requested true). Location /bases/{id}/feeds#feed-{id}. */
require_once dirname(__DIR__, 2) . '/app/features/feeds/handler.php';
kn_handler_begin();
$pdo = db();
[$f, $b] = feed_from_request($pdo);
$bid = (int) $b['base_id'];
$id = (int) $f['feed_id'];
require_base_right($bid, 'feeds.manage');
require_base_live($b);
$me = (int) current_member_id();
kn_guard($pdo, static function () use ($pdo, $id, $me, $bid): void {
    $pdo->beginTransaction();
    request_feed_sync($pdo, $id, $me);
    feed_log($pdo, 'feed.sync', $bid, $id, ['feed_id' => $id, 'requested' => true]);
    $pdo->commit();
});
kn_done('Sync requested — the next worker pass reads the feed', $id, kn_land('/bases/' . $bid . '/feeds', 'synced', 'feed-' . $id), 'feedChanged', ['base_id' => $bid]);
