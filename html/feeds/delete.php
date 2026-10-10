<?php
declare(strict_types=1);
/**
 * Action `feed_delete` (feeds.manage on a curated base; confirmed; deletion — pauses for an agent): `feed`. The feed's live sources are forgotten first (the engine forgets what
 * they taught within the minute), then the row. Logged `feed.delete` (feed_id, provider, tool, forgotten) — its own event, so the kernel's approval policy pauses a delete and
 * not a save. Location /bases/{id}/feeds.
 */
require_once dirname(__DIR__, 2) . '/app/features/feeds/handler.php';
kn_handler_begin();
$pdo = db();
[$f, $b] = feed_from_request($pdo);
$bid = (int) $b['base_id'];
$id = (int) $f['feed_id'];
require_base_right($bid, 'feeds.manage');
$me = (int) current_member_id();
$r = kn_guard($pdo, static function () use ($pdo, $id, $me, $bid, $f): array {
    $pdo->beginTransaction();
    $r = delete_feed($pdo, $id, $me);
    feed_log($pdo, 'feed.delete', $bid, $id, ['feed_id' => $id, 'provider' => $f['provider'], 'tool' => $f['tool'], 'forgotten' => $r['forgotten']]);
    $pdo->commit();
    return $r;
});
kn_done('Stopped following the feed — ' . $r['forgotten'] . ' source' . ($r['forgotten'] === 1 ? '' : 's') . ' removed', $id, kn_land('/bases/' . $bid . '/feeds', 'deleted'), 'feedChanged', ['base_id' => $bid, 'forgotten' => $r['forgotten']]);
