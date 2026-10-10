<?php
declare(strict_types=1);
/** /bases/{id}/questions (screen `questions`, curators — questions.review): the base's log, 50 a page (kn_questions()), with filters; a row opens the question. */
require_once dirname(__DIR__, 2) . '/app/features/ask/handler.php';
require_right('bases.read');
$pdo = db();
$b = base_from_request($pdo, 'questions');
$id = (int) $b['base_id'];
require_questions_review($id);
$filters = ['status' => request_string('status'), 'door' => request_string('door'), 'key' => request_integer('key'), 'area' => request_integer('area'),
            'asker' => request_integer('asker'), 'since' => request_string('since'), 'q' => mb_substr(request_string('q'), 0, 200)];
if ($filters['status'] !== '' && !isset(QUESTION_STATUSES[$filters['status']])) { $filters['status'] = ''; }
if ($filters['door'] !== '' && !in_array($filters['door'], KN_ASK_DOORS, true)) { $filters['door'] = ''; }
if ($filters['since'] !== '' && strtotime($filters['since']) === false) { $filters['since'] = ''; }
$page = max(1, request_integer('page') ?? 1);
$res = base_questions($pdo, $id, $filters, $page);
$areas = ask_areas($pdo, $id);
$names = array_column($areas, 'name', 'focus_area_id');
foreach ($res['rows'] as &$r) { $r['area_name'] = $r['focus_area_id'] !== null ? ($names[$r['focus_area_id']] ?? '') : ''; }
unset($r);
base_screen_view($pdo, 'questions', $id);
if (wants_json()) {
    respond_screen(['base' => present_base($b), 'page' => $page, 'more' => $res['more'], 'questions' => array_map(static fn ($r) => ['question_id' => (int) $r['question_id'], 'asker' => $r['asker_name'],
        'key_label' => $r['key_label'], 'application_key' => $r['application_key'], 'door' => $r['door'], 'question' => mb_substr((string) $r['question'], 0, 300), 'status' => $r['status'],
        'citation_count' => (int) $r['citation_count'], 'verified_citations' => (int) $r['verified_citations'], 'thumbs_up' => (int) $r['thumbs_up'], 'thumbs_down' => (int) $r['thumbs_down'],
        'pinned' => (bool) $r['pinned'], 'created_at' => json_ts($r['created_at'])], $res['rows'])]);
}
render_screen('Questions · ' . $b['name'], view('questions/log.php', ['b' => $b, 'rows' => $res['rows'], 'page' => $page, 'more' => $res['more'], 'filters' => $filters, 'areas' => $areas,
    'tabs' => base_tabs($id, base_level($id)), 'tz' => member_timezone()]), ['activeNav' => 'bases', 'screen' => 'questions', 'entity' => 'base', 'recordId' => (string) $id]);
