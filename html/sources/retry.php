<?php
declare(strict_types=1);
/** Action `source_retry` (sources.add on a curated base; free) — a failed source back in the queue (no_text is deterministic and is not). Logged `source.retry`. */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
kn_handler_begin();
$pdo = db();
[$s, $b] = source_from_request($pdo);
$bid = (int) $b['base_id'];
require_base_right($bid, 'sources.add');
require_base_live($b);
$sid = (int) $s['source_id'];
$me = (int) current_member_id();
kn_guard_fields($pdo, static function () use ($pdo, $sid, $bid, $me, $s): void {
    $pdo->beginTransaction();
    retry_source($pdo, $sid, $me);
    source_log($pdo, 'source.retry', $bid, $sid, ['before' => ['status' => 'failed', 'error' => $s['error']], 'after' => ['status' => 'uploaded']]);
    $pdo->commit();
}, source_field_refusals());
kn_done($s['title'] . ' goes back in the queue', $sid, kn_land(return_path('/sources/' . $sid), 'retry'), 'sourcesChanged', ['base_id' => $bid]);
