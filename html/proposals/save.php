<?php
declare(strict_types=1);
/**
 * Action `archivist_propose` (the base's reach: a curator of the base — or any agent that reads it, the Archivist holding only the role user; free): `base`, `kind`
 * (one of the eleven), `title` (≤ 300), `reason`, `subject_type` + `subject_id` (a record of THIS base, of the type the kind is about), `payload` (a JSON object ≤ 16 KB).
 * One open proposal per base, kind and subject ("Already proposed…"); the database's trigger tells the curators. Logged `archivist.propose` (proposal_id, kind, the
 * subject) — never the reason or the payload. Location /bases/{id}/proposals#proposal-{id}.
 */
require_once dirname(__DIR__, 2) . '/app/features/proposals/handler.php';
kn_handler_begin();
$pdo = db();
$b = base_from_request($pdo);
$bid = (int) $b['base_id'];
if (!caller_is_agent() && !can_curate_base($bid)) {
    refuse(403, 'You may not change this knowledge base.');
}
require_base_live($b);
$errors = [];
$payload = [];
if (req_has('payload')) {
    $raw = $_POST['payload'];
    if (is_array($raw)) {
        $payload = $raw;
    } elseif (trim((string) $raw) !== '') {
        $d = json_decode((string) $raw, true);
        if (!is_array($d) || ($d !== [] && array_is_list($d))) {
            $errors['payload'] = 'The payload is a JSON object.';
        } else {
            $payload = $d;
        }
    }
}
$fields = ['base' => $bid, 'kind' => (string) (req_val('kind') ?? ''), 'title' => (string) (req_val('title') ?? ''), 'reason' => trim((string) ($_POST['reason'] ?? '')),
           'subject_type' => (string) (req_val('subject_type') ?? ''), 'subject_id' => (string) (req_val('subject_id') ?? ''), 'payload' => $payload];
if ($fields['subject_id'] !== '' && filter_var($fields['subject_id'], FILTER_VALIDATE_INT) === false) {
    $errors['subject_id'] = 'A subject id is a whole number.';
}
if ($errors !== []) {
    kn_refuse_fields($errors);
}
$me = (int) current_member_id();
$id = kn_guard($pdo, static function () use ($pdo, $fields, $me, $bid): int {
    $pdo->beginTransaction();
    $id = make_proposal($pdo, $fields, $me);
    proposal_log($pdo, 'archivist.propose', $bid, $id, ['proposal_id' => $id, 'kind' => $fields['kind'], 'subject_type' => $fields['subject_type'] === '' ? null : $fields['subject_type'],
        'subject_id' => $fields['subject_id'] === '' ? null : (int) $fields['subject_id']]);
    $pdo->commit();
    return $id;
});
kn_done('Proposed to the curators of ' . $b['name'], $id, kn_land('/bases/' . $bid . '/proposals', null, 'proposal-' . $id), 'proposalChanged', ['base_id' => $bid, 'kind' => $fields['kind']]);
