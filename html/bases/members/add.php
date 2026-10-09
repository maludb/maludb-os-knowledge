<?php
declare(strict_types=1);
/**
 * Action `base_share` (base.share on a curated base; free for an agent) — `member` (a person or an agent, never a guest) or
 * `department`, at `level` read · curate (· own from the owner or an admin). An existing row for the principal changes its level.
 * Logged `base.share` (base_member_id, principal_kind, principal_id, level, changed); the person or the department's members are
 * told by the db/012 trigger. A guest is refused here: they are shared under A guest (base_share_guest, which pauses for an agent).
 */
require_once dirname(__DIR__, 3) . '/app/features/bases/handler.php';
kn_handler_begin();
$pdo = db();
$b = base_from_request($pdo);
$id = (int) $b['base_id'];
require_base_right($id, 'base.share');
require_base_live($b);
$memberId = req_has('member') && (string) req_val('member') !== '' ? request_integer('member') : null;
$deptId = req_has('department') && (string) req_val('department') !== '' ? request_integer('department') : null;
if (($memberId === null) === ($deptId === null)) {
    kn_refuse_fields([$memberId === null ? 'member' : 'department' => 'Name one person, agent or department to share with.']);
}
if ($deptId !== null) {
    $level = share_level_from_request($id, ['read', 'curate']);
    $name = (string) (one_value($pdo, 'SELECT name FROM mcp_departments WHERE department_id = :d', ['d' => $deptId]) ?? 'the department');
    base_share_finish($pdo, $b, 'department', $deptId, null, $level, 'base.share', $name);
}
$level = share_level_from_request($id);
$st = $pdo->prepare('SELECT member_id, display_name, member_kind, is_guest FROM mcp_members WHERE member_id = :m');
$st->execute(['m' => $memberId]);
$m = $st->fetch();
if ($m === false) {
    kn_refuse_fields(['member' => 'That person is not here.']);
}
if (!empty($m['is_guest'])) {
    refuse(422, 'Share with a guest under A guest — an agent\'s request waits for a person\'s approval.');
}
base_share_finish($pdo, $b, $m['member_kind'] === 'agent' ? 'agent' : 'member', (int) $m['member_id'], null, $level, 'base.share', (string) $m['display_name']);
