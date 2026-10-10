<?php
declare(strict_types=1);
/**
 * /feeds/new?base={id} (screen `feed-add`) and /feeds/{id}/edit (screen `feed-edit`): the source (only the index tools this application declares as reads), its arguments (Spaces: the space's key;
 * Help Desk: an optional category), the area, every N minutes (5–10,080; 60), enabled. The base of a feed never changes.
 */
require_once dirname(__DIR__, 2) . '/app/features/feeds/handler.php';
require_human();
$pdo = db();
$id = request_integer('id');
$f = null;
if ($id !== null) {
    [$f, $b] = feed_from_request($pdo, 'feed-edit');
} else {
    $b = base_from_request($pdo, 'feed-add');
}
$bid = (int) $b['base_id'];
require_base_right($bid, 'feeds.manage');
require_base_live($b);
$screen = $f === null ? 'feed-add' : 'feed-edit';
base_screen_view($pdo, $screen, $bid);
$tools = feed_tools();
$areas = feed_areas($pdo, $bid);
if (wants_json()) {
    respond_screen(['feed' => $f === null ? null : present_feed($f), 'tools' => array_values($tools), 'areas' => $areas]);
}
render_screen($f === null ? 'Follow a feed' : 'Change the feed', view('feeds/form.php', ['f' => $f, 'b' => $b, 'tools' => $tools, 'areas' => $areas]),
    ['activeNav' => 'bases', 'screen' => $screen, 'entity' => 'feed', 'recordId' => $f === null ? '' : (string) $f['feed_id']]);
