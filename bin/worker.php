<?php
declare(strict_types=1);

/**
 * The worker's pass (knowledge-worker.timer, once a minute): the one process that does what no request should wait for. It takes the
 * advisory lock (a second pass exits 0 at once, never overlaps), has a deadline of 50 s from its start (each step stops taking new work
 * past it and finishes the item in hand), runs its steps in order and logs `cron.run` with each step's count (source cron).
 *   slice 2: kn_worker_forget → kn_worker_purge_bases → kn_worker_recover → kn_worker_sources   (app/knowledge/worker/sources.php)
 *   slice 4: → kn_worker_builds (app/knowledge/worker/builds.php: the automatic queue, forget-then-ingest, retries, the guards); slice 7: key-usage pruning and the retention
 *   sweep; slice 8: feed polling, the outbox and the Archivist's duty — each added to the order below.
 *   php bin/worker.php
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/knowledge/worker/sources.php';
require_once APP_ROOT . '/app/knowledge/worker/builds.php';

$pdo = db();
if (!(bool) $pdo->query("SELECT pg_try_advisory_lock(hashtext('knowledge_worker'))")->fetchColumn()) {
    echo "another worker pass holds the lock — skipping.\n";
    exit(0);
}
$started = microtime(true);
$deadline = $started + (float) (getenv('KN_WORKER_DEADLINE') ?: 50);
$done = [];
$steps = [
    'forget'  => static fn (): int => kn_worker_forget($pdo, $deadline),
    'purge'   => static fn (): int => kn_worker_purge_bases($pdo),
    'recover' => static fn (): int => kn_worker_recover($pdo),
    'sources' => static fn (): int => kn_worker_sources($pdo, $deadline),
    'builds'  => static fn (): int => kn_worker_builds($pdo, $deadline),
];
foreach ($steps as $name => $step) {
    try {
        $done[$name] = $step();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("worker step $name: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $done[$name] = 'error';
    }
}
log_activity($pdo, 'cron.run', null, null, ['actor_member_id' => null, 'source' => 'cron', 'after' => ['steps' => $done, 'ms' => (int) round((microtime(true) - $started) * 1000)]]);
echo 'worker pass done: ' . implode(', ', array_map(static fn ($k, $v): string => "$k $v", array_keys($done), array_values($done))) . "\n";
exit(0);
