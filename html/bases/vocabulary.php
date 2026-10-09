<?php
declare(strict_types=1);
/**
 * /bases/{id}/vocabulary?q=&never= (screen `vocabulary`, curators): the terms as a table — name, aliases, type, the description's first
 * line, seeded, never met — the alias conflicts, New term, Import CSV, a filter for the never-met.
 */
require_once dirname(__DIR__, 2) . '/app/features/guidelines/handler.php';
require_once APP_ROOT . '/app/features/vocabulary/queries.php';
require_right('bases.read');
$pdo = db();
$b = base_from_request($pdo, 'vocabulary');
$id = (int) $b['base_id'];
require_base_curator_screen($id);
$filters = ['q' => mb_substr(request_string('q'), 0, 80), 'never' => request_bool('never')];
$terms = find_terms($pdo, $id, $filters);
$conflicts = kn_compile_vocabulary($pdo, $id)['conflicts'];
$may = ['manage' => base_level($id) === 'own' || has_right('base.manage')];
base_screen_view($pdo, 'vocabulary', $id);
if (wants_json()) {
    respond_screen(['terms' => array_map(static fn (array $t): array => ['term_id' => (int) $t['term_id'], 'canonical_name' => $t['canonical_name'], 'aliases' => $t['aliases_list'],
        'subject_type' => $t['subject_type'], 'description' => $t['description'], 'seeded' => $t['maludb_subject_id'] !== null, 'never_met' => $t['never_met']], $terms), 'conflicts' => $conflicts]);
}
render_screen('Vocabulary of ' . $b['name'], view('vocabulary/index.php', ['b' => $b, 'terms' => $terms, 'conflicts' => $conflicts, 'filters' => $filters, 'may' => $may,
    'tabs' => base_tabs($id, base_level($id)), 'notice' => kn_notice(request_string('notice') ?: null, guideline_notices(built_under($pdo, $id)) + ['removed' => ['success', 'Deleted the term.']])]),
    ['activeNav' => 'bases', 'screen' => 'vocabulary', 'entity' => 'base', 'recordId' => (string) $id]);
