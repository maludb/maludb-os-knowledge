<?php
declare(strict_types=1);

/**
 * The worker's source steps (slice 2, THE FIRST EXEMPLAR; docs/build-specs/sources-sections.md "The worker — the queue discipline").
 * Called by bin/worker.php in this order, inside one pass that holds the worker's advisory lock and has a 50 s deadline:
 *   kn_worker_forget()        removed sections forgotten in the engine ("within the minute")
 *   kn_worker_purge_bases()   a deleted base's row and files go once the engine holds nothing of it
 *   kn_worker_recover()       a source the worker died on becomes failed / worker_interrupted
 *   kn_worker_sources()       up to 10 sources read and cut, one at a time, each claimed by exactly one pass
 * The worker has no member: it reads base tables (the mcp_* views answer nothing without one), logs with actor null and source cron,
 * and every row it logs carries base_id. No row carries a source's or a section's text.
 */
require_once APP_ROOT . '/app/knowledge/engine.php';
require_once APP_ROOT . '/app/knowledge/fetch.php';
require_once APP_ROOT . '/app/features/files/queries.php';
require_once APP_ROOT . '/app/knowledge/areas.php';

/** Slice 3: a deleted base's areas lose their engine projects before the base goes; false when one remains (the next pass retries). */
function kn_worker_purge_base_projects(PDO $pdo, int $baseId): bool
{
    $st = $pdo->prepare('SELECT id FROM focus_areas WHERE base_id = :b AND maludb_project_id IS NOT NULL');
    $st->execute(['b' => $baseId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $areaId) {
        if (!kn_area_engine_remove($pdo, (int) $areaId)['ok']) {
            return false;
        }
    }
    return true;
}

const KN_WORKER_STOP_CODES = ['engine_unavailable', 'engine_auth', 'engine_forbidden', 'engine_timeout'];

/** A row of the worker's trail: actor null, source cron, base_id always. */
function kn_worker_log(PDO $pdo, string $action, int $baseId, ?string $entityType, ?int $entityId, array $after = []): void
{
    log_activity($pdo, $action, $entityType, $entityId, ['actor_member_id' => null, 'source' => 'cron', 'base_id' => $baseId, 'after' => $after]);
}

/**
 * Forget, in the engine, what was removed: sections forgotten with a document id still set, oldest first. ok or not_found clears the id
 * (only if it is still that id); an engine that cannot be reached or refuses the token stops the step (the next pass resumes); any other
 * failure leaves that section for the next pass. A source whose last such section is cleared logs source.forget. Answers the count cleared.
 */
function kn_worker_forget(PDO $pdo, float $deadline, int $batch = 200): int
{
    $rows = $pdo->query("SELECT id, base_id, source_id, maludb_document_id FROM sections WHERE status = 'forgotten' AND maludb_document_id IS NOT NULL
                          ORDER BY updated_at, id LIMIT " . max(1, $batch))->fetchAll();
    $cleared = 0;
    $perSource = [];
    foreach ($rows as $r) {
        if (microtime(true) > $deadline) {
            break;
        }
        $doc = (string) $r['maludb_document_id'];
        $res = ctype_digit($doc) ? kn_engine_forget_document((int) $doc) : ['ok' => false, 'code' => 'not_found'];
        if (!$res['ok'] && in_array($res['code'], KN_WORKER_STOP_CODES, true)) {
            error_log('worker forget: the engine is not answering (' . $res['code'] . '); the next pass resumes');
            break;
        }
        if ($res['ok'] || $res['code'] === 'not_found') {
            $st = $pdo->prepare('UPDATE sections SET maludb_document_id = NULL WHERE id = :id AND maludb_document_id = :doc');
            $st->execute(['id' => (int) $r['id'], 'doc' => $doc]);
            if ($st->rowCount() === 1) {
                $cleared++;
                $sid = (int) $r['source_id'];
                $perSource[$sid] = ['base' => (int) $r['base_id'], 'n' => ($perSource[$sid]['n'] ?? 0) + 1];
            }
        } else {
            error_log('worker forget: section ' . $r['id'] . ' waits (' . ($res['code'] ?? 'error') . ')');
        }
    }
    foreach ($perSource as $sid => $v) {
        $left = (int) one_value($pdo, 'SELECT count(*) FROM sections WHERE source_id = :s AND maludb_document_id IS NOT NULL', ['s' => $sid]);
        if ($left === 0) {
            kn_worker_log($pdo, 'source.forget', $v['base'], 'source', $sid, ['source_id' => $sid, 'sections' => $v['n']]);
        }
    }
    return $cleared;
}

/**
 * A deleted base (slice 1, divergence 2): its forget build starts; when no section of the base holds a document id (and, from slice 3,
 * no area a project), its attachment files and its row go — the cascade takes the rest — logged base.delete phase done. Answers how many.
 */
function kn_worker_purge_bases(PDO $pdo): int
{
    $builds = $pdo->query("SELECT id, base_id, status FROM builds WHERE kind = 'forget' AND status IN ('queued', 'running') ORDER BY requested_at")->fetchAll();
    $done = 0;
    foreach ($builds as $b) {
        $base = (int) $b['base_id'];
        if ($b['status'] === 'queued') {
            $pdo->prepare("UPDATE builds SET status = 'running', started_at = now() WHERE id = :id AND status = 'queued'")->execute(['id' => (int) $b['id']]);
        }
        $left = (int) one_value($pdo, 'SELECT count(*) FROM sections WHERE base_id = :b AND maludb_document_id IS NOT NULL', ['b' => $base]);
        if ($left > 0) {
            $pdo->prepare('UPDATE builds SET items_done = GREATEST(items_total - :left, 0) WHERE id = :id')->execute(['left' => $left, 'id' => (int) $b['id']]);
            continue;
        }
        if (function_exists('kn_worker_purge_base_projects') && !kn_worker_purge_base_projects($pdo, $base)) {
            continue;                                                   // slice 3: an area's project the engine has not let go of yet
        }
        $name = (string) one_value($pdo, 'SELECT name FROM knowledge_bases WHERE id = :b', ['b' => $base]);
        $files = $pdo->prepare("SELECT a.storage_path FROM attachments a JOIN sources s ON s.id = a.record_id WHERE a.record_type = 'source' AND s.base_id = :b");
        $files->execute(['b' => $base]);
        $paths = array_map(static fn (array $r): string => APP_ROOT . '/storage/' . $r['storage_path'], $files->fetchAll());
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM notes WHERE (record_type = 'base' AND record_id = :b)
                              OR (record_type = 'source' AND record_id IN (SELECT id FROM sources WHERE base_id = :b))
                              OR (record_type = 'section' AND record_id IN (SELECT id FROM sections WHERE base_id = :b))")->execute(['b' => $base]);
            $pdo->prepare("DELETE FROM attachments WHERE record_type = 'source' AND record_id IN (SELECT id FROM sources WHERE base_id = :b)")->execute(['b' => $base]);
            $pdo->prepare('DELETE FROM knowledge_bases WHERE id = :b')->execute(['b' => $base]);
            kn_worker_log($pdo, 'base.delete', $base, 'base', $base, ['name' => $name, 'phase' => 'done', 'build_id' => (int) $b['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('worker purge of base ' . $base . ': ' . $e->getMessage());
            continue;
        }
        foreach ($paths as $p) {
            if (is_file($p)) {
                @unlink($p);
                @rmdir(dirname($p));
            }
        }
        $done++;
    }
    return $done;
}

/** A source the worker died on (extracting_text for 15 minutes) → failed / worker_interrupted. Answers how many. */
function kn_worker_recover(PDO $pdo): int
{
    $rows = $pdo->query("UPDATE sources SET status = 'failed', error = 'worker_interrupted'
                          WHERE status IN ('extracting_text', 'sectioning') AND updated_at < now() - interval '15 minutes' RETURNING id, base_id, added_by, title")->fetchAll();
    foreach ($rows as $r) {
        kn_worker_log($pdo, 'source.fail', (int) $r['base_id'], 'source', (int) $r['id'], ['source_id' => (int) $r['id'], 'error' => 'worker_interrupted']);
        kn_worker_notify_failed($pdo, $r, 'failed', 'worker_interrupted');
    }
    return count($rows);
}

/** Tell the person who added a source that it could not be read (never an agent — kn_notify() refuses one). */
function kn_worker_notify_failed(PDO $pdo, array $src, string $status, ?string $error): void
{
    require_once APP_ROOT . '/app/features/sources/present.php';
    if ($src['added_by'] === null) {
        return;
    }
    $pdo->prepare('SELECT kn_notify(:m, :k, :t, :b, :rt, :rid, :d)')->execute([
        'm' => (int) $src['added_by'], 'k' => 'source_failed', 't' => 'Could not read "' . mb_substr((string) $src['title'], 0, 150) . '"',
        'b' => source_error_words($error, $status), 'rt' => 'source', 'rid' => (int) $src['id'], 'd' => 'source:' . (int) $src['id'] . ':' . $status,
    ]);
}

/** Mark a source failed with a code, log it and tell the person who added it. */
function kn_worker_source_failed(PDO $pdo, array $src, string $code): void
{
    $pdo->prepare("UPDATE sources SET status = 'failed', error = :e WHERE id = :id")->execute(['e' => mb_substr($code, 0, 200), 'id' => (int) $src['id']]);
    kn_worker_log($pdo, 'source.fail', (int) $src['base_id'], 'source', (int) $src['id'], ['source_id' => (int) $src['id'], 'error' => $code]);
    kn_worker_notify_failed($pdo, $src, 'failed', $code);
}

/**
 * Read and cut up to $max sources, one at a time: claim (exactly one pass holds a source), extract outside any transaction, then ONE
 * transaction writes the text, the pages and the sections and passes through sectioning to ready. Answers how many it handled.
 */
function kn_worker_sources(PDO $pdo, float $deadline, int $max = 10): int
{
    $n = 0;
    $settings = $pdo->query('SELECT section_target_chars, section_max_chars, url_fetch_allowed, url_fetch_max_bytes FROM kn_settings WHERE id = 1')->fetch();
    while ($n < $max && microtime(true) < $deadline) {
        $claim = $pdo->query("UPDATE sources SET status = 'extracting_text', error = NULL
                               WHERE id = (SELECT s.id FROM sources s JOIN knowledge_bases b ON b.id = s.base_id AND b.archived_at IS NULL
                                            WHERE s.status = 'uploaded' AND s.kind IN ('file', 'text', 'url', 'note')
                                            ORDER BY s.added_at, s.id LIMIT 1 FOR UPDATE OF s SKIP LOCKED)
                               RETURNING *")->fetch();
        if ($claim === false) {
            break;
        }
        $n++;
        try {
            kn_worker_read_source($pdo, $claim, $settings, $deadline);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('worker: source ' . $claim['id'] . ' failed to read: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            kn_worker_source_failed($pdo, $claim, 'extract_error');
        }
    }
    return $n;
}

/** One claimed source: extract, then write it whole or mark it failed / no_text. */
function kn_worker_read_source(PDO $pdo, array $src, array $settings, float $deadline): void
{
    $id = (int) $src['id'];
    $base = (int) $src['base_id'];
    $extractor = $src['kind'];
    $etag = null;
    switch ($src['kind']) {
        case 'file':
            $att = $pdo->prepare('SELECT filename, mime_type, storage_path FROM attachments WHERE id = :a');
            $att->execute(['a' => (int) $src['attachment_id']]);
            $a = $att->fetch();
            $path = $a === false ? null : APP_ROOT . '/storage/' . $a['storage_path'];
            if ($path === null || !is_file($path)) {
                kn_worker_source_failed($pdo, $src, 'file_missing');
                return;
            }
            $ex = kn_extract_text($path, (string) $a['filename'], (string) $a['mime_type']);
            $extractor = (string) ($ex['kind'] ?? 'file');
            break;
        case 'text':
        case 'note':
            $raw = (string) one_value($pdo, 'SELECT text FROM source_text WHERE source_id = :s', ['s' => $id]);
            $ex = kn_extract_pasted_text($raw, (string) $src['title']);
            $extractor = $src['kind'] === 'note' ? 'note' : 'paste';
            break;
        case 'url':
            if (!(bool) $settings['url_fetch_allowed'] || !kn_fetch_enabled()) {
                kn_worker_source_failed($pdo, $src, 'fetch_disabled');
                return;
            }
            $seam = kn_fetch_test_seam();
            $f = null;
            foreach ([0, 2, 5] as $i => $wait) {                       // a transient failure is retried twice, after 2 s and 5 s
                if ($wait > 0) {
                    if (microtime(true) + $wait + 5 > $deadline) {
                        break;
                    }
                    sleep($wait);
                }
                $f = kn_fetch_url((string) $src['origin'], ['resolve' => $seam['resolve'], 'connect_to' => $seam['connect_to'], 'max_bytes' => (int) $settings['url_fetch_max_bytes']]);
                $transient = in_array($f['code'], ['fetch_failed', 'fetch_timeout'], true) || ($f['code'] === 'fetch_status' && $f['status'] >= 500);
                if ($f['ok'] || !$transient) {
                    break;
                }
            }
            if (!$f['ok']) {
                kn_worker_source_failed($pdo, $src, $f['code'] === 'fetch_status' ? 'fetch_status:' . $f['status'] : $f['code']);
                return;
            }
            $ex = kn_worker_read_fetched($pdo, $src, $f);
            $etag = $f['etag'];
            $extractor = 'url';
            $pdo->prepare('UPDATE sources SET url_etag = :e, url_checked_at = now() WHERE id = :id')->execute(['e' => $etag, 'id' => $id]);
            break;
        default:
            return;                                                     // a feed source is slice 8's
    }
    if (!$ex['ok']) {
        kn_worker_source_failed($pdo, $src, (string) $ex['error']);
        return;
    }
    $title = (string) $src['title'];
    if ($title === (string) $src['origin'] && ($ex['title'] ?? null) !== null && trim((string) $ex['title']) !== '') {
        $title = mb_substr(trim((string) $ex['title']), 0, 300);      // a provisional title (the file name, the address) gives way to the document's
    }
    $sections = $ex['no_text'] ? [] : kn_section_document($ex, ['title' => $title, 'target_chars' => (int) $settings['section_target_chars'], 'max_chars' => (int) $settings['section_max_chars']]);
    if ($ex['no_text'] || $sections === []) {
        $pdo->prepare("UPDATE sources SET status = 'no_text', title = :t WHERE id = :id")->execute(['t' => $title, 'id' => $id]);
        kn_worker_log($pdo, 'source.no_text', $base, 'source', $id, ['source_id' => $id, 'extractor' => $extractor]);
        kn_worker_notify_failed($pdo, $src + ['title' => $title], 'no_text', null);
        return;
    }
    $lang = $ex['language'] ?? null;
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO source_text (source_id, text, extractor, extracted_at) VALUES (:s, :x, :e, now())
                   ON CONFLICT (source_id) DO UPDATE SET text = EXCLUDED.text, extractor = EXCLUDED.extractor, extracted_at = now()')
        ->execute(['s' => $id, 'x' => $ex['text'], 'e' => $extractor]);
    $pdo->prepare('DELETE FROM source_pages WHERE source_id = :s')->execute(['s' => $id]);
    $pg = $pdo->prepare('INSERT INTO source_pages (source_id, page_no, char_from, char_to) VALUES (:s, :p, :f, :t)');
    foreach ($ex['pages'] as [$pageNo, $from, $to]) {
        $pg->execute(['s' => $id, 'p' => (int) $pageNo, 'f' => (int) $from, 't' => (int) $to]);
    }
    $validLang = is_string($lang) && preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $lang) === 1;
    $pdo->prepare("UPDATE sources SET status = 'sectioning', title = :t, language = COALESCE(language, :l) WHERE id = :id")
        ->execute(['t' => $title, 'l' => $validLang ? $lang : null, 'id' => $id]);
    kn_worker_log($pdo, 'source.text_extracted', $base, 'source', $id, ['source_id' => $id, 'characters' => mb_strlen($ex['text']), 'pages' => count($ex['pages']), 'extractor' => $extractor]);
    $ins = $pdo->prepare("INSERT INTO sections (base_id, source_id, position, level, kind, heading, heading_path, text, char_from, char_to, page_no, parent_id, status)
                          VALUES (:b, :s, :pos, :lvl, :kind, :h, :hp, :x, :f, :t, :page, :parent, 'queued') RETURNING id");
    $ids = [];
    $kinds = [];
    foreach ($sections as $i => $sec) {
        $parent = $sec['parent_index'] ?? null;
        $ins->execute(['b' => $base, 's' => $id, 'pos' => (int) $sec['position'], 'lvl' => max(0, min(9, (int) $sec['level'])), 'kind' => $sec['kind'],
            'h' => (string) $sec['heading'], 'hp' => (string) $sec['heading_path'], 'x' => (string) $sec['text'], 'f' => (int) $sec['char_from'], 't' => (int) $sec['char_to'],
            'page' => $sec['page_no'] === null ? null : (int) $sec['page_no'], 'parent' => $parent === null ? null : ($ids[(int) $parent] ?? null)]);
        $ids[$i] = (int) $ins->fetchColumn();
        $kinds[$sec['kind']] = ($kinds[$sec['kind']] ?? 0) + 1;
    }
    $pdo->prepare("UPDATE sources SET status = 'ready' WHERE id = :id")->execute(['id' => $id]);
    kn_worker_log($pdo, 'source.sectioned', $base, 'source', $id, ['source_id' => $id, 'sections' => count($ids), 'by_kind' => $kinds]);
    $pdo->commit();
}

/** A fetched page: HTML through the reader pass, any other admitted type through the file extractor; the bytes kept as the attachment. */
function kn_worker_read_fetched(PDO $pdo, array $src, array $f): array
{
    $ext = KN_FETCH_EXT[$f['content_type']] ?? 'html';
    $leaf = basename((string) (parse_url((string) $f['final_url'], PHP_URL_PATH) ?? ''));
    $name = $leaf !== '' && strtolower(pathinfo($leaf, PATHINFO_EXTENSION)) === $ext ? $leaf : ($ext === 'html' ? 'page.html' : 'page.' . $ext);
    $tmp = tempnam(sys_get_temp_dir(), 'knfetch');
    file_put_contents($tmp, $f['body']);
    $ex = $ext === 'html' ? kn_extract_url_text($f['body'], (string) $f['final_url']) : kn_extract_text($tmp, $name, $f['content_type']);
    try {
        $old = one_value($pdo, 'SELECT attachment_id FROM sources WHERE id = :id', ['id' => (int) $src['id']]);
        $att = store_attachment($pdo, 'source', (int) $src['id'], ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($f['body']), 'error' => UPLOAD_ERR_OK], null);
        $pdo->prepare('UPDATE sources SET attachment_id = :a, mime_type = :m WHERE id = :id')->execute(['a' => $att, 'm' => $f['content_type'], 'id' => (int) $src['id']]);
        if ($old !== null) {
            delete_attachment($pdo, (int) $old);                        // a retried fetch keeps one copy
        }
    } catch (Throwable $e) {
        error_log('worker: the fetched bytes of source ' . $src['id'] . ' were not kept: ' . $e->getMessage());
    }
    if (is_file($tmp)) {
        @unlink($tmp);
    }
    return $ex;
}
