<?php
declare(strict_types=1);
/**
 * Action `archivist_proposal_dismiss` (questions.review on a curated base; people only): `proposal`, `note` (why, ≤ 500 characters, optional — kept as payload.decision_note).
 * A decided proposal stays decided (the trigger's words). The Archivist does not propose what a curator dismissed again for 90 days. Logged `archivist.dismiss`
 * (proposal_id, kind, has_note). Location /bases/{id}/proposals.
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
$note = trim((string) ($_POST['note'] ?? ''));
if (mb_strlen($note) > 500) {
    kn_refuse_fields(['note' => 'A reason is up to 500 characters.']);
}
$me = (int) current_member_id();
kn_guard($pdo, static function () use ($pdo, $id, $note, $me, $bid, $p): void {
    $pdo->beginTransaction();
    dismiss_proposal($pdo, $id, $note, $me);
    proposal_log($pdo, 'archivist.dismiss', $bid, $id, ['proposal_id' => $id, 'kind' => $p['kind'], 'has_note' => $note !== '']);
    $pdo->commit();
});
kn_done('Dismissed', $id, kn_land('/bases/' . $bid . '/proposals', 'dismissed', 'proposal-' . $id), 'proposalChanged', ['base_id' => $bid]);
