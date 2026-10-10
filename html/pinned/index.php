<?php
declare(strict_types=1);
/** /pinned?base=&due= (screen `pinned`): the active pinned answers across the bases I read, as cards — review-due first; a curator verifies again or unpins. */
require_once dirname(__DIR__, 2) . '/app/features/ask/handler.php';
require_right('bases.read');
$pdo = db();
$bid = request_integer('base');
$base = $bid !== null && one_value($pdo, 'SELECT base_id FROM mcp_bases WHERE base_id = :b', ['b' => $bid]) !== null ? find_base($pdo, $bid) : null;
$due = request_string('due') !== '';
$pins = find_pins($pdo, $base !== null ? (int) $base['base_id'] : null, $due);
log_screen_view($pdo, 'pinned');
if (wants_json()) {
    respond_screen(['pinned' => array_map(static fn ($p) => ['pinned_answer_id' => (int) $p['pinned_answer_id'], 'base_id' => (int) $p['base_id'], 'title' => $p['title'], 'question_text' => $p['question_text'],
        'answer_md' => $p['answer_md'], 'owner_name' => $p['owner_name'], 'review_until' => $p['review_until'], 'review_due' => (bool) $p['review_due'], 'verified_at' => json_ts($p['verified_at']),
        'source_id' => $p['source_id'] === null ? null : (int) $p['source_id']], $pins)]);
}
render_screen('Pinned answers', view('pinned/index.php', ['pins' => $pins, 'base' => $base, 'due' => $due, 'tz' => member_timezone(), 'notice' => kn_notice(request_string('notice') ?: null, ask_notices())]),
    ['activeNav' => 'pinned', 'screen' => 'pinned']);
