<?php
declare(strict_types=1);

/**
 * The vocabulary — the writes of slice 3. Every insert, change or delete of a term shapes the graph: the row trigger bumps the base's version
 * and marks the built sections stale (divergence 1: an import bumps once per term). These report the version move like save_guideline().
 */
require_once dirname(__DIR__) . '/guidelines/write.php';

/** Insert (no $id) or update a term. $fields: canonical_name, aliases (list), subject_type (?string), description (?string). */
function save_term(PDO $pdo, ?int $id, int $baseId, array $fields, int $by): array
{
    $before = guideline_base_state($pdo, $baseId);
    $aliases = pg_array_literal(array_map('strval', $fields['aliases'] ?? []));
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO vocabulary_terms (base_id, canonical_name, aliases, subject_type, description, created_by) VALUES (:b, :n, :a, :t, :d, :by) RETURNING id');
        $st->execute(['b' => $baseId, 'n' => $fields['canonical_name'], 'a' => $aliases, 't' => $fields['subject_type'] ?? null, 'd' => $fields['description'] ?? null, 'by' => $by]);
        $id = (int) $st->fetchColumn();
    } else {
        $pdo->prepare('UPDATE vocabulary_terms SET canonical_name = :n, aliases = :a, subject_type = :t, description = :d WHERE id = :id')
            ->execute(['n' => $fields['canonical_name'], 'a' => $aliases, 't' => $fields['subject_type'] ?? null, 'd' => $fields['description'] ?? null, 'id' => $id]);
    }
    $after = guideline_base_state($pdo, $baseId);
    return ['term_id' => $id, 'base_version_before' => $before['version'], 'base_version_after' => $after['version'],
            'stale_marked' => max(0, $after['stale'] - $before['stale']), 'stale_total' => $after['stale']];
}

function delete_term(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT id, base_id, canonical_name FROM vocabulary_terms WHERE id = :id');
    $st->execute(['id' => $id]);
    $t = $st->fetch() ?: throw new DomainException('Not found.');
    $before = guideline_base_state($pdo, (int) $t['base_id']);
    $pdo->prepare('DELETE FROM vocabulary_terms WHERE id = :id')->execute(['id' => $id]);
    $after = guideline_base_state($pdo, (int) $t['base_id']);
    return ['term_id' => $id, 'canonical_name' => $t['canonical_name'], 'base_version_before' => $before['version'], 'base_version_after' => $after['version'],
            'stale_marked' => max(0, $after['stale'] - $before['stale']), 'stale_total' => $after['stale']];
}

/**
 * Upsert rows by lower(canonical_name) in the caller's ONE transaction: a non-empty cell replaces, an empty one keeps. ['added', 'updated',
 * 'base_version_before', 'base_version_after', 'stale_marked', 'stale_total'].
 */
function import_terms(PDO $pdo, int $baseId, array $rows, int $by): array
{
    $before = guideline_base_state($pdo, $baseId);
    $find = $pdo->prepare('SELECT id, canonical_name, aliases, subject_type, description FROM vocabulary_terms WHERE base_id = :b AND lower(canonical_name) = lower(:n)');
    $added = 0;
    $updated = 0;
    foreach ($rows as $r) {
        $find->execute(['b' => $baseId, 'n' => $r['canonical_name']]);
        $cur = $find->fetch();
        if ($cur === false) {
            $pdo->prepare('INSERT INTO vocabulary_terms (base_id, canonical_name, aliases, subject_type, description, created_by) VALUES (:b, :n, :a, :t, :d, :by)')
                ->execute(['b' => $baseId, 'n' => $r['canonical_name'], 'a' => pg_array_literal(clean_aliases($r['aliases'], $r['canonical_name'])), 't' => $r['subject_type'], 'd' => $r['description'], 'by' => $by]);
            $added++;
            continue;
        }
        $aliases = $r['aliases'] === [] ? kn_compile_pg_array((string) $cur['aliases']) : clean_aliases($r['aliases'], (string) $cur['canonical_name']);
        $pdo->prepare('UPDATE vocabulary_terms SET aliases = :a, subject_type = :t, description = :d WHERE id = :id')
            ->execute(['a' => pg_array_literal($aliases), 't' => $r['subject_type'] ?? $cur['subject_type'], 'd' => $r['description'] ?? $cur['description'], 'id' => (int) $cur['id']]);
        $updated++;
    }
    $after = guideline_base_state($pdo, $baseId);
    return ['added' => $added, 'updated' => $updated, 'base_version_before' => $before['version'], 'base_version_after' => $after['version'],
            'stale_marked' => max(0, $after['stale'] - $before['stale']), 'stale_total' => $after['stale']];
}
