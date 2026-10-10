<?php
declare(strict_types=1);
/**
 * /sections/{id}?quote= (screen `section-view`, read at 375 first): the heading path as a breadcrumb (the source, then each ancestor), the
 * page chip, the words (escaped, line breaks kept), previous · next in the source's order, "Open the source"; ?quote= marks the first
 * occurrence of the quoted words (exactly, else with the whitespace collapsed, else says they are not here) — slice 5's citation chips
 * link here. A curator also sees the section's status, built version and error; a forgotten section shows no text.
 */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
require_once APP_ROOT . '/app/features/graph/queries.php';
require_right('bases.read');
$pdo = db();
[$x, $b] = section_from_request($pdo, 'section-view');
$xid = (int) $x['section_id'];
$bid = (int) $b['base_id'];
$curate = in_array(base_level($bid), ['own', 'curate'], true);
$quote = mb_substr(request_string('quote'), 0, 2000);
$marked = mark_quote((string) $x['text'], $quote);
base_screen_view($pdo, 'section-view', $bid, ['section_id' => $xid]);
if (wants_json()) {
    respond_screen(['section' => present_section($x, $curate), 'neighbours' => section_neighbours($pdo, $xid), 'ancestors' => section_ancestors($pdo, $xid), 'quote_found' => $marked['found']]);
}
$taught = $x['status'] === 'forgotten' ? null : section_taught($pdo, $xid);       // slice 6: what it taught the graph
render_screen($x['heading'] !== '' ? $x['heading'] : $x['source_title'], view('sections/view.php', ['x' => $x, 'b' => $b, 'curate' => $curate, 'marked' => $marked, 'quote' => $quote, 'taught' => $taught, 'here' => graph_here(),
    'neighbours' => section_neighbours($pdo, $xid), 'ancestors' => section_ancestors($pdo, $xid), 'back' => back_link()]),
    ['activeNav' => 'bases', 'screen' => 'section-view', 'entity' => 'section', 'recordId' => (string) $xid]);
