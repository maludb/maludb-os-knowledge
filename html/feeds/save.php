<?php
declare(strict_types=1);
/**
 * Action `feed_save` (feeds.manage on a curated base; free for an agent) — with or without `feed`: `base`, `provider` + `tool` (or the form's `source_tool`; only the index tools this
 * application declares as reads), `arguments` (a JSON object; or the tool's own fields, `space`, `category`), `focus_area`, `every_minutes` (5–10,080; 60), `enabled`. A field left out keeps its
 * value (an agent's `_partial=1` fills the rest from the feed's row). A new feed — or one whose tool or arguments changed — is due at once. Logged `feed.save` (feed_id, provider,
 * tool, focus_area_id, every_minutes, enabled). Location /bases/{id}/feeds#feed-{id}.
 */
require_once dirname(__DIR__, 2) . '/app/features/feeds/handler.php';
kn_handler_begin();
$pdo = db();
$cur = null;
if (request_integer('feed') !== null) {
    [$cur, $b] = feed_from_request($pdo);
} else {
    $b = base_from_request($pdo);
}
$bid = (int) $b['base_id'];
require_base_right($bid, 'feeds.manage');
require_base_live($b);
[$f, $errors] = feed_fields_from_request($pdo, $bid, $cur);
if ($errors !== []) {
    kn_refuse_fields($errors);
}
$me = (int) current_member_id();
$fid = $cur === null ? null : (int) $cur['feed_id'];
$id = kn_guard_fields($pdo, static function () use ($pdo, $fid, $bid, $f, $me): int {
    $pdo->beginTransaction();
    $id = save_feed($pdo, $fid, $bid, $f, $me);
    feed_log($pdo, 'feed.save', $bid, $id, ['feed_id' => $id, 'provider' => $f['provider'], 'tool' => $f['tool'], 'focus_area_id' => $f['focus_area_id'], 'every_minutes' => $f['every_minutes'],
        'enabled' => $f['enabled'], 'created' => $fid === null]);
    $pdo->commit();
    return $id;
}, feed_field_refusals());
kn_done($fid === null ? 'Following the feed' : 'Saved the feed', $id, kn_land('/bases/' . $bid . '/feeds', 'saved', 'feed-' . $id), 'feedChanged', ['base_id' => $bid]);
