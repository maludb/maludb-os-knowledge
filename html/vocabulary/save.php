<?php
declare(strict_types=1);
/**
 * Action `vocabulary_save` (base.manage on a curated base; free for an agent) — with or without `term`: canonical_name (1–200, unique in the
 * base case-insensitively), aliases (one a line or comma-separated; ≤ 20, each ≤ 200, de-duplicated, never the name), subject_type
 * ([a-z][a-z_]{0,39} or empty), description (≤ 2,000). A field left out keeps its value. The trigger bumps the version; the compiled prompt
 * (its ALIAS lines) is sent best-effort. Logged `vocabulary.save` (term_id, canonical_name, aliases count, subject_type) + the version rows.
 */
require_once dirname(__DIR__, 2) . '/app/features/guidelines/handler.php';
require_once APP_ROOT . '/app/features/vocabulary/queries.php';
require_once APP_ROOT . '/app/features/vocabulary/write.php';
kn_handler_begin();
$pdo = db();
$cur = null;
if (request_integer('term') !== null) {
    [$cur, $b] = term_from_request($pdo);
} else {
    $b = base_from_request($pdo);
}
$bid = (int) $b['base_id'];
require_base_right($bid, 'base.manage');
require_base_live($b);
$name = req_has('canonical_name') ? trim((string) req_val('canonical_name')) : (string) ($cur['canonical_name'] ?? '');
if (req_has('aliases')) {
    $raw = $_POST['aliases'];
    $aliases = is_array($raw) ? array_map('strval', $raw) : (kn_pg_literal_list($raw) ?? (preg_split('/[\n,]/', (string) $raw) ?: []));
} else {
    $aliases = $cur['aliases_list'] ?? [];
}
$aliases = clean_aliases($aliases, $name);
$type = req_has('subject_type') ? trim((string) req_val('subject_type')) : (string) ($cur['subject_type'] ?? '');
$desc = req_has('description') ? trim((string) ($_POST['description'] ?? '')) : (string) ($cur['description'] ?? '');
$reason = term_refusal($name, $aliases, $type, $desc);
if ($reason !== null) {
    $field = str_contains($reason, 'alias') ? 'aliases' : (str_contains($reason, 'type') ? 'subject_type' : (str_contains($reason, 'description') ? 'description' : 'canonical_name'));
    kn_refuse_fields([$field => $reason]);
}
$me = (int) current_member_id();
$tid = $cur === null ? null : (int) $cur['term_id'];
$fields = ['canonical_name' => $name, 'aliases' => $aliases, 'subject_type' => $type === '' ? null : $type, 'description' => $desc === '' ? null : $desc];
$r = kn_guard_fields($pdo, static function () use ($pdo, $tid, $bid, $fields, $me): array {
    $pdo->beginTransaction();
    $r = save_term($pdo, $tid, $bid, $fields, $me);
    guideline_log($pdo, 'vocabulary.save', $bid, 'term', $r['term_id'], ['after' => ['term_id' => $r['term_id'], 'canonical_name' => $fields['canonical_name'],
        'aliases' => count($fields['aliases']), 'subject_type' => $fields['subject_type'], 'created' => $tid === null]]);
    log_version_move($pdo, $bid, $r);
    $pdo->commit();
    return $r;
}, term_field_refusals($name));
$sent = send_compiled_after($pdo, $bid, $r);
kn_done(str_replace('Saved', 'Saved ' . $name, guideline_did($r, $sent)), $r['term_id'], kn_land('/bases/' . $bid . '/vocabulary', guideline_notice_key($r, $sent), 'term-row-' . $r['term_id']),
    'vocabularyChanged', ['base_id' => $bid, 'version' => $r['base_version_after'], 'engine_synced' => $sent === null ? null : (bool) ($sent['ok'] ?? false)]);
