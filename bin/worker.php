<?php
declare(strict_types=1);

/**
 * The worker's pass (knowledge-worker.timer, once a minute): the one process that does what no request should wait for. PHASE 0 STUB —
 * the contract only: take the advisory lock (a second pass skips, never overlaps), log `cron.run` with source cron, exit 0 so
 * knowledge-worker.service starts cleanly. Phase 3 fills it, in the order its slices need: slice 2 source extraction and sectioning,
 * slice 4 the build (ingest section by section through app/knowledge/engine.php, forget-then-ingest, retries, cancel), slice 7
 * key-usage pruning (kn_prune_key_usage()) and the retention sweep, slice 8 feed polling and the Archivist's duty.
 *   php bin/worker.php
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';

$pdo = db();
if (!(bool) $pdo->query("SELECT pg_try_advisory_lock(hashtext('knowledge_worker'))")->fetchColumn()) {
    echo "another worker pass holds the lock — skipping.\n";
    exit(0);
}
$started = microtime(true);
$done = [];                                              // Phase 3: each step adds its name and count here
log_activity($pdo, 'cron.run', null, null, ['actor_member_id' => null, 'source' => 'cron', 'after' => ['steps' => $done, 'ms' => (int) round((microtime(true) - $started) * 1000)]]);
echo "worker pass done (stub): " . ($done === [] ? 'nothing to do' : implode(', ', $done)) . "\n";
exit(0);
