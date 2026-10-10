<?php
declare(strict_types=1);
/** /bases/{id}/gaps?since=7|30|90 (screen `gaps`, curators — questions.review): the questions the base could not answer, clustered (kn_question_gaps()), and the thumbs-down answers. */
require_once dirname(__DIR__, 2) . '/app/features/ask/handler.php';
require_right('bases.read');
$pdo = db();
$b = base_from_request($pdo, 'gaps');
$id = (int) $b['base_id'];
require_questions_review($id);
$since = in_array(request_string('since'), ['7', '30', '90'], true) ? request_string('since') : '30';
$gaps = base_gaps($pdo, $id, $since);
$down = thumbs_down($pdo, $id, $since);
base_screen_view($pdo, 'gaps', $id);
if (wants_json()) {
    respond_screen(['base' => present_base($b), 'since_days' => (int) $since, 'gaps' => array_map(static fn ($g) => ['cluster_key' => $g['cluster_key'], 'question_count' => (int) $g['question_count'],
        'sample_question' => mb_substr((string) $g['sample_question'], 0, 200), 'last_asked_at' => json_ts($g['last_asked_at']), 'question_ids' => array_map('intval', kn_pg_literal_list($g['question_ids']) ?? [])], $gaps),
        'thumbs_down' => array_map(static fn ($f) => ['question_id' => (int) $f['question_id'], 'member_name' => $f['member_name'], 'comment' => $f['comment'], 'at' => json_ts($f['updated_at'])], $down)]);
}
render_screen('Gaps · ' . $b['name'], view('questions/gaps.php', ['b' => $b, 'gaps' => $gaps, 'down' => $down, 'since' => $since, 'tabs' => base_tabs($id, base_level($id)), 'tz' => member_timezone()]),
    ['activeNav' => 'bases', 'screen' => 'gaps', 'entity' => 'base', 'recordId' => (string) $id]);
