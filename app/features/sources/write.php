<?php
declare(strict_types=1);

/**
 * Sources — the writes of slice 2 (docs/build-specs/sources-sections.md "Writes"), to the base tables after the gate. The database is
 * the referee (db/007): an archived base takes no source, a forgotten source never comes back and forgets its sections, a replaced
 * source is forgotten, the base's status follows its sections. A refusal of ours is a DomainException in a person's words; the
 * callers wrap these in kn_guard_fields() and a transaction. Files are deleted only after the commit (the caller's last step).
 */
require_once APP_ROOT . '/app/features/files/queries.php';

/** The source row itself (base tables): null when gone. */
function source_row(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM sources WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The size a source file may have: the smaller of the attachment door's and the settings'. */
function source_max_bytes(PDO $pdo): int
{
    return (int) min(attachment_max_bytes(), (int) one_value($pdo, 'SELECT max_source_bytes FROM kn_settings WHERE id = 1'));
}

/** A cleaned file name: its base name, no control characters, at most 300 characters. */
function source_clean_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));
    return mb_substr($name === '' ? 'file' : $name, 0, 300);
}

/**
 * One uploaded file → a source (status uploaded) with its attachment. Refused in the file's own sentence (DomainException): over the
 * size, the same bytes already live in the base, a kind the door refuses. ['source_id', 'title'].
 */
function add_file_source(PDO $pdo, int $baseId, array $file, int $by): array
{
    $name = source_clean_filename((string) ($file['name'] ?? 'file'));
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $max = source_max_bytes($pdo);
    if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) ($file['size'] ?? 0) > $max) {
        throw new DomainException('Over ' . fmt_bytes($max) . ': ' . $name . '.');
    }
    if ($err !== UPLOAD_ERR_OK || !is_file((string) ($file['tmp_name'] ?? ''))) {
        throw new DomainException($name . ' did not arrive.');
    }
    $sha = hash_file('sha256', (string) $file['tmp_name']);
    $dup = base_has_attachment_sha($pdo, $baseId, $sha);
    if ($dup !== null) {
        throw new DomainException($name . ' is already here as "' . $dup['title'] . '".');
    }
    $st = $pdo->prepare("INSERT INTO sources (base_id, kind, title, origin, added_by, status) VALUES (:b, 'file', :t, :t, :by, 'uploaded') RETURNING id");
    $st->execute(['b' => $baseId, 't' => $name, 'by' => $by]);
    $id = (int) $st->fetchColumn();
    try {
        $att = store_attachment($pdo, 'source', $id, array_merge($file, ['name' => $name]), $by);
    } catch (DomainException $e) {
        throw new DomainException($name . ': ' . $e->getMessage());
    }
    $mime = (string) one_value($pdo, 'SELECT mime_type FROM attachments WHERE id = :a', ['a' => $att]);
    $pdo->prepare('UPDATE sources SET attachment_id = :a, mime_type = :m WHERE id = :id')->execute(['a' => $att, 'm' => $mime, 'id' => $id]);
    return ['source_id' => $id, 'title' => $name, 'bytes' => (int) $file['size'], 'mime_type' => $mime];
}

/** Pasted text → a source (status uploaded) with its raw text; the worker cleans and cuts it. */
function add_text_source(PDO $pdo, int $baseId, string $title, string $text, int $by): int
{
    $st = $pdo->prepare("INSERT INTO sources (base_id, kind, title, origin, added_by, status) VALUES (:b, 'text', :t, 'paste', :by, 'uploaded') RETURNING id");
    $st->execute(['b' => $baseId, 't' => $title, 'by' => $by]);
    $id = (int) $st->fetchColumn();
    $pdo->prepare("INSERT INTO source_text (source_id, text, extractor) VALUES (:s, :x, 'paste')")->execute(['s' => $id, 'x' => $text]);
    return $id;
}

/** An address → a source (status uploaded); the worker fetches it. The title is the given one, else the address (provisional). */
function add_url_source(PDO $pdo, int $baseId, string $url, ?string $title, int $by): int
{
    $st = $pdo->prepare("INSERT INTO sources (base_id, kind, title, origin, added_by, status) VALUES (:b, 'url', :t, :u, :by, 'uploaded') RETURNING id");
    $st->execute(['b' => $baseId, 't' => mb_substr($title !== null && $title !== '' ? $title : $url, 0, 300), 'u' => $url, 'by' => $by]);
    return (int) $st->fetchColumn();
}

/**
 * Removing what a source taught (divergence 4): its file row, source_text and source_pages go and its sections' text becomes ''; the
 * rows stay for the history and the citations. Answers the stored file's path, deleted by the caller after the commit.
 */
function wipe_source_content(PDO $pdo, int $id): ?string
{
    $path = one_value($pdo, "SELECT a.storage_path FROM sources s JOIN attachments a ON a.id = s.attachment_id WHERE s.id = :id", ['id' => $id]);
    $pdo->prepare('DELETE FROM source_pages WHERE source_id = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM source_text WHERE source_id = :id')->execute(['id' => $id]);
    $pdo->prepare("UPDATE sections SET text = '' WHERE source_id = :id AND text <> ''")->execute(['id' => $id]);
    $pdo->prepare("DELETE FROM attachments WHERE record_type = 'source' AND record_id = :id")->execute(['id' => $id]);
    return $path === null ? null : APP_ROOT . '/storage/' . $path;
}

/** Remove a source: forgotten (the trigger forgets its sections and recomputes the base), its content wiped. ['sections', 'file', 'title']. */
function remove_source(PDO $pdo, int $id, int $by): array
{
    $src = source_row($pdo, $id) ?? throw new DomainException('Not found.');
    if ($src['status'] === 'forgotten') {
        throw new DomainException('"' . $src['title'] . '" is removed already.');
    }
    if ($src['kind'] === 'feed') {                                   // slice 8: a fed source goes when its page does, or when the feed does (feeds/write.php forget_feed_source)
        throw new DomainException('A fed source is removed by its feed.');
    }
    $sections = (int) one_value($pdo, "SELECT count(*) FROM sections WHERE source_id = :id AND status <> 'forgotten'", ['id' => $id]);
    $pdo->prepare("UPDATE sources SET status = 'forgotten' WHERE id = :id")->execute(['id' => $id]);
    $file = wipe_source_content($pdo, $id);
    return ['sections' => $sections, 'file' => $file, 'title' => $src['title']];
}

/**
 * Replace a source by a new version of the same kind: the new source (its file, text or address as in source_add), its areas copied,
 * the old one superseded (the trigger forgets it and its sections), the old content wiped. $input: kind, file|text|url, title.
 * ['new_id', 'sections_forgotten', 'old_file'].
 */
function replace_source(PDO $pdo, int $oldId, array $input, int $by): array
{
    $old = source_row($pdo, $oldId) ?? throw new DomainException('Not found.');
    if ($old['status'] === 'forgotten') {
        throw new DomainException('"' . $old['title'] . '" is removed already: add the new version instead.');
    }
    if ($old['kind'] === 'feed') {
        throw new DomainException('A fed source is replaced by its feed.');
    }
    $baseId = (int) $old['base_id'];
    $title = ($input['title'] ?? '') !== '' ? (string) $input['title'] : null;
    $sections = (int) one_value($pdo, "SELECT count(*) FROM sections WHERE source_id = :id AND status <> 'forgotten'", ['id' => $oldId]);
    // the old version's bytes are not "already here": it is the one being replaced
    $pdo->prepare("UPDATE sources SET status = 'forgotten' WHERE id = :id")->execute(['id' => $oldId]);
    switch ($input['kind']) {
        case 'file':
            $new = add_file_source($pdo, $baseId, $input['file'], $by)['source_id'];
            if ($title !== null) {
                $pdo->prepare('UPDATE sources SET title = :t WHERE id = :id')->execute(['t' => $title, 'id' => $new]);
            }
            break;
        case 'text':
            $new = add_text_source($pdo, $baseId, $title ?? (string) $old['title'], (string) $input['text'], $by);
            break;
        case 'url':
            $new = add_url_source($pdo, $baseId, (string) $input['url'], $title ?? ($old['kind'] === 'url' && $old['title'] !== $old['origin'] ? (string) $old['title'] : null), $by);
            break;
        default:
            throw new DomainException('Replace a source with a file, text or an address.');
    }
    $pdo->prepare('INSERT INTO source_focus_areas (source_id, focus_area_id, assigned_by) SELECT :n, focus_area_id, :by FROM source_focus_areas WHERE source_id = :o ON CONFLICT DO NOTHING')
        ->execute(['n' => $new, 'o' => $oldId, 'by' => $by]);
    $pdo->prepare('UPDATE sources SET superseded_by = :n WHERE id = :o')->execute(['n' => $new, 'o' => $oldId]);
    $file = wipe_source_content($pdo, $oldId);
    return ['new_id' => $new, 'sections_forgotten' => $sections, 'old_file' => $file];
}

/** A failed source goes back in the queue (no_text is deterministic and not retried). */
function retry_source(PDO $pdo, int $id, int $by): void
{
    $src = source_row($pdo, $id) ?? throw new DomainException('Not found.');
    if ($src['status'] !== 'failed') {
        throw new DomainException('Only a source that failed is tried again' . ($src['status'] === 'no_text' ? ' — a source with no text gives none the second time.' : '.'));
    }
    $pdo->prepare("UPDATE sources SET status = 'uploaded', error = NULL WHERE id = :id")->execute(['id' => $id]);
}

/** Title and language. Returns the diff. */
function update_source(PDO $pdo, int $id, array $fields, int $by): array
{
    $before = source_row($pdo, $id) ?? throw new DomainException('Not found.');
    $set = [];
    $args = ['id' => $id];
    foreach (['title', 'language'] as $col) {
        if (array_key_exists($col, $fields) && $fields[$col] !== $before[$col]) {
            $set[] = "$col = :$col";
            $args[$col] = $fields[$col];
        }
    }
    if ($set !== []) {
        $pdo->prepare('UPDATE sources SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($args);
    }
    $after = source_row($pdo, $id);
    $pick = static fn (array $r): array => ['title' => $r['title'], 'language' => $r['language']];
    return kn_diff($pick($before), $pick($after));
}

/** Delete stored files after a commit (a rollback keeps them). */
function delete_files_after_commit(array $paths): void
{
    foreach ($paths as $p) {
        if (is_string($p) && $p !== '' && is_file($p)) {
            @unlink($p);
            @rmdir(dirname($p));
        }
    }
}
