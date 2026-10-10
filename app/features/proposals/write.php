<?php
declare(strict_types=1);

/**
 * The Archivist's proposals — the writes of slice 8 to the base tables after the gate. The database is the referee (db/012): one open proposal per base,
 * kind and subject; a decided proposal stays decided; the trigger tells the curators of a new one. Refusals of ours are DomainExceptions in a person's words.
 */

/**
 * A proposal made. $fields: base, kind, subject_type?, subject_id?, title (≤ 300), reason, payload (an array; its JSON ≤ 16 KB). The subject must be a record of
 * the base and of the type the kind is about (PROPOSAL_SUBJECTS); the one-open index answers "Already proposed". The proposal's id.
 */
function make_proposal(PDO $pdo, array $fields, int $by): int
{
    $baseId = (int) $fields['base'];
    $kind = (string) $fields['kind'];
    if (!in_array($kind, PROPOSAL_KINDS, true)) {
        throw new DomainException('A proposal is one of: ' . implode(', ', PROPOSAL_KINDS) . '.');
    }
    $type = ($fields['subject_type'] ?? '') === '' ? null : (string) $fields['subject_type'];
    $sid = ($fields['subject_id'] ?? '') === '' || $fields['subject_id'] === null ? null : (int) $fields['subject_id'];
    if (($type === null) !== ($sid === null)) {
        throw new DomainException('Name the subject by its type and its id, or leave both out.');
    }
    $allowed = PROPOSAL_SUBJECTS[$kind];
    if ($type === null && !in_array('none', $allowed, true)) {
        throw new DomainException('A ' . str_replace('_', ' ', $kind) . ' proposal is about ' . implode(' or ', array_map(static fn (string $t): string => 'a ' . str_replace('_', ' ', $t), $allowed)) . '.');
    }
    if ($type !== null && !in_array($type, $allowed, true)) {
        throw new DomainException('A ' . str_replace('_', ' ', $kind) . ' proposal is about ' . (in_array('none', $allowed, true) ? 'no one record' : implode(' or ', array_map(static fn (string $t): string => 'a ' . str_replace('_', ' ', $t), $allowed))) . '.');
    }
    if ($type !== null && !proposal_subject_in_base($pdo, $baseId, $type, $sid)) {
        throw new DomainException('That subject is not in this base.');
    }
    $title = trim((string) $fields['title']);
    if ($title === '' || mb_strlen($title) > 300) {
        throw new DomainException('Give the proposal a title of up to 300 characters.');
    }
    $reason = trim((string) $fields['reason']);
    if ($reason === '') {
        throw new DomainException('Say why: a reason, in a sentence or two.');
    }
    $payload = $fields['payload'] ?? [];
    if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
        throw new DomainException('The payload is a JSON object.');
    }
    $json = json_encode($payload === [] ? new stdClass() : $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || strlen($json) > 16384) {
        throw new DomainException('The payload is a JSON object of up to 16 KB.');
    }
    try {
        $st = $pdo->prepare('INSERT INTO archivist_proposals (base_id, kind, subject_type, subject_id, title, reason, payload, proposed_by)
                             VALUES (:b, :k, :t, :s, :title, :reason, CAST(:p AS jsonb), :by) RETURNING id');
        $st->execute(['b' => $baseId, 'k' => $kind, 't' => $type, 's' => $sid, 'title' => $title, 'reason' => $reason, 'p' => $json, 'by' => $by]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23505') {
            throw new DomainException('Already proposed — an open proposal for this exists.');
        }
        throw $e;
    }
    return (int) $st->fetchColumn();
}

/** A curator's no: the proposal dismissed, the reason kept as payload.decision_note (≤ 500 characters). The decision is the trigger's: a decided one refuses in its words. */
function dismiss_proposal(PDO $pdo, int $id, ?string $note, int $by): void
{
    $st = $pdo->prepare('SELECT status FROM archivist_proposals WHERE id = :id FOR UPDATE');
    $st->execute(['id' => $id]);
    $status = $st->fetchColumn();
    if ($status === false) {
        throw new DomainException('Not found.');
    }
    if ($status !== 'proposed') {
        throw new DomainException('A decided proposal stays ' . $status . '.');
    }
    $note = $note === null ? '' : trim($note);
    $pdo->prepare("UPDATE archivist_proposals SET status = 'dismissed', decided_by = :by,
                          payload = CASE WHEN :note = '' THEN payload ELSE payload || jsonb_build_object('decision_note', CAST(:note AS text)) END WHERE id = :id")
        ->execute(['by' => $by, 'note' => mb_substr($note, 0, 500), 'id' => $id]);
}

/**
 * The Monday note: one kn_notify() per curator of the base, kind monday_note, at most once a week each (the dedupe key names the ISO week). Answers
 * how many were told. The body is Markdown (the bell shows its first 500 characters).
 */
function send_monday_note(PDO $pdo, int $baseId, string $title, string $body, int $by): int
{
    $cur = $pdo->prepare('SELECT kn_base_curator_ids(:b)');
    $cur->execute(['b' => $baseId]);
    $notify = $pdo->prepare("SELECT kn_notify(:m, 'monday_note', :t, :body, 'base', :b, :k)");
    $week = (string) one_value($pdo, "SELECT to_char(now(), 'IYYY-IW')");
    $same = $pdo->prepare("SELECT 1 FROM notifications WHERE member_id = :m AND kind = 'monday_note' AND record_type = 'base' AND record_id = :b
                              AND to_char(created_at, 'IYYY-IW') = to_char(now(), 'IYYY-IW')");
    $told = 0;
    foreach ($cur->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $same->execute(['m' => (int) $m, 'b' => $baseId]);         // kn_notify()'s dedupe lives in the outbox: a person with email off would be told twice
        $seen = $same->fetchColumn() !== false;
        $same->closeCursor();
        if ($seen) {
            continue;
        }
        $notify->execute(['m' => (int) $m, 't' => mb_substr($title, 0, 200), 'body' => $body, 'b' => $baseId, 'k' => 'monday:' . $baseId . ':' . $week . ':' . (int) $m]);
        if ($notify->fetchColumn() !== null) {
            $told++;
        }
        $notify->closeCursor();
    }
    return $told;
}
