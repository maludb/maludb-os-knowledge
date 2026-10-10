<?php
declare(strict_types=1);
/**
 * Action `build_start` (builds.run on a curated base; free for an agent under the month's limit): the refusals in order (archived, the model not
 * set up, nothing to build, a build running, the month spent, an agent's build that does not fit), then one transaction: the build and its
 * items (queued, stale and failed sections). Logged `build.start` (sections, characters, the guideline version, the prompt's hash, within_limit,
 * auto false). Location /builds/{id}.
 */
require_once dirname(__DIR__, 2) . '/app/features/builds/handler.php';
kn_handler_begin();
$pdo = db();
$b = base_from_request($pdo);
require_base_right((int) $b['base_id'], 'builds.run');
$est = build_start_gate($pdo, $b, 'extract');
build_start_commit($pdo, $b, 'extract', 'build.start', $est);
