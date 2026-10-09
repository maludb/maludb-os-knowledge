<?php
declare(strict_types=1);

/**
 * Focus areas — the writes of slice 3, to the base tables after the gate. The database is the referee (db/008): the slug from the name,
 * unique in the base; a source assigned only to an area of its own base; a proposal stamped with its proposer, a decision with its decider.
 * The engine is the callers' (kn_area_engine_sync / kn_area_engine_remove, app/knowledge/areas.php), after the commit.
 */

/** Insert (no $id) or update an area. $fields: name, description, seed_terms (list), example_questions (list), position, status (active | archived). */
function save_area(PDO $pdo, ?int $id, int $baseId, array $fields, int $by): int
{
    $args = ['n' => $fields['name'], 'd' => $fields['description'] ?? '', 's' => pg_array_literal($fields['seed_terms'] ?? []), 'q' => pg_array_literal($fields['example_questions'] ?? []),
             'p' => $fields['position'] ?? 0];
    if ($id === null) {
        $st = $pdo->prepare("INSERT INTO focus_areas (base_id, name, description, seed_terms, example_questions, position, status, created_by)
                             VALUES (:b, :n, :d, :s, :q, :p, 'active', :by) RETURNING id");
        $st->execute($args + ['b' => $baseId, 'by' => $by]);
        return (int) $st->fetchColumn();
    }
    $renamed = (string) one_value($pdo, 'SELECT name FROM focus_areas WHERE id = :id', ['id' => $id]) !== $fields['name'];
    $pdo->prepare('UPDATE focus_areas SET name = :n, description = :d, seed_terms = :s, example_questions = :q, position = :p, status = :st'
                  . ($renamed ? ", slug = ''" : '') . ' WHERE id = :id')
        ->execute($args + ['st' => $fields['status'] ?? 'active', 'id' => $id]);
    return $id;
}

/** A proposal (the expert's): status proposed, a note, no engine call; each curator told once. */
function propose_area(PDO $pdo, int $baseId, array $fields, int $by): int
{
    $st = $pdo->prepare("INSERT INTO focus_areas (base_id, name, description, seed_terms, example_questions, status, proposed_by, proposal_note, created_by)
                         VALUES (:b, :n, :d, :s, :q, 'proposed', :by, :note, :by) RETURNING id");
    $st->execute(['b' => $baseId, 'n' => $fields['name'], 'd' => $fields['description'] ?? '', 's' => pg_array_literal($fields['seed_terms'] ?? []),
                  'q' => pg_array_literal($fields['example_questions'] ?? []), 'by' => $by, 'note' => $fields['proposal_note']]);
    $id = (int) $st->fetchColumn();
    $cur = $pdo->prepare('SELECT kn_base_curator_ids(:b)');
    $cur->execute(['b' => $baseId]);
    $notify = $pdo->prepare('SELECT kn_notify(:m, \'proposal\', :t, :body, \'focus_area\', :id, :d)');
    foreach ($cur->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $notify->execute(['m' => (int) $m, 't' => 'Proposed area: ' . mb_substr($fields['name'], 0, 150), 'body' => mb_substr($fields['proposal_note'], 0, 500), 'id' => $id, 'd' => 'area:' . $id . ':' . (int) $m]);
    }
    return $id;
}

/** Accept (→ active) or dismiss (→ archived) a proposed area; an open archivist proposal naming it follows. ['proposed_by', 'proposal_id']. */
function decide_area(PDO $pdo, int $id, bool $accept, int $by): array
{
    $st = $pdo->prepare('SELECT status, proposed_by, base_id FROM focus_areas WHERE id = :id FOR UPDATE');
    $st->execute(['id' => $id]);
    $a = $st->fetch() ?: throw new DomainException('Not found.');
    if ($a['status'] !== 'proposed') {
        throw new DomainException('That area is not a proposal.');
    }
    $pdo->prepare('UPDATE focus_areas SET status = :s WHERE id = :id')->execute(['s' => $accept ? 'active' : 'archived', 'id' => $id]);
    $p = $pdo->prepare("UPDATE archivist_proposals SET status = :s WHERE kind = 'area' AND subject_type = 'focus_area' AND subject_id = :id AND status = 'proposed' RETURNING id");
    $p->execute(['s' => $accept ? 'accepted' : 'dismissed', 'id' => $id]);
    $pid = $p->fetchColumn();
    return ['proposed_by' => $a['proposed_by'] === null ? null : (int) $a['proposed_by'], 'proposal_id' => $pid === false ? null : (int) $pid];
}

/** Delete an area (assignments cascade; keys, feeds and questions keep their rows with the area cleared). The engine was the caller's first step. */
function delete_area(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare("SELECT a.name, a.maludb_project_id, (SELECT count(*) FROM source_focus_areas s WHERE s.focus_area_id = a.id) AS sources FROM focus_areas a WHERE a.id = :id");
    $st->execute(['id' => $id]);
    $a = $st->fetch() ?: throw new DomainException('Not found.');
    $pdo->prepare('DELETE FROM focus_areas WHERE id = :id')->execute(['id' => $id]);
    return ['name' => $a['name'], 'project_id' => $a['maludb_project_id'], 'sources' => (int) $a['sources']];
}

/** A source's full set of areas: those named are added, the others removed. ['added', 'removed']. */
function set_source_areas(PDO $pdo, int $sourceId, array $areaIds, int $by): array
{
    $areaIds = array_values(array_unique(array_map('intval', $areaIds)));
    $cur = $pdo->prepare('SELECT focus_area_id FROM source_focus_areas WHERE source_id = :s');
    $cur->execute(['s' => $sourceId]);
    $have = array_map('intval', $cur->fetchAll(PDO::FETCH_COLUMN));
    $add = array_diff($areaIds, $have);
    $remove = array_diff($have, $areaIds);
    $ins = $pdo->prepare('INSERT INTO source_focus_areas (source_id, focus_area_id, assigned_by) VALUES (:s, :a, :by)');
    foreach ($add as $a) {
        $ins->execute(['s' => $sourceId, 'a' => $a, 'by' => $by]);
    }
    if ($remove !== []) {
        $pdo->prepare('DELETE FROM source_focus_areas WHERE source_id = :s AND focus_area_id IN (' . implode(',', array_map('intval', $remove)) . ')')->execute(['s' => $sourceId]);
    }
    return ['added' => count($add), 'removed' => count($remove)];
}

/** One source in or out of one area. Returns whether anything changed. */
function assign_area(PDO $pdo, int $areaId, int $sourceId, bool $assigned, int $by): bool
{
    if ($assigned) {
        $st = $pdo->prepare('INSERT INTO source_focus_areas (source_id, focus_area_id, assigned_by) VALUES (:s, :a, :by) ON CONFLICT DO NOTHING');
        $st->execute(['s' => $sourceId, 'a' => $areaId, 'by' => $by]);
        return $st->rowCount() === 1;
    }
    $st = $pdo->prepare('DELETE FROM source_focus_areas WHERE source_id = :s AND focus_area_id = :a');
    $st->execute(['s' => $sourceId, 'a' => $areaId]);
    return $st->rowCount() === 1;
}
