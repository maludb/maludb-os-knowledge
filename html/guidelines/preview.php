<?php
declare(strict_types=1);
/**
 * POST /guidelines/preview.php — Preview on a section (not an action: writes nothing, logs nothing). The unsaved draft (base, guideline?,
 * kind, title, body, is_active, position) compiled with kn_compile_prompt() — the chosen section's text in place of {{chunk}} — for the three
 * graph-shaping kinds, or with kn_compile_answer_system() for answering and audience. HTMX gets the partial for #preview; a browser without
 * JavaScript gets the whole form again with the preview filled.
 */
require_once dirname(__DIR__, 2) . '/app/features/guidelines/handler.php';
require_once APP_ROOT . '/app/features/sources/queries.php';
require_post();
require_login();
verify_csrf();
$pdo = db();
$g = null;
if (request_integer('guideline') !== null) {
    [$g, $b] = guideline_from_request($pdo);
} else {
    $b = base_from_request($pdo);
}
$bid = (int) $b['base_id'];
require_base_right($bid, 'base.manage');
$kind = (string) (req_val('kind') ?? 'extraction');
if (!isset(GUIDELINE_KINDS[$kind])) {
    refuse(422, 'The kind is extraction, vocabulary, exclusion, answering or audience.');
}
$draft = ['guideline_id' => $g === null ? null : (int) $g['guideline_id'], 'kind' => $kind, 'title' => (string) (req_val('title') ?? ''),
          'body' => mb_substr((string) ($_POST['body'] ?? ''), 0, 20000), 'is_active' => req_has('is_active') ? kn_yes('is_active') : true,
          'position' => req_has('position') && (string) req_val('position') !== '' && ctype_digit((string) req_val('position')) ? (int) req_val('position') : null];
$sectionId = request_integer('section');
$chunk = '(no section yet — add documents)';
if ($sectionId !== null) {
    $x = find_section($pdo, $sectionId);
    if ($x === null || (int) $x['base_id'] !== $bid) {
        refuse(404, 'Section not found.');
    }
    $chunk = (string) $x['text'];
}
if (in_array($kind, GUIDELINE_SHAPING, true)) {
    $now = kn_compile_prompt($pdo, $bid);
    $with = kn_compile_prompt($pdo, $bid, $draft);
    $warnings = [];
    foreach ($with['truncated'] as $block => $n) { $warnings[] = $n . ' ' . strtoupper(str_replace('_', ' ', $block)) . ' line' . ($n === 1 ? '' : 's') . ' did not fit — shorten the ' . ($block === 'term' ? 'vocabulary' : str_replace('_', ' ', $block) . ' guidelines') . '.'; }
    foreach ($with['conflicts'] as $c) { $warnings[] = '"' . $c['alias'] . '" is claimed by ' . $c['kept_for'] . ' and ' . $c['dropped_for'] . ' — only ' . $c['kept_for'] . ' keeps it.'; }
    $engine = null;
    if ($sectionId !== null) {
        $ep = kn_engine_preview('kb:' . $bid, $chunk);
        $engine = $ep['ok'] ? trim((string) ($ep['data']['system_prompt'] ?? '') . "\n\n" . (string) ($ep['data']['user_message'] ?? '')) : null;
        $engine = $engine === '' ? null : $engine;
    }
    $p = ['shaping' => true, 'changes' => $with['sha256'] !== $now['sha256'], 'lines' => $with['lines'], 'warnings' => $warnings,
          'text' => str_replace('{{chunk}}', $chunk, $with['prompt_template']), 'engine' => $engine];
} else {
    $sys = kn_compile_answer_system($pdo, $bid, null, $draft);
    $p = ['shaping' => false, 'text' => $sys['system'], 'engine' => null, 'changes' => $sys['sha256'] !== kn_compile_answer_system($pdo, $bid)['sha256']];
}
if (wants_json()) {
    respond_screen(['preview' => $p]);
}
if (is_htmx_request()) {
    echo view('guidelines/partials/preview.php', ['p' => $p]);
    exit;
}
render_screen('Preview', view('guidelines/form.php', ['g' => $g, 'b' => $b, 'draft' => $draft, 'sections' => preview_sections($pdo, $bid), 'section' => $sectionId, 'preview' => $p,
    'stale' => built_under($pdo, $bid)['total']]), ['activeNav' => 'bases', 'screen' => $g === null ? 'guideline-add' : 'guideline-edit', 'entity' => 'guideline', 'recordId' => $g === null ? '' : (string) $g['guideline_id']]);
