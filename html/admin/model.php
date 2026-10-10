<?php
declare(strict_types=1);
/**
 * /admin/model (screen `admin-model`, model.manage; GET) and action `model_config_save` (POST; other — pauses for an agent; confirmed): the engine's model. The page shows the business's
 * connection (the reference namespace kb:0 — provider, model id, embedding model, base URL, the secret's NAME), the engine's health, the admin's last recorded choice, what a chosen base's
 * namespace holds, and a Test: the base's compiled prompt with a chosen section in place of {{chunk}} — "what the model would be sent" (MaluDB cannot yet run a dry extraction, M5).
 * The save writes kb:0 first (refused → nothing else changes), records the choice, sends every live base the new connection and — for a new embedding model, with the box ticked —
 * marks every base's sections stale. Logged `model_config.save` (before/after of the two values, bases_sent, bases_failed, rebuild_marked). Location /admin/model.
 */
require_once dirname(__DIR__, 2) . '/app/features/admin/handler.php';
require_once APP_ROOT . '/app/features/guidelines/queries.php';       // preview_sections()
require_once APP_ROOT . '/app/knowledge/compile.php';                 // the engine module and kn_compile_connection()
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    kn_handler_begin();
    require_right('model.manage');
    $fields = [];
    foreach (['model_alias', 'embedding_model'] as $k) {
        if (req_has($k)) {
            $fields[$k] = (string) req_val($k);
        }
    }
    $me = (int) current_member_id();
    $r = kn_guard($pdo, static fn (): array => save_model_config($pdo, $fields, kn_yes('rebuild_confirmed'), $me));
    if (!$r['ok']) {
        kn_refuse_fields([(string) ($r['field'] ?? 'model_alias') => (string) $r['error']]);
    }
    log_activity($pdo, 'model_config.save', 'settings', 1, ['before' => $r['before'], 'after' => $r['after'] + ['bases_sent' => $r['bases_sent'], 'bases_failed' => count($r['bases_failed']),
        'failed_base_ids' => array_map('intval', array_keys($r['bases_failed'])), 'rebuild_marked' => $r['rebuild_marked']]]);
    $_SESSION['model_saved'] = ['sent' => $r['bases_sent'], 'failed' => $r['bases_failed'], 'marked' => $r['rebuild_marked']];      // shown once on the page it lands on
    $words = 'Saved the model — sent to ' . $r['bases_sent'] . ' base' . ($r['bases_sent'] === 1 ? '' : 's')
        . ($r['bases_failed'] !== [] ? '; ' . count($r['bases_failed']) . ' could not be sent yet' : '') . ($r['rebuild_marked'] > 0 ? '; every base needs a rebuild' : '');
    kn_done($words, 1, kn_land('/admin/model', 'model'), 'settingsChanged', ['bases_sent' => $r['bases_sent'], 'bases_failed' => count($r['bases_failed']), 'failed_base_ids' => array_map('intval', array_keys($r['bases_failed'])),
        'rebuild_marked' => $r['rebuild_marked']]);
}
require_right('model.manage');
require_human();
$baseId = request_integer('base');
if ($baseId !== null && find_base($pdo, $baseId) === null) {
    $baseId = null;
}
$sectionId = request_integer('section');
$saved = $_SESSION['model_saved'] ?? null;
unset($_SESSION['model_saved']);
if ($saved !== null) {
    foreach ($saved['failed'] as $bid => $code) {
        $saved['failed'][$bid] = ['name' => (string) (one_value($pdo, 'SELECT name FROM mcp_bases WHERE base_id = :b', ['b' => (int) $bid]) ?? ('Base ' . $bid)), 'words' => kn_engine_error_message((string) $code)];
    }
}
$state = model_state($pdo, $baseId);
$sections = $baseId !== null ? preview_sections($pdo, $baseId) : [];
$test = null;
if ($sectionId !== null && $baseId !== null) {
    $test = model_test($pdo, $sectionId);
    if ($test !== null && $test['base_id'] !== $baseId) {
        $test = null;
    }
}
log_screen_view($pdo, 'admin-model');
if (wants_json()) {
    respond_screen($state + ['test' => $test === null ? null : ['section_id' => $test['section_id'], 'heading_path' => $test['heading_path'], 'text' => $test['text'], 'sha256' => $test['sha256']],
        'note' => 'This shows the prompt the model would be sent — MaluDB cannot yet run a dry extraction (M5).']);
}
render_screen('Model', view('admin/model.php', ['st' => $state, 'base_id' => $baseId, 'base_label' => $baseId !== null ? picker_label($pdo, 'base', $baseId) : null, 'sections' => $sections, 'section_id' => $sectionId,
    'test' => $test, 'saved' => $saved, 'notice' => kn_notice(request_string('notice') ?: null, admin_notices())]), ['activeNav' => 'admin', 'screen' => 'admin-model']);
