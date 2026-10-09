<?php
declare(strict_types=1);
/**
 * Action `source_remove` (sources.manage on a curated base; deletion — pauses for an agent; confirmed) — one transaction: the source
 * forgotten (the trigger forgets its sections and recomputes the base) and its content wiped; the file deleted after the commit; the next
 * worker pass forgets it in the engine. Logged `source.remove` (title, sections). Location the base's sources.
 */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
kn_handler_begin();
$pdo = db();
[$s, $b] = source_from_request($pdo);
$bid = (int) $b['base_id'];
require_base_right($bid, 'sources.manage');
$sid = (int) $s['source_id'];
$me = (int) current_member_id();
$r = kn_guard_fields($pdo, static function () use ($pdo, $sid, $bid, $me): array {
    $pdo->beginTransaction();
    $r = remove_source($pdo, $sid, $me);
    source_log($pdo, 'source.remove', $bid, $sid, ['after' => ['source_id' => $sid, 'title' => $r['title'], 'sections' => $r['sections']]]);
    $pdo->commit();
    return $r;
}, source_field_refusals());
delete_files_after_commit([$r['file']]);
kn_done('Removed ' . $s['title'] . ' — the engine forgets its ' . $r['sections'] . ' section' . ($r['sections'] === 1 ? '' : 's') . ' within the minute', $sid,
    kn_land('/bases/' . $bid . '/sources', 'removed'), 'sourcesChanged', ['base_id' => $bid]);
