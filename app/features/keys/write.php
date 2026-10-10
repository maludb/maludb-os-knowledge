<?php
declare(strict_types=1);

/**
 * Keys — the writes (slice 7), to mcp_access_tokens after the gate. The database is the referee (db/011): the key is bound to one base for
 * life, its area is an area of that base, its limits default from the settings, rotation keeps the old key the overlap and no longer. A key's
 * raw form is `mcp_` + 48 hex, shown once; only sha256 is stored; neither the raw form nor its hash is ever logged.
 */

/** A new raw key: mcp_ + 48 hex (db/011's form). */
function key_new_raw(): string
{
    return 'mcp_' . bin2hex(random_bytes(24));
}

/** Mint a key for a base. $fields: label, focus_area_id (?int), rate_per_minute (?int), rate_per_day (?int) — null limits take the settings'. ['key_id', 'raw']. */
function mint_key(PDO $pdo, int $baseId, array $fields, int $by): array
{
    $raw = key_new_raw();
    $st = $pdo->prepare("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, base_id, focus_area_id, rate_per_minute, rate_per_day)
                         VALUES (:m, :l, :h, 'api', :b, :a, :pm, :pd) RETURNING id");
    $st->execute(['m' => $by, 'l' => $fields['label'], 'h' => hash('sha256', $raw), 'b' => $baseId, 'a' => $fields['focus_area_id'] ?? null,
                  'pm' => $fields['rate_per_minute'] ?? null, 'pd' => $fields['rate_per_day'] ?? null]);
    return ['key_id' => (int) $st->fetchColumn(), 'raw' => $raw];
}

/** Change a key's label, area or limits (never its base — the trigger). A key not in $fields is kept. ['changed' => names]. */
function save_key(PDO $pdo, int $keyId, array $fields, int $by): array
{
    $st = $pdo->prepare('SELECT label, focus_area_id, rate_per_minute, rate_per_day FROM mcp_access_tokens WHERE id = :id AND scope = \'api\' FOR UPDATE');
    $st->execute(['id' => $keyId]);
    $cur = $st->fetch(PDO::FETCH_ASSOC) ?: throw new DomainException('Not found.');
    $new = array_intersect_key($fields, $cur) + $cur;
    $changed = array_keys(array_filter($new, static fn ($v, string $k): bool => $v !== $cur[$k], ARRAY_FILTER_USE_BOTH));
    if ($changed !== []) {
        $pdo->prepare('UPDATE mcp_access_tokens SET label = :l, focus_area_id = :a, rate_per_minute = :pm, rate_per_day = :pd WHERE id = :id')
            ->execute(['l' => $new['label'], 'a' => $new['focus_area_id'], 'pm' => $new['rate_per_minute'], 'pd' => $new['rate_per_day'], 'id' => $keyId]);
    }
    return ['changed' => $changed];
}

/** Rotate a live key (kn_key_rotate): a new key for the same base, area, limits and minter; the old one expires after the overlap. ['key_id', 'raw', 'old_expires_at', 'overlap_hours']. */
function rotate_key(PDO $pdo, int $keyId, int $by): array
{
    $raw = key_new_raw();
    $new = (int) one_value($pdo, 'SELECT kn_key_rotate(:old, :h)', ['old' => $keyId, 'h' => hash('sha256', $raw)]);
    $old = $pdo->prepare('SELECT expires_at FROM mcp_access_tokens WHERE id = :id');
    $old->execute(['id' => $keyId]);
    return ['key_id' => $new, 'raw' => $raw, 'old_expires_at' => $old->fetchColumn() ?: null, 'overlap_hours' => key_defaults($pdo)['overlap_hours']];
}

/** Revoke a key at once (it answers 401 on the next call). Only that key: one mid-overlap is revoked alone. */
function revoke_key(PDO $pdo, int $keyId, int $by): void
{
    $st = $pdo->prepare("UPDATE mcp_access_tokens SET revoked_at = now() WHERE id = :id AND scope = 'api' AND revoked_at IS NULL");
    $st->execute(['id' => $keyId]);
    if ($st->rowCount() === 0) {
        throw new DomainException('This key is already revoked.');
    }
}
