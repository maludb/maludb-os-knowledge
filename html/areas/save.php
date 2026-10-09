<?php
declare(strict_types=1);
/**
 * Action `focus_area_save` (base.manage on a curated base; free for an agent) — with or without `focus_area`: name (1–120; the slug from the
 * trigger, unique in the base), description, seed terms, example questions, position, status active | archived (never proposed from here).
 * A field left out keeps its value. Then the area's project brought in step best-effort. Logged `focus_area.save` (name, status, counts,
 * engine_linked). Location /areas/{id}.
 */
require_once dirname(__DIR__, 2) . '/app/features/areas/handler.php';
kn_handler_begin();
$pdo = db();
$cur = null;
if (request_integer('focus_area') !== null) {
    [$cur, $b] = area_from_request($pdo);
} else {
    $b = base_from_request($pdo);
}
$bid = (int) $b['base_id'];
require_base_right($bid, 'base.manage');
require_base_live($b);
if ($cur !== null && $cur['status'] === 'proposed') {
    refuse(422, 'Accept or dismiss a proposed area first.');
}
[$f, $errors] = area_fields_from_request($cur);
$f['status'] = $cur['status'] ?? 'active';
if (req_has('status') && (string) req_val('status') !== '') {
    $f['status'] = (string) req_val('status');
    if (!in_array($f['status'], ['active', 'archived'], true)) {
        $errors['status'] = 'An area is active or archived (a proposal is the expert\'s).';
    }
}
if ($errors !== []) {
    kn_refuse_fields($errors);
}
$me = (int) current_member_id();
$aid = $cur === null ? null : (int) $cur['focus_area_id'];
$id = kn_guard_fields($pdo, static fn (): int => save_area($pdo, $aid, $bid, $f, $me), area_field_refusals($f['name']));
$sync = $f['status'] === 'active' ? area_sync_best_effort($pdo, $id) : null;      // an archived area keeps its project; it sends none (kn_compile_projects)
$linked = $f['status'] === 'active' && ($sync['ok'] ?? false);
area_log($pdo, 'focus_area.save', $bid, $id, ['after' => ['focus_area_id' => $id, 'name' => $f['name'], 'status' => $f['status'], 'seed_terms' => count($f['seed_terms']),
    'example_questions' => count($f['example_questions']), 'engine_linked' => $linked, 'created' => $aid === null]]);
$did = $f['status'] !== 'active' ? 'Saved ' . $f['name'] . ' (archived)' : ($linked ? 'Saved — linked in the engine' : 'Saved — the engine will link it before the next build');
kn_done($did, $id, kn_land('/areas/' . $id, $f['status'] !== 'active' ? 'saved' : ($linked ? 'linked' : 'later')), 'areasChanged', ['base_id' => $bid, 'engine_linked' => $linked,
    'project_id' => $sync['project_id'] ?? null]);
