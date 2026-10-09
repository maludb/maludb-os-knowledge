<?php
declare(strict_types=1);
/**
 * Action `base_share_guest` (base.share on a curated base; external_send — an agent's call pauses for a person's approval) — `guest`
 * (an external member holding Reader alone, mcp_members.is_guest), level read only. Logged `base.share_guest` — its own event, so the
 * kernel's approval policy for it never catches the free base.share. The guest is told (db/012 trigger, kind shared).
 */
require_once dirname(__DIR__, 3) . '/app/features/bases/handler.php';
kn_handler_begin();
$pdo = db();
$b = base_from_request($pdo);
$id = (int) $b['base_id'];
require_base_right($id, 'base.share');
require_base_live($b);
if (req_has('level') && !in_array((string) req_val('level'), ['', 'read'], true)) {
    kn_refuse_fields(['level' => 'A guest reads; they never curate.']);
}
$guestId = request_integer('guest');
if ($guestId === null) {
    kn_refuse_fields(['guest' => 'Choose a guest.']);
}
$st = $pdo->prepare('SELECT member_id, display_name, is_guest FROM mcp_members WHERE member_id = :m');
$st->execute(['m' => $guestId]);
$g = $st->fetch();
if ($g === false || empty($g['is_guest'])) {
    kn_refuse_fields(['guest' => 'Choose a guest — someone outside the business who holds Reader alone.']);
}
base_share_finish($pdo, $b, 'member', (int) $g['member_id'], null, 'read', 'base.share_guest', (string) $g['display_name']);
