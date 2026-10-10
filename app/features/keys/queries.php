<?php
declare(strict_types=1);

/**
 * Keys — the reads (slice 7). A key is an mcp_access_tokens row of scope `api` bound to one base; the caller sees only the keys of the bases
 * they curate, through mcp_keys (db/014), which never selects the hash. The token API's own reads (a key's questions) are here too.
 */

/** The settings' defaults a key's limits start from, and the overlap a rotation keeps the old key for. */
function key_defaults(PDO $pdo): array
{
    $r = $pdo->query('SELECT key_rate_per_minute, key_rate_per_day, key_rotation_overlap_hours FROM kn_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['per_minute' => (int) ($r['key_rate_per_minute'] ?? 30), 'per_day' => (int) ($r['key_rate_per_day'] ?? 2000), 'overlap_hours' => (int) ($r['key_rotation_overlap_hours'] ?? 24)];
}

/** The keys of a base (mcp_keys), live first, the newest first, with the area's name. */
function find_keys(PDO $pdo, int $baseId): array
{
    $st = $pdo->prepare('SELECT k.*, a.name AS area_name, a.slug AS area_slug, o.label AS rotated_from_label
                           FROM mcp_keys k LEFT JOIN mcp_focus_areas a ON a.focus_area_id = k.focus_area_id LEFT JOIN mcp_keys o ON o.key_id = k.rotated_from
                          WHERE k.base_id = :b ORDER BY k.is_live DESC, k.created_at DESC, k.key_id DESC');
    $st->execute(['b' => $baseId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** One key as the caller may see it (a base they curate), or null. */
function find_key(PDO $pdo, int $keyId): ?array
{
    $st = $pdo->prepare('SELECT k.*, a.name AS area_name, a.slug AS area_slug, o.label AS rotated_from_label
                           FROM mcp_keys k LEFT JOIN mcp_focus_areas a ON a.focus_area_id = k.focus_area_id LEFT JOIN mcp_keys o ON o.key_id = k.rotated_from
                          WHERE k.key_id = :k');
    $st->execute(['k' => $keyId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Every key of every base the caller curates (slice 9's all-keys page reads it). $f: base, live (bool), q (label). */
function all_keys(PDO $pdo, array $f = []): array
{
    $st = $pdo->prepare('SELECT k.*, b.name AS base_name, a.name AS area_name FROM mcp_keys k JOIN mcp_bases b ON b.base_id = k.base_id LEFT JOIN mcp_focus_areas a ON a.focus_area_id = k.focus_area_id
                          WHERE (CAST(:b AS bigint) IS NULL OR k.base_id = :b) AND (NOT :live OR k.is_live) AND (CAST(:q AS text) IS NULL OR k.label ILIKE :q)
                          ORDER BY b.name, k.is_live DESC, k.created_at DESC LIMIT 500');
    $st->execute(['b' => $f['base'] ?? null, 'live' => !empty($f['live']) ? 'true' : 'false', 'q' => isset($f['q']) && $f['q'] !== '' ? '%' . $f['q'] . '%' : null]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** The active areas of a base a key may be bound to (mcp_focus_areas). */
function key_areas(PDO $pdo, int $baseId): array
{
    $st = $pdo->prepare("SELECT focus_area_id, name, slug FROM mcp_focus_areas WHERE base_id = :b AND status = 'active' ORDER BY position, lower(name), focus_area_id");
    $st->execute(['b' => $baseId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** This key's id and the id of the key it was rotated from: the ids whose questions the key may read. */
function key_token_ids(PDO $pdo, int $tokenId): array
{
    $from = one_value($pdo, 'SELECT rotated_from FROM mcp_access_tokens WHERE id = :t', ['t' => $tokenId]);
    return $from === null ? [$tokenId] : [$tokenId, (int) $from];
}

/** A question as the caller may see it (mcp_questions) — the token API's read; a key adds its own check (key_token_ids). */
function api_find_question(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_questions WHERE question_id = :id');
    $st->execute(['id' => $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
