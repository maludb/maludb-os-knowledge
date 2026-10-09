<?php
declare(strict_types=1);

/**
 * Guidelines — the writes of slice 3, to the base table after the gate. The database is the referee (db/008, db/015): it numbers the
 * version, writes the version row and — for a guideline live in a graph-shaping kind before or after the change — bumps the base's
 * guideline_version and marks every extracted section stale. A position-only change is not a save. These report what moved.
 */

/** The base's version and its stale sections, for a before/after. */
function guideline_base_state(PDO $pdo, int $baseId): array
{
    $st = $pdo->prepare("SELECT b.guideline_version, (SELECT count(*) FROM sections x WHERE x.base_id = b.id AND x.status = 'stale') AS stale FROM knowledge_bases b WHERE b.id = :b");
    $st->execute(['b' => $baseId]);
    $r = $st->fetch();
    return ['version' => (int) $r['guideline_version'], 'stale' => (int) $r['stale']];
}

/**
 * Insert (no $id) or update a guideline. $fields: kind, title, body, is_active, position (each optional on an update). $origin: person |
 * agent — a new row's; an existing row keeps its own. ['guideline_id', 'version_no', 'shapes_graph', 'base_version_before',
 * 'base_version_after', 'stale_marked'].
 */
function save_guideline(PDO $pdo, ?int $id, int $baseId, array $fields, string $origin, int $by): array
{
    $before = guideline_base_state($pdo, $baseId);
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO guidelines (base_id, kind, title, body, position, is_active, origin, created_by, updated_by)
                             VALUES (:b, :k, :t, :body, :p, :a, :o, :by, :by) RETURNING id');
        $st->execute(['b' => $baseId, 'k' => $fields['kind'], 't' => $fields['title'] ?? '', 'body' => $fields['body'],
                      'p' => $fields['position'] ?? (int) one_value($pdo, 'SELECT COALESCE(max(position), 0) + 1 FROM guidelines WHERE base_id = :b AND kind = :k', ['b' => $baseId, 'k' => $fields['kind']]),
                      'a' => ($fields['is_active'] ?? true) ? 'true' : 'false', 'o' => $origin, 'by' => $by]);
        $id = (int) $st->fetchColumn();
    } else {
        $set = [];
        $args = ['id' => $id];
        foreach (['kind', 'title', 'body', 'position', 'is_active'] as $col) {
            if (array_key_exists($col, $fields)) {
                $set[] = "$col = :$col";
                $args[$col] = $col === 'is_active' ? ($fields[$col] ? 'true' : 'false') : $fields[$col];
            }
        }
        if ($set !== []) {
            $pdo->prepare('UPDATE guidelines SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($args);
        }
    }
    $after = guideline_base_state($pdo, $baseId);
    $row = $pdo->prepare('SELECT version_no, kind FROM guidelines WHERE id = :id');
    $row->execute(['id' => $id]);
    $g = $row->fetch();
    return ['guideline_id' => $id, 'version_no' => (int) $g['version_no'], 'shapes_graph' => in_array($g['kind'], GUIDELINE_SHAPING, true),
            'base_version_before' => $before['version'], 'base_version_after' => $after['version'], 'stale_marked' => max(0, $after['stale'] - $before['stale']), 'stale_total' => $after['stale']];
}

/** Delete a guideline (an active graph-shaping one bumps the version — the trigger). Returns its facts and the version move. */
function delete_guideline(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT id, base_id, kind, title, version_no, is_active FROM guidelines WHERE id = :id');
    $st->execute(['id' => $id]);
    $g = $st->fetch() ?: throw new DomainException('Not found.');
    $before = guideline_base_state($pdo, (int) $g['base_id']);
    $pdo->prepare('DELETE FROM guidelines WHERE id = :id')->execute(['id' => $id]);
    $after = guideline_base_state($pdo, (int) $g['base_id']);
    return ['guideline_id' => $id, 'kind' => $g['kind'], 'title' => $g['title'], 'version_no' => (int) $g['version_no'],
            'base_version_before' => $before['version'], 'base_version_after' => $after['version'], 'stale_marked' => max(0, $after['stale'] - $before['stale']), 'stale_total' => $after['stale']];
}
