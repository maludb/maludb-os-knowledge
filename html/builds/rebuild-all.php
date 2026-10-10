<?php
declare(strict_types=1);
/**
 * Action `build_rebuild_all` (builds.run on a curated base; other — pauses for an agent; `confirm=1` required): every live section forgotten in
 * the engine and extracted again. The same refusals as build_start ("nothing to build" counts every live section). Logged `build.rebuild_all`.
 */
require_once dirname(__DIR__, 2) . '/app/features/builds/handler.php';
kn_handler_begin();
$pdo = db();
$b = base_from_request($pdo);
require_base_right((int) $b['base_id'], 'builds.run');
$est = build_start_gate($pdo, $b, 'rebuild_all');
if ((string) (req_val('confirm') ?? '') !== '1') {
    kn_refuse_fields(['confirm' => 'Confirm the rebuild: every section — ' . (int) $est['sections'] . ' sections, ' . number_format((int) $est['characters']) . ' characters — is extracted again.']);
}
build_start_commit($pdo, $b, 'rebuild_all', 'build.rebuild_all', $est);
