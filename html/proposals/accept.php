<?php
declare(strict_types=1);
/**
 * Action `archivist_proposal_accept` (questions.review on a curated base; people only — the Archivist never accepts; confirmed in the command bar): `proposal`, and
 * by kind `groups[]` (a merge's ticked groups by number; none sent at all = every group), `name` · `description` · `seed_terms` · `example_questions` (an area's, as edited).
 * The effect and the decision are one transaction (proposals/effects.php): an effect the database refuses leaves the proposal open, the refusal on the proposal field.
 * Logged `archivist.accept` (proposal_id, kind, effect, record_id) and the effect's own event (vocabulary.save, focus_area.save, build.start, feed.sync, question.pin,
 * source.retry). Location the screen the effect lands on, else /bases/{id}/proposals.
 */
require_once dirname(__DIR__, 2) . '/app/features/proposals/handler.php';
kn_handler_begin();
require_human();
$pdo = db();
[$p, $b] = proposal_from_request($pdo);
$bid = (int) $b['base_id'];
require_base_right($bid, 'questions.review');
require_base_live($b);
$id = (int) $p['proposal_id'];
if ($p['status'] !== 'proposed') {
    kn_refuse_fields(['proposal' => 'A decided proposal stays ' . $p['status'] . '.']);
}
$form = ['groups' => null, 'area' => null];
if ($p['kind'] === 'merge') {
    $form['groups'] = req_has('groups') ? array_map('intval', request_list('groups') ?? []) : (req_has('groups_sent') ? [] : null);
}
if ($p['kind'] === 'area') {
    [$f, $errors] = area_fields_from_request(proposal_area_cur($p['payload']));
    if ($errors !== []) {
        kn_refuse_fields($errors);
    }
    $form['area'] = $f;
}
$me = (int) current_member_id();
$fx = kn_guard_fields($pdo, static function () use ($pdo, $id, $form, $me): array {
    try {
        return accept_proposal($pdo, $id, $form, $me);
    } catch (DomainException $e) {
        if ($e->getMessage() === 'Not found.') {
            throw $e;
        }
        kn_refuse_fields(['proposal' => $e->getMessage()]);
    }
}, area_field_refusals((string) ($form['area']['name'] ?? '')));
$engine = null;
if (isset($fx['post']['area_id'])) {
    $sync = area_sync_best_effort($pdo, (int) $fx['post']['area_id']);
    $engine = (bool) ($sync['ok'] ?? false);
}
if (isset($fx['post']['move'])) {
    $sent = send_compiled_after($pdo, $bid, $fx['post']['move']);
    $engine = $sent === null ? null : (bool) ($sent['ok'] ?? false);
}
kn_done($fx['did'], $id, kn_land($fx['land'] ?? '/bases/' . $bid . '/proposals', $fx['land'] === null ? $fx['notice'] : null, $fx['land'] === null ? 'proposal-' . $id : null), 'proposalChanged',
    ['base_id' => $bid, 'effect' => $fx['effect'], 'effect_record_id' => $fx['record_id'], 'engine_synced' => $engine]);
