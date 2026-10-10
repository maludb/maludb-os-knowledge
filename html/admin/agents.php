<?php
declare(strict_types=1);
/**
 * /admin/agents (screen `admin-agents`, agents.settings; GET) and action `agents_settings_save` (POST; other — pauses for an agent): the agents maludb-os.json declares (key, the job description's first
 * line, the duty and its schedule in words, the skill and tool counts), the agent members of the mirror (name, roles here, status, last activity, their dispatches by status), and who the expert and the
 * Archivist are — each an agent member or none (slice 5's trigger refuses a human). Hiring, grants and duties are the kernel's (a link to the OS's Agent HR). Logged `agents_settings.save` (the two
 * member ids) — its own event, so the kernel's policy pauses this action and nothing else. Location /admin/agents.
 */
require_once dirname(__DIR__, 2) . '/app/features/admin/handler.php';
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    kn_handler_begin();
    require_right('agents.settings');
    $cur = find_kn_settings($pdo);
    $errors = [];
    foreach (['expert', 'archivist'] as $who) {                      // an agent may send the column's name
        if (!req_has($who) && req_has($who . '_member_id')) {
            $_POST[$who] = $_POST[$who . '_member_id'];
        }
    }
    $sql = 'SELECT id FROM members WHERE id = :id';
    $expert = kn_ref($pdo, 'expert', isset($cur['expert_member_id']) ? (int) $cur['expert_member_id'] : null, $sql, 'agent member', $errors);
    $archivist = kn_ref($pdo, 'archivist', isset($cur['archivist_member_id']) ? (int) $cur['archivist_member_id'] : null, $sql, 'agent member', $errors);
    if ($errors !== []) {
        kn_refuse_fields($errors);
    }
    $d = kn_guard_fields($pdo, static function () use ($pdo, $expert, $archivist): array {
        $pdo->beginTransaction();
        $d = save_agents($pdo, ['expert_member_id' => $expert, 'archivist_member_id' => $archivist]);
        log_activity($pdo, 'agents_settings.save', 'settings', 1, ['before' => $d['before'], 'after' => ['expert_member_id' => $expert, 'archivist_member_id' => $archivist]]);
        $pdo->commit();
        return $d;
    }, ['/^The expert is an agent member/' => ['expert', null], '/^The Archivist is an agent member/' => ['archivist', null]]);
    kn_done($d['after'] === [] ? 'Nothing changed' : 'Saved who the expert and the Archivist are', 1, kn_land('/admin/agents', 'agents'), 'settingsChanged', ['expert_member_id' => $expert, 'archivist_member_id' => $archivist]);
}
require_right('agents.settings');
require_human();
$s = find_kn_settings($pdo);
$members = agents_here($pdo);
$declared = declared_agents();
$note = last_monday_note($pdo);
log_screen_view($pdo, 'admin-agents');
if (wants_json()) {
    respond_screen(['declared' => $declared, 'agents' => array_map('present_agent_member', $members), 'expert_member_id' => isset($s['expert_member_id']) ? (int) $s['expert_member_id'] : null,
        'archivist_member_id' => isset($s['archivist_member_id']) ? (int) $s['archivist_member_id'] : null,
        'last_note' => $note === null ? null : ['at' => json_ts($note['at']), 'base_id' => $note['base_id'], 'recipients' => $note['recipients']], 'agent_hr_url' => os_page_url('/agents')]);
}
render_screen('Agents', view('admin/agents.php', ['declared' => $declared, 'members' => $members, 's' => $s, 'note' => $note, 'tz' => member_timezone(), 'hr' => os_page_url('/agents'),
    'notice' => kn_notice(request_string('notice') ?: null, admin_notices())]), ['activeNav' => 'admin', 'screen' => 'admin-agents']);
