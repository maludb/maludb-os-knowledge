<?php
declare(strict_types=1);
/**
 * /bases/{id}/feeds (screen `feeds`, feeds.manage on a curated base): the feeds the base follows from sibling applications (mcp_feeds) — the source and the arguments in words, the area,
 * every N minutes, enabled, the last sync and its status chip with the last error, the items alive — as a table at 1280 and cards at 375; Follow a feed; per feed Edit · Sync now · Delete.
 * A feed whose connection is not approved shows "no connection": never an error page.
 */
require_once dirname(__DIR__, 2) . '/app/features/feeds/handler.php';
require_human();
$pdo = db();
$b = base_from_request($pdo, 'feeds');
$id = (int) $b['base_id'];
require_base_right($id, 'feeds.manage');
$feeds = find_feeds($pdo, $id);
$may = ['follow' => $b['archived_at'] === null && feed_tools() !== [], 'edit' => $b['archived_at'] === null];
base_screen_view($pdo, 'feeds', $id);
if (wants_json()) {
    respond_screen(['base_id' => $id, 'feeds' => array_map('present_feed', $feeds), 'tools' => array_values(array_map(static fn (array $t): array => ['provider' => $t['provider'], 'tool' => $t['tool'], 'label' => $t['label']], feed_tools()))]);
}
render_screen('Feeds of ' . $b['name'], view('feeds/index.php', ['b' => $b, 'feeds' => $feeds, 'may' => $may, 'tabs' => base_tabs($id, base_level($id)), 'tz' => member_timezone(),
    'notice' => kn_notice(request_string('notice') ?: null, feed_notices())]), ['activeNav' => 'bases', 'screen' => 'feeds', 'entity' => 'base', 'recordId' => (string) $id]);
