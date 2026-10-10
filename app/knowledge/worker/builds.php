<?php
declare(strict_types=1);

/**
 * The worker's build step (slice 4, docs/build-specs/builds.md "The worker's build step"): sections into the graph. Called by bin/worker.php after
 * slice 2's steps, inside the pass that holds the advisory lock and has a 50 s deadline. Base tables only; every row logged by cron with
 * base_id; never a section's text, an edge or a subject name in a log row.
 *   kn_worker_builds()    the clock, the automatic queue, then the builds oldest first, at most 10 items each per pass
 *   kn_build_prepare()    a queued build: the compiled prompt ensured, the vocabulary seeded, the areas linked, then running
 *   kn_build_item()       one section: claim, forget first, ingest, count, done or fail by the engine's code
 *   kn_build_finish()     done or failed, the base's counts, the log
 * The guards (all proven): one build per base; the month's text limit before every item, counting every paid attempt; five failures in a row;
 * a second duplicate-key refusal; three attempts an item; a timeout resolved by asking the engine before paying again; ten items a build a
 * pass; a guideline change mid-build stops it; the automatic queue takes new sections only, never while the engine is down or the month spent.
 */
require_once APP_ROOT . '/app/knowledge/engine.php';
require_once APP_ROOT . '/app/knowledge/compile.php';
require_once APP_ROOT . '/app/knowledge/areas.php';
require_once APP_ROOT . '/app/knowledge/extract/extract.php';

const KN_BUILD_ITEMS_PER_PASS = 10;
const KN_BUILD_FAILURES_IN_A_ROW = 5;
const KN_BUILD_ENGINE_DOWN = ['engine_unavailable', 'engine_auth', 'engine_forbidden'];

/** The worker's trail: cron, actor null, base_id always. */
function kn_build_log(PDO $pdo, string $action, int $baseId, ?string $entityType, ?int $entityId, array $after = []): void
{
    log_activity($pdo, $action, $entityType, $entityId, ['actor_member_id' => null, 'source' => 'cron', 'base_id' => $baseId, 'after' => $after]);
}

/** A line appended to the build's log (the first line is the prompt's hash, written by prepare). */
function kn_build_note(PDO $pdo, int $buildId, string $line): void
{
    $pdo->prepare("UPDATE builds SET log = COALESCE(log, '') || :l WHERE id = :id")->execute(['l' => $line . "\n", 'id' => $buildId]);
}

/** This month's characters sent for extraction by the base (a direct query: the worker has no member). */
function kn_build_month_used(PDO $pdo, int $baseId): int
{
    return (int) one_value($pdo, "SELECT COALESCE(chars, 0) FROM ai_usage WHERE base_id = :b AND kind = 'extract' AND month = date_trunc('month', now())::date", ['b' => $baseId]);
}

function kn_build_limit_chars(PDO $pdo, int $baseId): int
{
    return (int) one_value($pdo, 'SELECT monthly_text_mb_limit FROM knowledge_bases WHERE id = :b', ['b' => $baseId]) * 1048576;
}

/** A build fails with a code (a queued one passes through running: the trigger moves a status forward only). */
function kn_build_fail(PDO $pdo, int $buildId, string $code): void
{
    $pdo->prepare("UPDATE builds SET status = 'running' WHERE id = :id AND status = 'queued'")->execute(['id' => $buildId]);
    $pdo->prepare("UPDATE builds SET status = 'failed', error = :e WHERE id = :id AND status = 'running'")->execute(['e' => $code, 'id' => $buildId]);
    $b = $pdo->prepare('SELECT base_id, items_done, items_failed FROM builds WHERE id = :id');
    $b->execute(['id' => $buildId]);
    $r = $b->fetch();
    kn_build_log($pdo, 'build.fail', (int) $r['base_id'], 'build', $buildId, ['build_id' => $buildId, 'error' => $code, 'done' => (int) $r['items_done'], 'failed' => (int) $r['items_failed']]);
}

/** Add to the build's usage counters (calls, chars, attempts, retries, duplicate_key) — one worker holds the lock, so read-modify-write is safe. */
function kn_build_usage_add(PDO $pdo, int $buildId, array $add): array
{
    $u = json_decode((string) one_value($pdo, 'SELECT usage FROM builds WHERE id = :id', ['id' => $buildId]), true) ?: [];
    foreach ($add as $k => $v) {
        $u[$k] = (int) ($u[$k] ?? 0) + (int) $v;
    }
    $pdo->prepare('UPDATE builds SET usage = :u WHERE id = :id')->execute(['u' => json_encode($u), 'id' => $buildId]);
    return $u;
}

// ---------------------------------------------------------------------------------------------------------------------

/** The step: returns the number of items handled this pass. */
function kn_worker_builds(PDO $pdo, float $deadline): int
{
    if ((string) getenv('MALUDB_KB_API_TIMEOUT') === '') {
        putenv('MALUDB_KB_API_TIMEOUT=120');                   // a 6,000-character section is about four model calls inside one request
    }
    $up = kn_engine_available();
    if (!$up['ok']) {
        kn_build_clock($pdo);
        return 0;
    }
    kn_build_auto_queue($pdo);
    $builds = $pdo->query("SELECT id, base_id, status FROM builds WHERE status IN ('queued', 'running') AND kind IN ('extract', 'rebuild_all') ORDER BY requested_at, id")->fetchAll();
    $handled = 0;
    foreach ($builds as $b) {
        if (microtime(true) > $deadline) {
            break;
        }
        $id = (int) $b['id'];
        if ($b['status'] === 'queued') {
            $p = kn_build_prepare($pdo, $id);
            if ($p === 'down') {
                return $handled;                                 // every build waits for the next pass
            }
            if ($p !== 'running') {
                continue;
            }
        }
        $r = kn_build_run_items($pdo, $id, $deadline);
        $handled += $r['handled'];
        if ($r['down']) {
            return $handled;
        }
    }
    return $handled;
}

/** A build untouched for 30 minutes while the engine is down fails (the curators told once by the trigger). */
function kn_build_clock(PDO $pdo): int
{
    $st = $pdo->query("SELECT id FROM builds WHERE status IN ('queued', 'running') AND kind IN ('extract', 'rebuild_all') AND updated_at < now() - interval '30 minutes'");
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->prepare("UPDATE build_items SET status = 'queued' WHERE build_id = :b AND status = 'running'")->execute(['b' => (int) $id]);
        kn_build_fail($pdo, (int) $id, 'engine_unavailable');
        $n++;
    }
    return $n;
}

/**
 * Automatic builds (D9): a base with auto-extract on, not archived, holding a queued section, with no live build and this month's text not spent
 * gets a build of its QUEUED sections only (divergence 2). "Spent" means not even the smallest queued section fits what is left — else a build
 * that stopped at the limit would be queued again every pass, fail again, and tell the curators every minute. Answers how many were queued.
 */
function kn_build_auto_queue(PDO $pdo): int
{
    $bases = $pdo->query("SELECT b.id, b.monthly_text_mb_limit FROM knowledge_bases b
                           WHERE b.auto_extract AND b.archived_at IS NULL AND b.key NOT LIKE 'deleting-%'
                             AND EXISTS (SELECT 1 FROM sections x WHERE x.base_id = b.id AND x.status = 'queued')
                             AND NOT EXISTS (SELECT 1 FROM builds x WHERE x.base_id = b.id AND x.status IN ('queued', 'running'))
                           ORDER BY b.id")->fetchAll();
    $n = 0;
    foreach ($bases as $base) {
        $bid = (int) $base['id'];
        $smallest = (int) one_value($pdo, "SELECT min(length(text)) FROM sections WHERE base_id = :b AND status = 'queued'", ['b' => $bid]);
        if (kn_build_month_used($pdo, $bid) + $smallest > (int) $base['monthly_text_mb_limit'] * 1048576) {
            continue;                                       // the month is spent for this base: not even its smallest new section fits
        }
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare("INSERT INTO builds (base_id, kind) VALUES (:b, 'extract') RETURNING id");
            $st->execute(['b' => $bid]);
            $id = (int) $st->fetchColumn();
            $ins = $pdo->prepare("INSERT INTO build_items (build_id, section_id, chars) SELECT :id, s.id, length(s.text) FROM sections s WHERE s.base_id = :b AND s.status = 'queued'");
            $ins->execute(['id' => $id, 'b' => $bid]);
            $sections = $ins->rowCount();
            $chars = (int) one_value($pdo, 'SELECT COALESCE(sum(chars), 0) FROM build_items WHERE build_id = :id', ['id' => $id]);
            kn_build_log($pdo, 'build.start', $bid, 'build', $id, ['build_id' => $id, 'kind' => 'extract', 'sections' => $sections, 'characters' => $chars,
                'guideline_version' => (int) one_value($pdo, 'SELECT guideline_version FROM builds WHERE id = :id', ['id' => $id]), 'auto' => true]);
            $pdo->commit();
            $n++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('auto build of base ' . $bid . ': ' . $e->getMessage());
        }
    }
    return $n;
}

/**
 * A queued build: the compiled prompt ensured (the engine's must hash to it), the vocabulary seeded (no model call; each term's subject id
 * written), every active area linked (a failure is a log line), then running with the hash line first in the log. Answers running | down |
 * failed.
 */
function kn_build_prepare(PDO $pdo, int $buildId): string
{
    $b = $pdo->prepare('SELECT base_id, guideline_version FROM builds WHERE id = :id');
    $b->execute(['id' => $buildId]);
    $build = $b->fetch();
    $bid = (int) $build['base_id'];
    $ensure = kn_compile_ensure($pdo, $bid);
    if (!$ensure['ok']) {
        if (in_array($ensure['code'], KN_BUILD_ENGINE_DOWN, true) || $ensure['code'] === 'engine_timeout') {
            return 'down';
        }
        kn_build_fail($pdo, $buildId, $ensure['code'] === 'model_not_configured' || $ensure['code'] === 'model_api_key_missing' ? 'model_not_configured' : (string) $ensure['code']);
        return 'failed';
    }
    $voc = kn_compile_vocabulary($pdo, $bid);
    $seeded = 0;
    foreach (array_chunk($voc['terms'], 500) as $batch) {
        $s = kn_engine_seed_subjects($batch);
        if (!$s['ok']) {
            if (in_array($s['code'], KN_BUILD_ENGINE_DOWN, true)) {
                return 'down';
            }
            kn_build_fail($pdo, $buildId, 'vocabulary_failed');
            return 'failed';
        }
        $w = $pdo->prepare('UPDATE vocabulary_terms SET maludb_subject_id = :s WHERE base_id = :b AND lower(canonical_name) = lower(:n) AND maludb_subject_id IS DISTINCT FROM :s');
        foreach ($s['data']['subjects'] ?? [] as $sub) {
            $w->execute(['s' => (string) $sub['subject_id'], 'b' => $bid, 'n' => (string) $sub['canonical_name']]);
            $seeded++;
        }
    }
    if ($voc['terms'] !== []) {
        kn_build_log($pdo, 'vocabulary.sync', $bid, 'base', $bid, ['build_id' => $buildId, 'terms' => count($voc['terms']), 'seeded' => $seeded]);
    }
    $areas = $pdo->prepare("SELECT id FROM focus_areas WHERE base_id = :b AND status = 'active' ORDER BY position, id");
    $areas->execute(['b' => $bid]);
    $linked = 0;
    $notes = [];
    foreach ($areas->fetchAll(PDO::FETCH_COLUMN) as $areaId) {
        $r = kn_area_engine_sync($pdo, (int) $areaId);
        $r['ok'] ? $linked++ : $notes[] = 'area ' . (int) $areaId . ' not linked: ' . ($r['code'] ?? 'engine_error');
    }
    $hash = 'prompt sha256=' . $ensure['sha256'] . ' guideline_version=' . (int) $build['guideline_version'] . ' terms=' . count($voc['terms']) . ' areas=' . $linked;
    $pdo->prepare("UPDATE builds SET status = 'running', log = :log WHERE id = :id AND status = 'queued'")->execute(['log' => $hash . "\n" . implode('', array_map(static fn ($n) => $n . "\n", $notes)), 'id' => $buildId]);
    return 'running';
}

/** Up to ten items of a running build; finishes the build when none is left. ['handled', 'down']. */
function kn_build_run_items(PDO $pdo, int $buildId, float $deadline): array
{
    $st = $pdo->prepare("SELECT i.id FROM build_items i JOIN sections x ON x.id = i.section_id JOIN sources s ON s.id = x.source_id
                          WHERE i.build_id = :b AND i.status = 'queued' ORDER BY s.added_at, s.id, x.position, i.id LIMIT " . KN_BUILD_ITEMS_PER_PASS);
    $st->execute(['b' => $buildId]);
    $items = $st->fetchAll(PDO::FETCH_COLUMN);
    $handled = 0;
    foreach ($items as $itemId) {
        if (microtime(true) > $deadline) {
            break;
        }
        $r = kn_build_item($pdo, $buildId, (int) $itemId);
        if ($r === 'down') {
            return ['handled' => $handled, 'down' => true];
        }
        if ($r === 'stop') {
            return ['handled' => $handled, 'down' => false];
        }
        $handled++;
        $b = $pdo->prepare('SELECT items_done, items_failed, items_total, base_id FROM builds WHERE id = :id');
        $b->execute(['id' => $buildId]);
        $c = $b->fetch();
        if (($c['items_done'] + $c['items_failed']) % 25 === 0) {
            kn_build_log($pdo, 'build.progress', (int) $c['base_id'], 'build', $buildId, ['done' => (int) $c['items_done'], 'failed' => (int) $c['items_failed'], 'total' => (int) $c['items_total']]);
        }
    }
    $status = (string) one_value($pdo, 'SELECT status FROM builds WHERE id = :id', ['id' => $buildId]);
    if ($status === 'running') {
        $left = (int) one_value($pdo, "SELECT count(*) FROM build_items WHERE build_id = :b AND status IN ('queued', 'running')", ['b' => $buildId]);
        $c = $pdo->prepare('SELECT items_done, items_failed, items_total, base_id FROM builds WHERE id = :id');
        $c->execute(['id' => $buildId]);
        $row = $c->fetch();
        if ($left === 0) {
            kn_build_finish($pdo, $buildId);
        } elseif ($handled > 0) {
            kn_build_log($pdo, 'build.progress', (int) $row['base_id'], 'build', $buildId, ['done' => (int) $row['items_done'], 'failed' => (int) $row['items_failed'], 'total' => (int) $row['items_total']]);
        }
    }
    return ['handled' => $handled, 'down' => false];
}

/**
 * One item. Answers done | failed | skipped | down (the engine is unreachable: the item back to queued, every build waits) | stop (this build
 * ended: cancelled, the guidelines changed, the month spent, a guard stopped it).
 */
function kn_build_item(PDO $pdo, int $buildId, int $itemId): string
{
    $bq = $pdo->prepare('SELECT b.status, b.base_id, b.guideline_version, k.guideline_version AS base_version, k.namespace FROM builds b JOIN knowledge_bases k ON k.id = b.base_id WHERE b.id = :id');
    $bq->execute(['id' => $buildId]);
    $build = $bq->fetch();
    if ($build === false || $build['status'] !== 'running') {
        return 'stop';
    }
    $bid = (int) $build['base_id'];
    if ((int) $build['base_version'] > (int) $build['guideline_version']) {
        kn_build_finish($pdo, $buildId, 'stopped_guidelines');
        return 'stop';
    }
    $chars = (int) one_value($pdo, 'SELECT chars FROM build_items WHERE id = :i', ['i' => $itemId]);
    if (kn_build_month_used($pdo, $bid) + $chars > kn_build_limit_chars($pdo, $bid)) {
        kn_build_fail($pdo, $buildId, 'monthly_limit');
        return 'stop';
    }
    $claim = $pdo->prepare("UPDATE build_items SET status = 'running' WHERE id = :i AND status = 'queued'");
    $claim->execute(['i' => $itemId]);
    if ($claim->rowCount() !== 1) {
        return 'skipped';
    }
    $sq = $pdo->prepare('SELECT x.*, s.title AS source_title, s.base_id AS source_base FROM sections x JOIN sources s ON s.id = x.source_id JOIN build_items i ON i.section_id = x.id WHERE i.id = :i');
    $sq->execute(['i' => $itemId]);
    $sec = $sq->fetch();
    if ($sec === false || $sec['status'] === 'forgotten') {
        $pdo->prepare("UPDATE build_items SET status = 'skipped' WHERE id = :i")->execute(['i' => $itemId]);
        return 'skipped';
    }
    $sid = (int) $sec['id'];
    // forget first: a section built before holds its old document
    if ($sec['maludb_document_id'] !== null) {
        $old = (string) $sec['maludb_document_id'];
        $f = ctype_digit($old) ? kn_engine_forget_document((int) $old) : ['ok' => false, 'code' => 'not_found'];
        if (!$f['ok'] && in_array($f['code'], KN_BUILD_ENGINE_DOWN, true)) {
            $pdo->prepare("UPDATE build_items SET status = 'queued' WHERE id = :i")->execute(['i' => $itemId]);
            kn_build_wait_note($pdo, $buildId, $f['code']);
            return 'down';
        }
        if ($f['ok'] || $f['code'] === 'not_found') {
            $pdo->prepare('UPDATE sections SET maludb_document_id = NULL WHERE id = :s AND maludb_document_id = :old')->execute(['s' => $sid, 'old' => $old]);
        }
    }
    $ns = (string) $build['namespace'];
    $title = kn_section_title_for_engine(['heading_path' => $sec['heading_path']], (string) $sec['source_title']);
    $meta = kn_section_metadata($bid, (int) $sec['source_id'], $sid, ['page_no' => $sec['page_no'], 'heading_path' => $sec['heading_path'], 'position' => $sec['position']]);
    $projects = kn_compile_projects($pdo, (int) $sec['source_id']);
    $seeds = kn_build_seed_terms($pdo, (int) $sec['source_id']);
    static $vocab = [];
    $vocab[$bid] ??= kn_compile_vocabulary($pdo, $bid);
    $hints = kn_compile_section_hints($vocab[$bid], $seeds, (string) $sec['text']);
    $text = (string) $sec['text'];
    $attempts = 0;
    $dupHere = false;
    while (true) {
        $attempts++;
        $began = time();
        $r = kn_engine_ingest_section($ns, $title, $text, $meta, $projects, $hints);
        $paid = $r['ok'] || $r['status'] > 0 || $r['code'] === 'engine_timeout';
        if ($paid) {
            $calls = (int) ($r['data']['chunk_count'] ?? 0);
            $calls = $calls > 0 ? $calls : (int) max(1, ceil(mb_strlen($text) / 1800));
            $pdo->prepare('SELECT kn_usage_add(:b, \'extract\', :c, 0, 0, :n)')->execute(['b' => $bid, 'c' => $calls, 'n' => $chars]);
            kn_build_usage_add($pdo, $buildId, ['calls' => $calls, 'chars' => $chars, 'attempts' => 1, 'retries' => $attempts > 1 ? 1 : 0]);
            kn_build_limit_warn($pdo, $bid);
        }
        if ($r['ok']) {
            return kn_build_item_done($pdo, $buildId, $itemId, $sid, $bid, (int) ($r['data']['document_id'] ?? 0), $r['data']['edges'] ?? [], (int) ($r['data']['chunk_count'] ?? 0));
        }
        $class = kn_build_classify($r);
        if ($class === 'down') {
            $pdo->prepare("UPDATE build_items SET status = 'queued' WHERE id = :i")->execute(['i' => $itemId]);
            kn_build_wait_note($pdo, $buildId, (string) $r['code']);
            return 'down';
        }
        if ($class === 'timeout') {
            $doc = kn_build_find_document($ns, $title, $began);
            if ($doc !== null) {
                kn_build_note($pdo, $buildId, 'section ' . $sid . ': the engine timed out but kept document ' . $doc . ' — adopted');
                return kn_build_item_done($pdo, $buildId, $itemId, $sid, $bid, $doc, [], 0);
            }
            if ($attempts < 3) {
                continue;
            }
        }
        if ($class === 'model_failed' && $attempts < 3) {
            sleep(getenv('KN_BUILD_BACKOFF') === '0' ? 0 : ($attempts === 1 ? 2 : 8));   // 0: the proofs' five-in-a-row, which would wait 50 s
            continue;
        }
        if ($class === 'duplicate_key') {
            if (!$dupHere) {
                $dupHere = true;
                $u = kn_build_usage_add($pdo, $buildId, ['duplicate_key' => 1]);
                if ((int) $u['duplicate_key'] >= 2) {
                    kn_build_item_failed($pdo, $itemId, $r);
                    kn_build_fail($pdo, $buildId, 'engine_subject_ids');
                    return 'stop';
                }
                continue;                                   // retry once at once: the sequence has moved
            }
        }
        if ($class === 'not_configured') {
            $pdo->prepare("UPDATE build_items SET status = 'queued' WHERE id = :i")->execute(['i' => $itemId]);
            kn_build_fail($pdo, $buildId, 'model_not_configured');
            return 'stop';
        }
        kn_build_item_failed($pdo, $itemId, $r);
        if (kn_build_failures_in_a_row($pdo, $buildId) >= KN_BUILD_FAILURES_IN_A_ROW) {
            kn_build_fail($pdo, $buildId, 'too_many_failures');
            return 'stop';
        }
        return 'failed';
    }
}

/** The engine's answer sorted by what the worker does with it. */
function kn_build_classify(array $r): string
{
    $code = (string) ($r['code'] ?? '');
    $words = strtolower((string) ($r['error'] ?? '') . ' ' . (string) ($r['data']['engine_code'] ?? ''));
    return match (true) {
        in_array($code, KN_BUILD_ENGINE_DOWN, true) => 'down',
        $code === 'engine_timeout' => 'timeout',
        $code === 'model_failed' => 'model_failed',
        $code === 'conflict' && str_contains($words, 'duplicate key') => 'duplicate_key',
        in_array($code, ['model_not_configured', 'model_api_key_missing'], true) => 'not_configured',
        default => 'failed',
    };
}

/** The document the engine kept for a section though the call timed out: the namespace's newest documents, by the exact title, created since the attempt began. */
function kn_build_find_document(string $ns, string $title, int $began): ?int
{
    for ($i = 0; $i < 5; $i++) {
        $d = kn_engine_documents($ns, 50);
        foreach ($d['ok'] ? ($d['data']['documents'] ?? []) : [] as $doc) {
            $at = strtotime((string) ($doc['created_at'] ?? '')) ?: 0;
            if (($doc['title'] ?? null) === $title && $at >= $began - 5) {
                return (int) $doc['id'];
            }
        }
        sleep(2);                                           // the engine may still be writing it
    }
    return null;
}

/** Done: the document recorded on the section FIRST (never lost), then the item; the one hand-set status when the version moved during the call. */
function kn_build_item_done(PDO $pdo, int $buildId, int $itemId, int $sid, int $bid, int $doc, array $edges, int $chunks): string
{
    if ($doc > 0) {
        $pdo->prepare('UPDATE sections SET maludb_document_id = :d WHERE id = :s')->execute(['d' => (string) $doc, 's' => $sid]);
    }
    $subjects = array_slice(array_values(array_unique(array_filter(array_map(static fn ($e) => is_array($e) ? (string) ($e['subject_text'] ?? '') : '', $edges)))), 0, 200);
    $st = $pdo->prepare("UPDATE build_items SET status = 'done', usage = :u WHERE id = :i AND status = 'running'");
    $st->execute(['u' => json_encode(['edges' => count($edges), 'chunks' => $chunks, 'subjects' => $subjects, 'document_id' => $doc], JSON_UNESCAPED_UNICODE), 'i' => $itemId]);
    if ($st->rowCount() === 1) {
        $pdo->prepare('UPDATE builds SET statements_added = statements_added + :n WHERE id = :id')->execute(['n' => count($edges), 'id' => $buildId]);
    }
    $pdo->prepare("UPDATE sections x SET status = 'stale' FROM knowledge_bases k WHERE x.id = :s AND k.id = x.base_id AND x.status = 'extracted' AND x.extracted_version < k.guideline_version")
        ->execute(['s' => $sid]);
    return 'done';
}

/** A failed item: the engine's words (scrubbed) on the item; the trigger marks the section failed with them. */
function kn_build_item_failed(PDO $pdo, int $itemId, array $r): void
{
    $words = kn_engine_scrub_text(trim(($r['code'] ?? 'engine_error') . ': ' . (string) ($r['error'] ?? '')));
    $pdo->prepare("UPDATE build_items SET status = 'failed', error = :e WHERE id = :i AND status = 'running'")->execute(['e' => mb_substr($words, 0, 1000), 'i' => $itemId]);
}

/** The finished items of the build, newest first, counted while they are failures. */
function kn_build_failures_in_a_row(PDO $pdo, int $buildId): int
{
    $st = $pdo->prepare("SELECT status FROM build_items WHERE build_id = :b AND status IN ('done', 'failed') ORDER BY finished_at DESC NULLS LAST, id DESC LIMIT " . KN_BUILD_FAILURES_IN_A_ROW);
    $st->execute(['b' => $buildId]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $s) {
        if ($s !== 'failed') {
            break;
        }
        $n++;
    }
    return $n;
}

/** A "waiting" line in the build's log, once per change of reason. */
function kn_build_wait_note(PDO $pdo, int $buildId, string $code): void
{
    $log = (string) one_value($pdo, 'SELECT log FROM builds WHERE id = :id', ['id' => $buildId]);
    $lines = array_values(array_filter(explode("\n", $log)));
    if (end($lines) !== 'waiting: ' . $code) {
        kn_build_note($pdo, $buildId, 'waiting: ' . $code);
    }
}

/** The seed terms of the source's active areas (the hints a section's areas add). */
function kn_build_seed_terms(PDO $pdo, int $sourceId): array
{
    $st = $pdo->prepare("SELECT a.seed_terms FROM source_focus_areas sfa JOIN focus_areas a ON a.id = sfa.focus_area_id WHERE sfa.source_id = :s AND a.status = 'active' ORDER BY a.position, a.id");
    $st->execute(['s' => $sourceId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $lit) {
        foreach (kn_compile_pg_array((string) $lit) as $t) {
            $out[] = $t;
        }
    }
    return array_values(array_unique($out));
}

/** When this month's characters cross the warning percentage, each curator not yet told this month is told (once a member, base and month). */
function kn_build_limit_warn(PDO $pdo, int $bid): void
{
    $limit = kn_build_limit_chars($pdo, $bid);
    $used = kn_build_month_used($pdo, $bid);
    $pct = (int) one_value($pdo, 'SELECT limit_warn_pct FROM kn_settings WHERE id = 1');
    if ($limit <= 0 || $used * 100 < $limit * $pct) {
        return;
    }
    $name = (string) one_value($pdo, 'SELECT name FROM knowledge_bases WHERE id = :b', ['b' => $bid]);
    $share = (int) floor($used * 100 / $limit);
    $month = date('Y-m');
    $cur = $pdo->prepare('SELECT kn_base_curator_ids(:b)');
    $cur->execute(['b' => $bid]);
    $told = $pdo->prepare("SELECT 1 FROM notifications WHERE member_id = :m AND kind = 'limit_near' AND record_type = 'base' AND record_id = :b AND created_at >= date_trunc('month', now())");
    $notify = $pdo->prepare("SELECT kn_notify(:m, 'limit_near', :t, :body, 'base', :b, :d)");
    foreach ($cur->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $told->execute(['m' => (int) $m, 'b' => $bid]);
        if ($told->fetchColumn() !== false) {
            continue;
        }
        $notify->execute(['m' => (int) $m, 't' => 'Knowledge base "' . mb_substr($name, 0, 120) . '" has used ' . $share . '% of its monthly text',
            'body' => 'A build stops when the limit is reached; the admin can raise it.', 'b' => $bid, 'd' => 'limit:' . $bid . ':' . $month . ':' . (int) $m]);
    }
}

/**
 * Finish a build: failed / every_section_failed when nothing was done of a non-empty build, else done (with a note: stopped_guidelines); then
 * the base's counts from the engine and from the items; build.done or build.fail.
 */
function kn_build_finish(PDO $pdo, int $buildId, ?string $note = null): void
{
    $b = $pdo->prepare('SELECT * FROM builds WHERE id = :id');
    $b->execute(['id' => $buildId]);
    $build = $b->fetch();
    if ($build === false || !in_array($build['status'], ['queued', 'running'], true)) {
        return;
    }
    $bid = (int) $build['base_id'];
    if ($note === null && (int) $build['items_total'] > 0 && (int) $build['items_done'] === 0) {
        kn_build_fail($pdo, $buildId, 'every_section_failed');
        return;
    }
    $pdo->prepare("UPDATE builds SET status = 'running' WHERE id = :id AND status = 'queued'")->execute(['id' => $buildId]);
    $pdo->prepare("UPDATE builds SET status = 'done', error = :e WHERE id = :id AND status = 'running'")->execute(['e' => $note, 'id' => $buildId]);
    $oldSubjects = (int) one_value($pdo, 'SELECT subjects_count FROM knowledge_bases WHERE id = :b', ['b' => $bid]);
    $counts = kn_build_base_counts($pdo, $bid);
    $pdo->prepare('UPDATE builds SET subjects_added = :n WHERE id = :id')->execute(['n' => $counts['subjects'] - $oldSubjects, 'id' => $buildId]);
    $b->execute(['id' => $buildId]);
    $build = $b->fetch();
    $usage = json_decode((string) $build['usage'], true) ?: [];
    $skipped = (int) one_value($pdo, "SELECT count(*) FROM build_items WHERE build_id = :b AND status = 'skipped'", ['b' => $buildId]);
    $secs = $build['started_at'] !== null ? (int) (strtotime((string) $build['finished_at']) - strtotime((string) $build['started_at'])) : 0;
    kn_build_log($pdo, 'build.done', $bid, 'build', $buildId, ['build_id' => $buildId, 'done' => (int) $build['items_done'], 'failed' => (int) $build['items_failed'], 'skipped' => $skipped,
        'chars' => (int) $build['chars_processed'], 'calls' => (int) ($usage['calls'] ?? 0), 'statements_added' => (int) $build['statements_added'],
        'subjects_added' => (int) $build['subjects_added'], 'seconds' => $secs, 'stopped' => $note === 'stopped_guidelines' ? 'guidelines_changed' : null]);
}

/** The base's cached counts: documents from the engine; statements and subjects from the latest done item of each live extracted section; communities the engine's. */
function kn_build_base_counts(PDO $pdo, int $bid): array
{
    $ns = (string) one_value($pdo, 'SELECT namespace FROM knowledge_bases WHERE id = :b', ['b' => $bid]);
    $st = $pdo->prepare("SELECT DISTINCT ON (i.section_id) i.usage FROM build_items i JOIN sections x ON x.id = i.section_id
                          WHERE x.base_id = :b AND x.status = 'extracted' AND i.status = 'done' ORDER BY i.section_id, i.finished_at DESC NULLS LAST, i.id DESC");
    $st->execute(['b' => $bid]);
    $statements = 0;
    $subjects = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) {
        $u = json_decode((string) $u, true) ?: [];
        $statements += (int) ($u['edges'] ?? 0);
        foreach ((array) ($u['subjects'] ?? []) as $s) {
            $subjects[mb_strtolower((string) $s)] = true;
        }
    }
    $set = ['statements_count = :st', 'subjects_count = :su'];
    $args = ['st' => $statements, 'su' => count($subjects), 'b' => $bid];
    $stats = kn_engine_stats($ns);
    if ($stats['ok']) {
        $set[] = 'documents_count = :d';
        $args['d'] = (int) ($stats['data']['documents'] ?? 0);
    }
    $comm = kn_engine_communities($ns);
    if ($comm['ok']) {
        $set[] = 'communities_count = :c';
        $args['c'] = count((array) ($comm['data']['communities'] ?? []));
    }
    $pdo->prepare('UPDATE knowledge_bases SET ' . implode(', ', $set) . ' WHERE id = :b')->execute($args);
    return ['statements' => $statements, 'subjects' => count($subjects)];
}
