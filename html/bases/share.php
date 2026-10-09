<?php
declare(strict_types=1);
/**
 * /bases/{id}/share (screen `base-share`): who may read or curate the base (mcp_base_members) with Change level and Remove; four add
 * forms — a person or an agent (the record picker, source `person`), a department, a guest (the picker, source `guest`, read only),
 * an application (a catalog key, read only) — and Visibility (private / internal). A curator's: a reader is refused (403).
 */
require_once dirname(__DIR__, 2) . '/app/features/bases/handler.php';
require_human();
$pdo = db();
$b = base_from_request($pdo, 'base-share');
$id = (int) $b['base_id'];
require_base_right($id, 'base.share');
$level = base_level($id);
$members = base_members_list($pdo, $id);
$departments = find_live_departments($pdo);
base_screen_view($pdo, 'base-share', $id);
$may = ['own' => $level === 'own', 'visibility' => $level === 'own' || has_right('base.manage')];
if (wants_json()) {
    respond_screen(['base' => present_base($b), 'members' => array_map('present_base_member', $members), 'visibility' => $b['visibility'],
                    'departments' => array_map(static fn (array $d): array => ['department_id' => (int) $d['department_id'], 'name' => $d['name']], $departments),
                    'levels' => $may['own'] ? ['read', 'curate', 'own'] : ['read', 'curate']]);
}
render_screen('Share ' . $b['name'], view('bases/share.php', ['b' => $b, 'members' => $members, 'departments' => $departments, 'may' => $may,
    'tabs' => base_tabs($id, $level), 'tz' => member_timezone(), 'notice' => kn_notice(request_string('notice') ?: null, base_notices($b))]),
    ['activeNav' => 'bases', 'screen' => 'base-share', 'entity' => 'base', 'recordId' => (string) $id]);
