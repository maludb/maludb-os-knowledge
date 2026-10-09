<?php
declare(strict_types=1);
/**
 * POST /vocabulary/import.php — `step=check` (writes nothing, logs nothing: the parsed rows — new, update, refused with the line's reason —
 * and the same CSV carried in a hidden field) or `step=apply` (action `vocabulary_import`, base.manage, free for an agent): every good row
 * upserted by lower(canonical_name) in ONE transaction, then the compiled prompt sent once. A refused row refuses nothing else; 501 rows or
 * 1 MB refuse the whole. Logged `vocabulary.save` with after.import (added, updated, refused) + the version rows.
 */
require_once dirname(__DIR__, 2) . '/app/features/guidelines/handler.php';
require_once APP_ROOT . '/app/features/vocabulary/queries.php';
require_once APP_ROOT . '/app/features/vocabulary/write.php';
kn_handler_begin();
$pdo = db();
$b = base_from_request($pdo);
$bid = (int) $b['base_id'];
require_base_right($bid, 'base.manage');
require_base_live($b);
$csv = (string) ($_POST['csv'] ?? '');
if ($csv === '' && isset($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $csv = (string) file_get_contents((string) $_FILES['file']['tmp_name'], false, null, 0, VOCABULARY_IMPORT_MAX_BYTES + 1);
}
if (trim($csv) === '') {
    kn_refuse_fields(['csv' => 'Paste the CSV or choose its file.']);
}
$parsed = parse_vocabulary_csv($csv);
if ($parsed['fatal'] !== null) {
    kn_refuse_fields(['csv' => $parsed['fatal']]);
}
$existing = [];
foreach (find_terms($pdo, $bid, []) as $t) {
    $existing[mb_strtolower($t['canonical_name'])] = true;
}
$check = [];
foreach ($parsed['rows'] as $line => $r) {
    $check[] = ['line' => $line, 'canonical_name' => $r['canonical_name'], 'what' => isset($existing[mb_strtolower($r['canonical_name'])]) ? 'update' : 'new', 'reason' => null];
}
foreach ($parsed['errors'] as $line => $reason) {
    $check[] = ['line' => $line, 'canonical_name' => null, 'what' => 'refused', 'reason' => $reason];
}
usort($check, static fn ($a, $b) => $a['line'] <=> $b['line']);
if ((string) (req_val('step') ?? 'check') !== 'apply') {
    if (wants_json()) {
        respond_screen(['rows' => $check, 'new' => count(array_filter($check, static fn ($c) => $c['what'] === 'new')), 'update' => count(array_filter($check, static fn ($c) => $c['what'] === 'update')),
                        'refused' => count($parsed['errors'])]);
    }
    render_screen('Import terms', view('vocabulary/import.php', ['b' => $b, 'check' => $check, 'csv' => $csv]), ['activeNav' => 'bases', 'screen' => 'vocabulary-import', 'entity' => 'base', 'recordId' => (string) $bid]);
    exit;
}
if ($parsed['rows'] === []) {
    kn_refuse_fields(['csv' => 'No row of the CSV can be imported: ' . implode(' ', array_map(static fn ($l, $r) => "line $l: $r", array_keys($parsed['errors']), $parsed['errors']))]);
}
$me = (int) current_member_id();
$refused = count($parsed['errors']);
$r = kn_guard_fields($pdo, static function () use ($pdo, $bid, $parsed, $me, $refused): array {
    $pdo->beginTransaction();
    $r = import_terms($pdo, $bid, array_values($parsed['rows']), $me);
    guideline_log($pdo, 'vocabulary.save', $bid, 'base', $bid, ['after' => ['import' => ['added' => $r['added'], 'updated' => $r['updated'], 'refused' => $refused]]]);
    log_version_move($pdo, $bid, $r);
    $pdo->commit();
    return $r;
}, term_field_refusals());
$sent = send_compiled_after($pdo, $bid, $r);
$did = $r['added'] . ' added, ' . $r['updated'] . ' updated, ' . $refused . ' refused — the base is now at version ' . $r['base_version_after'];
kn_done($did, $bid, kn_land('/bases/' . $bid . '/vocabulary', 'imported'), 'vocabularyChanged', ['base_id' => $bid, 'added' => $r['added'], 'updated' => $r['updated'], 'refused' => $refused,
    'version' => $r['base_version_after'], 'engine_synced' => $sent === null ? null : (bool) ($sent['ok'] ?? false)]);
