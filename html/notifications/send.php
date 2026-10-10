<?php
declare(strict_types=1);
/**
 * Action `notification` (the Archivist — any agent that reads the base — or settings.manage): `base`, `title` (≤ 200), `body` (Markdown ≤ 4,000; the bell shows its first 500),
 * `kind` (monday_note, the only one). One kn_notify() per curator of the base; the same week again tells nobody twice. Logged `notification.send` (base_id, kind,
 * recipients) — never the body. Location /bases/{id}.
 */
require_once dirname(__DIR__, 2) . '/app/features/proposals/handler.php';
kn_handler_begin();
$pdo = db();
$b = base_from_request($pdo);
$bid = (int) $b['base_id'];
if (!caller_is_agent()) {
    require_right('settings.manage');
}
$kind = req_has('kind') && (string) req_val('kind') !== '' ? (string) req_val('kind') : 'monday_note';
if ($kind !== 'monday_note') {
    refuse(422, 'Only the Monday note is sent this way.');
}
$title = trim((string) ($_POST['title'] ?? ''));
$body = trim(str_replace("\r\n", "\n", (string) ($_POST['body'] ?? '')));
$errors = [];
if ($title === '' || mb_strlen($title) > 200) {
    $errors['title'] = 'Give the note a title of up to 200 characters.';
}
if ($body === '' || mb_strlen($body) > 4000) {
    $errors['body'] = 'Write the note: 1 to 4,000 characters.';
}
if ($errors !== []) {
    kn_refuse_fields($errors);
}
$me = (int) current_member_id();
$told = kn_guard($pdo, static function () use ($pdo, $bid, $title, $body, $me): int {
    $pdo->beginTransaction();
    $n = send_monday_note($pdo, $bid, $title, $body, $me);
    log_activity($pdo, 'notification.send', 'base', $bid, ['base_id' => $bid, 'after' => ['base_id' => $bid, 'kind' => 'monday_note', 'recipients' => $n]]);
    $pdo->commit();
    return $n;
});
kn_done($told === 0 ? 'Nobody to tell this week' : 'Told ' . $told . ' curator' . ($told === 1 ? '' : 's'), $bid, kn_land('/bases/' . $bid), 'notificationChanged', ['base_id' => $bid, 'recipients' => $told]);
