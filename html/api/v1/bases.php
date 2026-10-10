<?php
declare(strict_types=1);
/**
 * GET /api/v1/bases — os.knowledge-bases/1: a key sees its one base (and its one area when it is bound to one), with `calls_today` — the key's own
 * count; a session caller sees every base mcp_bases gives them. People-free: no owner, curator, member or key. The key's call is counted once first.
 */
require_once dirname(__DIR__, 3) . '/app/api/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/keys/handler.php';
api_cors();
api_require_get();
api_authenticate();
api_rate_limit();
$pdo = db();
$key = api_key();
if ($key !== null) {
    api_require_base($key['base_id']);
}
$st = $pdo->prepare("SELECT b.base_id, b.key, b.name, b.description, b.template, b.language, b.status, b.last_built_at, b.source_count, b.section_count
                       FROM mcp_bases b WHERE b.archived_at IS NULL AND (CAST(:only AS bigint) IS NULL OR b.base_id = :only) ORDER BY lower(b.name), b.base_id");
$st->execute(['only' => $key['base_id'] ?? null]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$areas = $pdo->prepare("SELECT slug, name, description, example_questions FROM mcp_focus_areas WHERE base_id = :b AND status = 'active' AND (CAST(:a AS bigint) IS NULL OR focus_area_id = :a) ORDER BY position, lower(name), focus_area_id");
foreach ($rows as &$r) {
    $areas->execute(['b' => (int) $r['base_id'], 'a' => $key['focus_area_id'] ?? null]);
    $r['areas'] = array_map(static fn (array $a): array => ['example_questions' => kn_pg_literal_list($a['example_questions']) ?? []] + $a, $areas->fetchAll(PDO::FETCH_ASSOC));
}
unset($r);
$doc = present_bases_document($rows);
if ($key !== null) {
    $doc['calls_today'] = (int) one_value($pdo, 'SELECT kn_key_calls_today(:t)', ['t' => $key['token_id']]);
}
api_json($doc);
