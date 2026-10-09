<?php
declare(strict_types=1);
/**
 * /bases/new (screen `base-add`, the first run: a name and a template, Done — "More" holds the description and the key) and
 * /bases/{id}/edit (screen `base-edit`: name, key, description, template — the template only while the base is empty).
 * Prefill: `template`.
 */
require_once dirname(__DIR__, 2) . '/app/features/bases/handler.php';
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('base');
$cur = null;
if ($id === null) {
    require_right('bases.create');
} else {
    $cur = base_from_request($pdo, 'base-edit');
    require_base_right((int) $cur['base_id'], 'base.manage');
    require_base_live($cur);
}
$screen = $cur === null ? 'base-add' : 'base-edit';
$templates = find_templates($pdo);
$chosen = $cur['template'] ?? (find_template($pdo, request_string('template')) !== null ? request_string('template') : 'general');
$hasSource = $cur !== null && base_has_live_source($pdo, (int) $cur['base_id']);
$cur === null ? log_screen_view($pdo, $screen) : base_screen_view($pdo, $screen, (int) $cur['base_id']);
if (wants_json()) {
    respond_screen(['base' => $cur === null ? null : present_base($cur), 'templates' => array_map('present_template', $templates), 'template' => $chosen,
                    'template_locked' => $hasSource]);
}
render_screen($cur === null ? 'Create a knowledge base' : 'Change ' . $cur['name'],
    view('bases/form.php', ['cur' => $cur, 'templates' => $templates, 'chosen' => $chosen, 'hasSource' => $hasSource]),
    ['activeNav' => 'bases', 'screen' => $screen, 'entity' => 'base', 'recordId' => $cur === null ? '' : (string) $cur['base_id']]);
