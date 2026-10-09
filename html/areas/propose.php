<?php
declare(strict_types=1);
/**
 * Action `focus_area_propose` (base.manage on a curated base — the expert holds it on a base it curates; free): an area with status proposed
 * and a note (required, ≤ 1,000: why); no engine call; each curator told once. Logged `focus_area.propose` (name, note length). Location
 * /bases/{id}/areas.
 */
require_once dirname(__DIR__, 2) . '/app/features/areas/handler.php';
kn_handler_begin();
$pdo = db();
$b = base_from_request($pdo);
$bid = (int) $b['base_id'];
require_base_right($bid, 'base.manage');
require_base_live($b);
[$f, $errors] = area_fields_from_request(null);
$f['proposal_note'] = trim((string) ($_POST['proposal_note'] ?? ''));
if ($f['proposal_note'] === '' || mb_strlen($f['proposal_note']) > 1000) {
    $errors['proposal_note'] = 'Say why: a note of up to 1,000 characters ("a cluster of 14 subjects about returns").';
}
if ($errors !== []) {
    kn_refuse_fields($errors);
}
$me = (int) current_member_id();
$id = kn_guard_fields($pdo, static function () use ($pdo, $bid, $f, $me): int {
    $pdo->beginTransaction();
    $id = propose_area($pdo, $bid, $f, $me);
    area_log($pdo, 'focus_area.propose', $bid, $id, ['after' => ['focus_area_id' => $id, 'name' => $f['name'], 'note_chars' => mb_strlen($f['proposal_note'])]]);
    $pdo->commit();
    return $id;
}, area_field_refusals($f['name']));
kn_done('Proposed the area ' . $f['name'], $id, kn_land('/bases/' . $bid . '/areas', 'proposed'), 'areasChanged', ['base_id' => $bid]);
