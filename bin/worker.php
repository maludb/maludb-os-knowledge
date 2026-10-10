<?php
declare(strict_types=1);

/**
 * The worker's pass (knowledge-worker.timer, once a minute): the one process that does what no request should wait for. It takes the
 * advisory lock (a second pass exits 0 at once, never overlaps), has a deadline of 50 s from its start (each step stops taking new work
 * past it and finishes the item in hand), runs its steps in order and logs `cron.run` with each step's count (source cron).
 *   slice 2: kn_worker_forget → kn_worker_purge_bases → kn_worker_recover → kn_worker_sources   (app/knowledge/worker/sources.php)
 *   slice 4: → kn_worker_builds (app/knowledge/worker/builds.php: the automatic queue, forget-then-ingest, retries, the guards); slice 7: → key_usage (kn_prune_key_usage())
 *   slice 8: → dispatch_after_build → dispatches → dispatch_poll (the expert, woken: app/features/agents/dispatch.php) → feeds → url_checks (app/features/feeds/{sync,urls}.php; a fed
 *   source is cut by the NEXT pass's sources step); the Archivist's duty is the kernel's schedule, not a step here. With slice 9, the retention sweep and the outbox.
 *   php bin/worker.php
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/knowledge/worker/sources.php';
require_once APP_ROOT . '/app/knowledge/worker/builds.php';
require_once APP_ROOT . '/app/features/agents/dispatch.php';          // slice 8: the expert, woken
require_once APP_ROOT . '/app/features/feeds/sync.php';
require_once APP_ROOT . '/app/features/feeds/urls.php';

$pdo = db();
if (!(bool) $pdo->query("SELECT pg_try_advisory_lock(hashtext('knowledge_worker'))")->fetchColumn()) {
    echo "another worker pass holds the lock — skipping.\n";
    exit(0);
}
$started = microtime(true);
// The clock the steps compare against. KN_WORKER_NOW (an ISO time) moves it — the proofs' seam, honoured only when APP_ENV is exactly "dev"; production reads the real one.
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
if (env('APP_ENV') === 'dev' && (string) env('KN_WORKER_NOW', '') !== '') {
    try {
        $now = new DateTimeImmutable((string) env('KN_WORKER_NOW'), new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        fwrite(STDERR, "KN_WORKER_NOW is not a time: ignored.\n");
    }
}
$deadline = $started + (float) (getenv('KN_WORKER_DEADLINE') ?: 50);
$done = [];
$steps = [
    'forget'  => static fn (): int => kn_worker_forget($pdo, $deadline),
    'purge'   => static fn (): int => kn_worker_purge_bases($pdo),
    'recover' => static fn (): int => kn_worker_recover($pdo),
    'sources' => static fn (): int => kn_worker_sources($pdo, $deadline),
    'builds'  => static fn (): int => kn_worker_builds($pdo, $deadline),
    'dispatch_after_build' => static fn (): int => dispatch_after_build($pdo, $now)['inserted'],                 // slice 8, in this order (agents-feeds.md)
    'dispatches' => static fn (): int => dispatches_pass($pdo, $now)['called'],
    'dispatch_poll' => static fn (): int => dispatch_poll_pass($pdo, $now)['polled'],
    'feeds' => static fn (): int => feeds_pass($pdo, $now, 5, $deadline)['feeds'],
    'url_checks' => static fn (): int => url_checks_pass($pdo, $now, 10, $deadline)['checked'],
    'key_usage' => static fn (): int => (int) $pdo->query('SELECT kn_prune_key_usage()')->fetchColumn(),      // slice 7: the rate buckets older than their keep (db/011)
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
