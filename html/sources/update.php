<?php
declare(strict_types=1);
/**
 * Action `source_update` (sources.manage on a curated base; free) — title (1–300), language (the CHECK's pattern; blank = none). A field
 * left out keeps its value (the partial-update target). Logged `source.update` (the diff).
 */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
kn_handler_begin();
$pdo = db();
[$s, $b] = source_from_request($pdo);
$bid = (int) $b['base_id'];
require_base_right($bid, 'sources.manage');
if ($s['status'] === 'forgotten') {
    refuse(422, '"' . $s['title'] . '" is removed: nothing of it changes.');
}
$f = [];
$errors = [];
if (req_has('title')) {
    $f['title'] = trim((string) req_val('title'));
    if ($f['title'] === '' || mb_strlen($f['title']) > 300) {
        $errors['title'] = 'Give the source a title of up to 300 characters.';
    }
}
if (req_has('language')) {
    $f['language'] = (string) req_val('language') === '' ? null : (string) req_val('language');
}
if ($errors !== []) {
    kn_refuse_fields($errors);
}
$sid = (int) $s['source_id'];
$me = (int) current_member_id();
$d = kn_guard_fields($pdo, static function () use ($pdo, $sid, $bid, $f, $me): array {
    $pdo->beginTransaction();
    $d = update_source($pdo, $sid, $f, $me);
    if ($d['after'] !== []) {
        source_log($pdo, 'source.update', $bid, $sid, ['before' => $d['before'], 'after' => $d['after']]);
    }
    $pdo->commit();
    return $d;
}, source_field_refusals());
kn_done('Saved ' . ($f['title'] ?? $s['title']), $sid, kn_land(return_path('/sources/' . $sid), 'saved'), 'sourcesChanged', ['base_id' => $bid]);
