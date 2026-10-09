<?php
declare(strict_types=1);
/**
 * /bases/{id}/compiled (screen `guidelines-compiled`, curators, read-only): the namespace's compiled prompt_template (with {{chunk}}), its
 * SHA-256 and size, the line counts, truncations and conflicts; the compiled answer guidelines; the terms as the engine gets them; for the
 * admin also whether the engine holds this prompt.
 */
require_once dirname(__DIR__, 2) . '/app/features/guidelines/handler.php';
require_right('bases.read');
$pdo = db();
$b = base_from_request($pdo, 'guidelines-compiled');
$id = (int) $b['base_id'];
require_base_curator_screen($id);
$prompt = kn_compile_prompt($pdo, $id);
$system = kn_compile_answer_system($pdo, $id);
$vocabulary = kn_compile_vocabulary($pdo, $id);
$engine = is_kn_admin() ? ['checked' => true, 'holds' => kn_compile_engine_sha('kb:' . $id) === $prompt['sha256']] : ['checked' => false, 'holds' => null];
base_screen_view($pdo, 'guidelines-compiled', $id);
if (wants_json()) {
    respond_screen(['prompt_template' => $prompt['prompt_template'], 'sha256' => $prompt['sha256'], 'chars' => $prompt['chars'], 'lines' => $prompt['lines'], 'truncated' => $prompt['truncated'],
                    'conflicts' => $prompt['conflicts'], 'system' => $system['system'], 'terms' => $vocabulary['terms'], 'engine_holds' => $engine['holds']]);
}
render_screen('What the engine is told', view('guidelines/compiled.php', ['b' => $b, 'prompt' => $prompt, 'system' => $system, 'vocabulary' => $vocabulary, 'engine' => $engine,
    'tabs' => base_tabs($id, base_level($id))]), ['activeNav' => 'bases', 'screen' => 'guidelines-compiled', 'entity' => 'base', 'recordId' => (string) $id]);
