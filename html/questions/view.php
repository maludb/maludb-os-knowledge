<?php
declare(strict_types=1);
/**
 * /questions/{id} (screen `question-view`): one question as its asker or a curator sees it (mcp_questions) — the turn (answer, citations,
 * evidence, thumbs, pin, the expert), the door, the asker, the guideline version, model and latency; the feedback. Anyone else: 404.
 */
require_once dirname(__DIR__, 2) . '/app/features/ask/handler.php';
require_right('bases.read');
$pdo = db();
[$q, $b] = question_from_request($pdo);
$qid = (int) $q['question_id'];
$bid = (int) $q['base_id'];
$curate = in_array(base_level($bid), ['own', 'curate'], true);
$t = ask_turn_data($pdo, $q);
$feedback = question_feedback_rows($pdo, $qid);
base_screen_view($pdo, 'question-view', $bid, ['question_id' => $qid]);
if (wants_json()) {
    $area = $q['focus_area_id'] !== null ? (q_area_row($pdo, (int) $q['focus_area_id'])) : null;
    $doc = ask_present_question($q, $b, $area, $t['citations']);
    $doc += ['asker_name' => $q['asker_name'], 'key_label' => $q['key_label'], 'application_key' => $q['application_key'], 'door' => $q['door'], 'created_at' => json_ts($q['created_at']), 'my_rating' => $t['rating'],
             'pinned' => (bool) $q['pinned'], 'feedback' => array_map(static fn ($f) => ['member_name' => $f['member_name'], 'rating' => $f['rating'], 'comment' => $f['comment'], 'at' => json_ts($f['updated_at'])], $feedback)];
    if ($curate) {
        $doc += ['usage' => json_decode((string) $q['usage'], true) ?: new stdClass(), 'model' => $q['model'], 'latency_ms' => $q['latency_ms'] === null ? null : (int) $q['latency_ms']];
    }
    respond_screen($doc);
}
render_screen('Question', view('questions/view.php', ['q' => $q, 'b' => $b, 't' => $t, 'feedback' => $feedback, 'curate' => $curate, 'tz' => member_timezone(),
    'notice' => kn_notice(request_string('notice') ?: null, ask_notices())]), ['activeNav' => 'ask', 'screen' => 'question-view', 'entity' => 'question', 'recordId' => (string) $qid]);

function q_area_row(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, name, slug FROM focus_areas WHERE id = :a');
    $st->execute(['a' => $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
