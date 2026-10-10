<?php
declare(strict_types=1);
/** /vocabulary/new?base={id} (screen `term-add`) and /vocabulary/{id}/edit (screen `term-edit`): name, aliases, type, description; Delete on the edit. */
require_once dirname(__DIR__, 2) . '/app/features/guidelines/handler.php';
require_once APP_ROOT . '/app/features/vocabulary/queries.php';
require_human();
$pdo = db();
$id = request_integer('id');
$t = null;
if ($id !== null) {
    $t = find_term($pdo, $id);
    if ($t === null) {
        $base = one_value($pdo, 'SELECT base_id FROM vocabulary_terms WHERE id = :id', ['id' => $id]);
        refuse($base !== null && base_level((int) $base) === 'read' ? 403 : 404, $base !== null && base_level((int) $base) === 'read' ? 'Guidelines and the vocabulary are the curators\'.' : 'Term not found.');
    }
    $b = find_base($pdo, (int) $t['base_id']) ?? refuse(404, 'Term not found.');
    base_admin_view_log($pdo, $b, 'term-edit');
} else {
    $b = base_from_request($pdo, 'term-add');
}
$bid = (int) $b['base_id'];
require_base_right($bid, 'base.manage');
require_base_live($b);
$prefill = null;
if ($t === null && trim(request_string('canonical_name')) !== '') {                                  // slice 6: the graph's Merge — the suggestion as the term, the others as its aliases
    $cn = trim(mb_substr(request_string('canonical_name'), 0, 200));
    $al = array_values(array_filter(array_map('trim', preg_split('/[\n,]/', mb_substr(request_string('aliases'), 0, 4000)) ?: []), static fn (string $a): bool => $a !== '' && mb_strtolower($a) !== mb_strtolower($cn)));
    $have = one_value($pdo, 'SELECT term_id FROM mcp_vocabulary_terms WHERE base_id = :b AND lower(canonical_name) = lower(:n)', ['b' => $bid, 'n' => $cn]);
    if ($have !== null) {                                                                              // a term of that name already exists: change it, keeping its own aliases
        $t = find_term($pdo, (int) $have);
        $t['aliases_list'] = array_values(array_unique(array_merge($t['aliases_list'], $al)));
    } else {
        $prefill = ['canonical_name' => $cn, 'aliases_list' => $al];
    }
}
$screen = $t === null ? 'term-add' : 'term-edit';
base_screen_view($pdo, $screen, $bid);
$types = base_subject_types($pdo, $bid);
if (wants_json()) {
    respond_screen(['term' => $t === null ? null : ['term_id' => (int) $t['term_id'], 'canonical_name' => $t['canonical_name'], 'aliases' => $t['aliases_list'], 'subject_type' => $t['subject_type'], 'description' => $t['description']],
                    'subject_types' => $types]);
}
render_screen($t === null ? 'New term' : 'Change ' . $t['canonical_name'], view('vocabulary/form.php', ['t' => $t, 'b' => $b, 'types' => $types, 'prefill' => $prefill]),
    ['activeNav' => 'bases', 'screen' => $screen, 'entity' => 'term', 'recordId' => $t === null ? '' : (string) $t['term_id']]);
