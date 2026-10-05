<?php
declare(strict_types=1);
/**
 * The home (screen `home`), design §9: a person with no bases sees ONE button — "Create a knowledge base" — and otherwise a
 * plain list of the bases they may read. Every other region of the design's home (builds, recent questions, pins due, the
 * Archivist's note, the curators' gaps, the admin's usage) is a Phase 3 slice's and fills its own key.
 * Reads go through mcp_bases (kn_visible_base_ids once per statement); a private base is its members' alone.
 */
function home_summary(PDO $pdo, int $memberId): array
{
    $bases = $pdo->query('SELECT base_id, name, description, status, visibility, my_level, i_curate, source_count, section_count, stale_count, last_built_at, running_build_id
                            FROM mcp_bases WHERE archived_at IS NULL ORDER BY updated_at DESC, name LIMIT 50')->fetchAll();
    return [
        'bases' => $bases,
        'may' => ['create' => has_right('bases.create'), 'admin' => has_right('settings.manage')],
    ];
}
