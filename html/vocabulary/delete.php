<?php
declare(strict_types=1);
/**
 * Action `vocabulary_delete` (base.manage on a curated base; deletion — pauses for an agent; confirmed): the trigger bumps the version; the
 * compiled prompt sent best-effort. The engine's subject stays (subjects are shared by every base of the memory database). Logged
 * `vocabulary.delete` + the version rows.
 */
require_once dirname(__DIR__, 2) . '/app/features/guidelines/handler.php';
require_once APP_ROOT . '/app/features/vocabulary/write.php';
kn_handler_begin();
$pdo = db();
[$t, $b] = term_from_request($pdo);
$bid = (int) $b['base_id'];
require_base_right($bid, 'base.manage');
require_base_live($b);
$me = (int) current_member_id();
$tid = (int) $t['term_id'];
$r = kn_guard_fields($pdo, static function () use ($pdo, $tid, $bid, $me): array {
    $pdo->beginTransaction();
    $r = delete_term($pdo, $tid, $me);
    guideline_log($pdo, 'vocabulary.delete', $bid, 'term', $tid, ['after' => ['term_id' => $tid, 'canonical_name' => $r['canonical_name']]]);
    log_version_move($pdo, $bid, $r);
    $pdo->commit();
    return $r;
}, term_field_refusals());
$sent = send_compiled_after($pdo, $bid, $r);
kn_done('Deleted the term ' . $t['canonical_name'], $tid, kn_land('/bases/' . $bid . '/vocabulary', 'removed'), 'vocabularyChanged', ['base_id' => $bid, 'version' => $r['base_version_after']]);
