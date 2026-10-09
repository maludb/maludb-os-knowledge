<?php
declare(strict_types=1);

/**
 * Bases — the writes of slice 1 (docs/build-specs/bases-core.md "Writes"), to the base tables after the gate. The database is the
 * referee (db/006): a namespace set once, a new base private, the key unique among live bases, a template switched only while empty,
 * the everyone row following visibility, an application reading only. A caller wraps these in kn_guard_fields() and a transaction
 * where the spec says "one transaction"; nothing here re-decides a rule the triggers keep.
 */

/** The base row itself (base tables, for a diff): null when gone. */
function base_row(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM knowledge_bases WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** A new base: the trigger gives key kb-<id> (when blank), namespace kb:<id>, the template version, the limit, status empty, private. */
function create_base(PDO $pdo, array $f, int $by): int
{
    $st = $pdo->prepare('INSERT INTO knowledge_bases (key, name, description, template, owner_member_id, created_by)
                         VALUES (:key, :name, :description, :template, :by, :by) RETURNING id');
    $st->execute(['key' => (string) ($f['key'] ?? ''), 'name' => $f['name'], 'description' => (string) ($f['description'] ?? ''),
                  'template' => $f['template'] ?? 'general', 'by' => $by]);
    return (int) $st->fetchColumn();
}

/**
 * Name, key, description, template. The template switch (divergence 1) happens here, after the row's update — the trigger has
 * refused it already when the base holds a live source. Returns ['before', 'after' (base_loggable), 'template_switch' => ?array].
 */
function update_base(PDO $pdo, int $id, array $f, int $by): array
{
    $before = base_row($pdo, $id) ?? throw new DomainException('Not found.');
    $set = [];
    $args = ['id' => $id];
    foreach (['name', 'key', 'description', 'template'] as $col) {
        if (array_key_exists($col, $f) && $f[$col] !== $before[$col]) {
            $set[] = "$col = :$col";
            $args[$col] = $f[$col];
        }
    }
    if ($set !== []) {
        $pdo->prepare('UPDATE knowledge_bases SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($args);
    }
    $switch = isset($args['template']) ? switch_template($pdo, $id, (string) $args['template'], $by) : null;
    $after = base_row($pdo, $id);
    return ['before' => base_loggable($before), 'after' => base_loggable($after), 'template_switch' => $switch];
}

/**
 * The shipped guidelines follow a template switch: the untouched ones (origin template, never saved since — version 1) go, the new
 * template's set arrives as kn_base_copy_template() copies it; a guideline a person wrote or edited stays.
 */
function switch_template(PDO $pdo, int $id, string $template, int $by): array
{
    $del = $pdo->prepare("DELETE FROM guidelines WHERE base_id = :b AND origin = 'template' AND version_no = 1");
    $del->execute(['b' => $id]);
    $add = $pdo->prepare("INSERT INTO guidelines (base_id, kind, title, body, position, origin, created_by)
                          SELECT :b, g->>'kind', COALESCE(g->>'title', ''), g->>'body', ord, 'template', :by
                            FROM base_templates t, jsonb_array_elements(t.guidelines) WITH ORDINALITY AS e(g, ord)
                           WHERE t.key = :t");
    $add->execute(['b' => $id, 't' => $template, 'by' => $by]);
    return ['removed' => $del->rowCount(), 'added' => $add->rowCount()];
}

/** Auto-extract, sensitivity, language — and the monthly limit only when $mayLimit. Returns the diff. */
function save_base_settings(PDO $pdo, int $id, array $f, bool $mayLimit, int $by): array
{
    $before = base_row($pdo, $id) ?? throw new DomainException('Not found.');
    $cols = ['auto_extract', 'sensitivity', 'language'];
    if ($mayLimit) {
        $cols[] = 'monthly_text_mb_limit';
    }
    $set = [];
    $args = ['id' => $id];
    foreach ($cols as $col) {
        if (!array_key_exists($col, $f)) {
            continue;
        }
        $set[] = "$col = :$col";
        $args[$col] = is_bool($f[$col]) ? ($f[$col] ? 'true' : 'false') : $f[$col];
    }
    if ($set !== []) {
        $pdo->prepare('UPDATE knowledge_bases SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($args);
    }
    $after = base_row($pdo, $id);
    $pick = static fn (array $r): array => ['auto_extract' => (bool) $r['auto_extract'], 'sensitivity' => $r['sensitivity'], 'language' => $r['language'],
        'monthly_text_mb_limit' => $r['monthly_text_mb_limit'] === null ? null : (int) $r['monthly_text_mb_limit']];
    return kn_diff($pick($before), $pick($after));
}

/** Private ↔ internal; the trigger writes or removes the everyone row. Returns ['before' => v, 'after' => v]. */
function set_base_visibility(PDO $pdo, int $id, string $v, int $by): array
{
    $before = base_row($pdo, $id) ?? throw new DomainException('Not found.');
    if ($before['visibility'] !== $v) {
        $pdo->prepare('UPDATE knowledge_bases SET visibility = :v WHERE id = :id')->execute(['v' => $v, 'id' => $id]);
    }
    return ['before' => $before['visibility'], 'after' => $v];
}

/**
 * Name a principal on a base, or change the level of the row already naming it. $kind: member (a person), agent, department or
 * application ($ref = its catalog key). Returns ['base_member_id', 'changed' (an existing row's level moved), 'before_level'].
 */
function share_base(PDO $pdo, int $baseId, string $kind, ?int $principalId, ?string $ref, string $level, int $by): array
{
    $st = $pdo->prepare('SELECT id, level FROM base_members WHERE base_id = :b AND principal_kind = :k AND COALESCE(principal_id, 0) = :p AND COALESCE(principal_ref, \'\') = :r');
    $st->execute(['b' => $baseId, 'k' => $kind, 'p' => $principalId ?? 0, 'r' => $ref ?? '']);
    $row = $st->fetch();
    if ($row !== false) {
        if ($row['level'] !== $level) {
            $pdo->prepare('UPDATE base_members SET level = :l WHERE id = :id')->execute(['l' => $level, 'id' => (int) $row['id']]);
        }
        return ['base_member_id' => (int) $row['id'], 'changed' => $row['level'] !== $level, 'before_level' => $row['level']];
    }
    $ins = $pdo->prepare('INSERT INTO base_members (base_id, principal_kind, principal_id, principal_ref, level, granted_by)
                          VALUES (:b, :k, :p, :r, :l, :by) RETURNING id');
    $ins->execute(['b' => $baseId, 'k' => $kind, 'p' => $principalId, 'r' => $ref, 'l' => $level, 'by' => $by]);
    return ['base_member_id' => (int) $ins->fetchColumn(), 'changed' => false, 'before_level' => null];
}

/** Remove a share row of the base (the trigger refuses the everyone row in its own words). Returns the row's facts. */
function unshare_base(PDO $pdo, int $baseId, int $baseMemberId, int $by): array
{
    $st = $pdo->prepare('SELECT id, principal_kind, principal_id, principal_ref, level FROM base_members WHERE id = :id AND base_id = :b');
    $st->execute(['id' => $baseMemberId, 'b' => $baseId]);
    $row = $st->fetch() ?: throw new DomainException('That share is not on this knowledge base.');
    $pdo->prepare('DELETE FROM base_members WHERE id = :id')->execute(['id' => $baseMemberId]);
    return ['base_member_id' => (int) $row['id'], 'principal_kind' => $row['principal_kind'],
            'principal_id' => $row['principal_id'] === null ? null : (int) $row['principal_id'], 'principal_ref' => $row['principal_ref'], 'level' => $row['level']];
}

/** Archive (true) or restore (false). A base being deleted is not restored (its forget build is live). */
function archive_base(PDO $pdo, int $id, bool $archive, int $by): void
{
    if (!$archive) {
        $live = base_live_build($pdo, $id);
        if ($live !== null && $live['kind'] === 'forget') {
            throw new DomainException('This base is being deleted.');
        }
    }
    $pdo->prepare('UPDATE knowledge_bases SET archived_at = ' . ($archive ? 'COALESCE(archived_at, now())' : 'NULL') . ' WHERE id = :id')->execute(['id' => $id]);
}

/**
 * Delete a base (divergence 2). One that never reached the engine goes at once (its files with it). One that did is renamed
 * deleting-<id>, its live sources forgotten (their sections follow), a forget build queued, and archived; the worker deletes the
 * row when nothing of it remains in the engine (slice 2's kn_worker_purge_bases()). A live build refuses it: one build per base.
 * Returns ['phase' => deleted|forgetting, 'sections', 'sources', 'build_id'].
 */
function delete_base(PDO $pdo, int $id, int $by): array
{
    $live = base_live_build($pdo, $id);
    if ($live !== null) {
        throw new DomainException($live['kind'] === 'forget' ? 'This base is being deleted.' : 'A build is running: cancel it first.');
    }
    $sources = (int) one_value($pdo, "SELECT count(*) FROM sources WHERE base_id = :b AND status <> 'forgotten'", ['b' => $id]);
    $sections = (int) one_value($pdo, "SELECT count(*) FROM sections WHERE base_id = :b AND status <> 'forgotten'", ['b' => $id]);
    $foot = base_engine_footprint($pdo, $id);
    if ($foot['documents'] === 0 && $foot['projects'] === 0) {
        $files = $pdo->prepare("SELECT a.id, a.storage_path FROM attachments a JOIN sources s ON s.id = a.record_id WHERE a.record_type = 'source' AND s.base_id = :b");
        $files->execute(['b' => $id]);
        $paths = $files->fetchAll();
        $pdo->prepare("DELETE FROM notes WHERE (record_type = 'base' AND record_id = :b)
                          OR (record_type = 'source' AND record_id IN (SELECT id FROM sources WHERE base_id = :b))
                          OR (record_type = 'section' AND record_id IN (SELECT id FROM sections WHERE base_id = :b))")->execute(['b' => $id]);
        $pdo->prepare("DELETE FROM attachments WHERE record_type = 'source' AND record_id IN (SELECT id FROM sources WHERE base_id = :b)")->execute(['b' => $id]);
        $pdo->prepare('DELETE FROM knowledge_bases WHERE id = :b')->execute(['b' => $id]);
        $GLOBALS['__delete_after_commit'] = array_map(static fn (array $r): string => APP_ROOT . '/storage/' . $r['storage_path'], $paths);
        return ['phase' => 'deleted', 'sections' => $sections, 'sources' => $sources, 'build_id' => null];
    }
    // An archived base takes no build (db/009): it is live for the moment the forget build is queued — under its deleting- key,
    // which no other base can hold — and archived again in the same transaction.
    $pdo->prepare("UPDATE knowledge_bases SET key = 'deleting-' || id, archived_at = NULL WHERE id = :b")->execute(['b' => $id]);
    $pdo->prepare("UPDATE sources SET status = 'forgotten' WHERE base_id = :b AND status <> 'forgotten'")->execute(['b' => $id]);
    $forget = (int) one_value($pdo, 'SELECT count(*) FROM sections WHERE base_id = :b AND maludb_document_id IS NOT NULL', ['b' => $id]);
    $st = $pdo->prepare("INSERT INTO builds (base_id, kind, started_by, items_total) VALUES (:b, 'forget', :by, :n) RETURNING id");
    $st->execute(['b' => $id, 'by' => $by, 'n' => $forget]);
    $buildId = (int) $st->fetchColumn();
    $pdo->prepare('UPDATE knowledge_bases SET archived_at = now() WHERE id = :b')->execute(['b' => $id]);
    return ['phase' => 'forgetting', 'sections' => $forget, 'sources' => $sources, 'build_id' => $buildId];
}

/** The files of a base deleted at once go only after its transaction commits (a rollback keeps them). */
function delete_base_files(): int
{
    $n = 0;
    foreach ($GLOBALS['__delete_after_commit'] ?? [] as $path) {
        if (is_file($path) && @unlink($path)) {
            $n++;
            @rmdir(dirname($path));
        }
    }
    $GLOBALS['__delete_after_commit'] = [];
    return $n;
}
