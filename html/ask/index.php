<?php
declare(strict_types=1);
/**
 * /ask?base=&area=&c=&q=&pick= (screen `ask`, 375 px first): the window. The base picker (the bases I may ask — remembered in the session),
 * the area chips, the conversation `c` (my turns, oldest first — anyone else's id shows an empty conversation), the composer; `q` prefills it
 * and never sends; an empty conversation shows the suggested questions. A base with nothing built says so (a curator gets Add documents).
 */
require_once dirname(__DIR__, 2) . '/app/features/ask/handler.php';
require_right('bases.read');
$pdo = db();
$me = (int) current_member_id();
$bases = ask_bases($pdo);
$byId = array_column($bases, null, 'base_id');
$want = request_integer('base') ?? (isset($_SESSION['ask_base']) ? (int) $_SESSION['ask_base'] : null);
$base = $want !== null ? ($byId[$want] ?? null) : (count($bases) === 1 ? $bases[0] : null);
if (request_integer('base') !== null && $base === null) {
    refuse(404, 'Knowledge base not found.');
}
$picking = request_string('pick') !== '';
$areas = [];
$area = null;
$turns = [];
$c = '';
if ($base !== null) {
    $bid = (int) $base['base_id'];
    $_SESSION['ask_base'] = $bid;
    $areas = ask_areas($pdo, $bid);
    $slug = request_string('area');
    foreach ($areas as $a) {
        if ($slug !== '' && ($a['slug'] === $slug || (string) $a['focus_area_id'] === $slug)) {
            $area = $a;
        }
    }
    $c = mb_substr(request_string('c'), 0, 120);
    if ($c !== '') {
        foreach (conversation_turns($pdo, $c, $me) as $q) {
            if ((int) $q['base_id'] === $bid) {
                $turns[] = ask_turn_data($pdo, $q, ['in_window' => true]);
            }
        }
        if ($turns === []) {
            $c = '';                                                   // not mine, or another base's: a new conversation
        }
    }
    base_screen_view($pdo, 'ask', $bid);
} else {
    log_screen_view($pdo, 'ask');
}
$limit = min((int) (kn_ask_settings($pdo)['max_question_chars'] ?? 2000), 2000);
$built = $base !== null && (int) $base['built_count'] > 0;
$suggestions = $base !== null ? suggested_questions($pdo, (int) $base['base_id'], $area !== null ? (int) $area['focus_area_id'] : null) : [];
if (wants_json()) {
    respond_screen(['bases' => array_map(static fn ($x) => ['base_id' => (int) $x['base_id'], 'key' => $x['key'], 'name' => $x['name'], 'status' => $x['status'], 'built' => (int) $x['built_count'] > 0, 'my_level' => $x['my_level']], $bases),
        'base_id' => $base !== null ? (int) $base['base_id'] : null, 'focus_area' => $area !== null ? ['slug' => $area['slug'], 'name' => $area['name']] : null,
        'areas' => array_map(static fn ($a) => ['focus_area_id' => (int) $a['focus_area_id'], 'slug' => $a['slug'], 'name' => $a['name']], $areas),
        'conversation_id' => $c ?: null, 'turns' => array_map(static fn ($t) => ask_present_question($t['q'], find_base($pdo, (int) $t['q']['base_id']) ?? [], null, $t['citations']), $turns),
        'suggestions' => $suggestions, 'max_question_chars' => $limit]);
}
render_screen('Ask' . ($base !== null ? ' · ' . $base['name'] : ''), view('ask/index.php', ['bases' => $bases, 'base' => $base, 'areas' => $areas, 'area' => $area, 'turns' => $turns, 'c' => $c,
    'prefill' => mb_substr(request_string('q'), 0, $limit), 'suggestions' => $suggestions, 'limit' => $limit, 'built' => $built,
    'curate' => $base !== null && (bool) $base['i_curate'], 'picking' => $picking, 'notice' => null]), ['activeNav' => 'ask', 'screen' => 'ask', 'entity' => 'base', 'recordId' => (string) ($base['base_id'] ?? '')]);
