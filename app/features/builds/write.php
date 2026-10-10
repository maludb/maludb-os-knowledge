<?php
declare(strict_types=1);

/**
 * Builds — the writes of a person or an agent (slice 4). The database is the referee: one build per base, the guideline version stamped,
 * items that name live sections of the base, a build's status forward only. The worker's writes are app/knowledge/worker/builds.php's.
 */

/** Queue a build of the base: kind extract (queued, stale, failed sections) or rebuild_all (every live section). ['build_id', 'sections', 'characters']. */
function start_build(PDO $pdo, int $baseId, string $kind, int $by): array
{
    $st = $pdo->prepare('INSERT INTO builds (base_id, kind, started_by) VALUES (:b, :k, :by) RETURNING id');
    $st->execute(['b' => $baseId, 'k' => $kind, 'by' => $by]);
    $id = (int) $st->fetchColumn();
    $n = (int) one_value($pdo, 'SELECT kn_build_queue_items(:id)', ['id' => $id]);
    $chars = (int) one_value($pdo, 'SELECT COALESCE(sum(chars), 0) FROM build_items WHERE build_id = :id', ['id' => $id]);
    return ['build_id' => $id, 'sections' => $n, 'characters' => $chars];
}

/** Cancel a queued or running build (the trigger skips what is left; a finished build refuses in its own words). */
function cancel_build(PDO $pdo, int $buildId, int $by): array
{
    $pdo->prepare("UPDATE builds SET status = 'cancelled' WHERE id = :id")->execute(['id' => $buildId]);
    $st = $pdo->prepare("SELECT items_done, items_failed, (SELECT count(*) FROM build_items WHERE build_id = :id AND status = 'skipped') AS skipped FROM builds WHERE id = :id");
    $st->execute(['id' => $buildId]);
    $r = $st->fetch();
    return ['build_id' => $buildId, 'done' => (int) $r['items_done'], 'failed' => (int) $r['items_failed'], 'skipped' => (int) $r['skipped']];
}
