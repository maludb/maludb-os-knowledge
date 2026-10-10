<?php
declare(strict_types=1);
/** /ask/conversations?base= (screen `conversations`): my conversations — the base, the first question's first line, the turns, when last asked; a tap reopens it. */
require_once dirname(__DIR__, 2) . '/app/features/ask/handler.php';
require_right('bases.read');
$pdo = db();
$bid = request_integer('base');
$base = null;
if ($bid !== null) {
    $base = one_value($pdo, 'SELECT base_id FROM mcp_bases WHERE base_id = :b', ['b' => $bid]) !== null ? find_base($pdo, $bid) : null;
}
$rows = my_conversations($pdo, (int) current_member_id(), $base !== null ? (int) $base['base_id'] : null);
log_screen_view($pdo, 'conversations');
if (wants_json()) {
    respond_screen(['conversations' => array_map(static fn ($r) => ['conversation_id' => $r['conversation_id'], 'base_id' => (int) $r['base_id'], 'base' => $r['base_name'], 'turns' => (int) $r['turns'],
        'first_question' => mb_substr((string) $r['first_question'], 0, 300), 'last_at' => json_ts($r['last_at'])], $rows)]);
}
render_screen('Conversations', view('ask/conversations.php', ['rows' => $rows, 'base' => $base, 'tz' => member_timezone()]), ['activeNav' => 'ask', 'screen' => 'conversations']);
