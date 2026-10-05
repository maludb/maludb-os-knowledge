<?php
declare(strict_types=1);

/**
 * The sectioning proof (docs/build-specs/sectioning.md): text extraction and sectioning against synthetic fixtures
 * generated here. No database, no network. Run: php tests/sectioning/run.php  (exit 0 = every check passed)
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '1024M');
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false; // silenced with @ on purpose
    }
    throw new ErrorException($msg, 0, $no, $file, $line);
});

require_once __DIR__ . '/../../app/knowledge/extract/extract.php';
require_once __DIR__ . '/fixtures.php';

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    if ($ok) {
        $GLOBALS['pass']++;
        echo "PASS  $name\n";
    } else {
        $GLOBALS['fail']++;
        echo "FAIL  $name" . ($detail === '' ? '' : "  — $detail") . "\n";
    }
}

function group(string $g): void
{
    echo "\n== $g\n";
}

/** Sections tile the text, each body is the trimmed range after its heading line, and headings + texts rebuild it. */
function tiles(array $sections, string $text): string
{
    $len = mb_strlen($text, 'UTF-8');
    $at = 0;
    $rebuilt = '';
    foreach ($sections as $i => $s) {
        if ($s['char_from'] !== $at) {
            return "section $i starts at {$s['char_from']}, expected $at";
        }
        if (!($s['char_from'] <= $s['body_from'] && $s['body_from'] <= $s['char_to'])) {
            return "section $i offsets out of order";
        }
        $body = trim(mb_substr($text, $s['body_from'], $s['char_to'] - $s['body_from'], 'UTF-8'));
        if ($body !== $s['text']) {
            return "section $i text differs from its range";
        }
        $rebuilt .= mb_substr($text, $s['char_from'], $s['body_from'] - $s['char_from'], 'UTF-8') . $s['text'];
        $at = $s['char_to'];
    }
    if ($at !== $len) {
        return "last section ends at $at of $len";
    }
    $ws = static fn(string $x): string => (string) preg_replace('/\s+/u', '', $x);
    return $ws($rebuilt) === $ws($text) ? '' : 'headings + texts do not rebuild the text';
}

function by_heading(array $sections, string $h): ?array
{
    foreach ($sections as $s) {
        if ($s['heading'] === $h) {
            return $s;
        }
    }
    return null;
}

function max_len(array $sections): int
{
    return $sections === [] ? 0 : max(array_map(static fn($s) => mb_strlen($s['text'], 'UTF-8'), $sections));
}

$stubResolver = static function (array $table): callable {
    return static fn(string $host): array => $table[$host] ?? [];
};

try {
    // ==================================================================================================== DOCX
    group('DOCX');
    $docxBody = [
        ['title', 'Staff Handbook'],
        ['p', fx_para(1, 400)],
        ['h1', 'Leave'],
        ['p', fx_para(2, 600)],
        ['h2', 'Carry-over'],
        ['p', fx_para(3, 500)],
        ['h2', 'Notice'],
        ['p', 'Short notice text.'],
        ['h2', 'Requests'],
        ['p', fx_para(4, 450)],
        ['h3', 'Approvals'],
        ['p', fx_para(5, 300)],
        ['h1', 'Travel'],
        ['p', fx_para(6, 350)],
        ['table', [['Item', 'Limit', 'Receipt'], ['Hotel', '150', 'yes'], ['Meals', '40', 'no']]],
    ];
    $docx = fx_docx('handbook.docx', $docxBody);
    $x = kn_extract_text($docx, 'handbook.docx');
    check('docx: ok and kind docx', $x['ok'] && $x['kind'] === 'docx', (string) $x['error']);
    check('docx: not no_text', $x['no_text'] === false);
    check('docx: language from the styles defaults', $x['language'] === 'en-GB', (string) $x['language']);
    $hs = array_map(static fn($b) => [$b['heading'], $b['level']], $x['blocks']);
    check('docx: Title and Heading1–3 found with their levels', $hs === [['Staff Handbook', 1], ['Leave', 1], ['Carry-over', 2], ['Notice', 2], ['Requests', 2], ['Approvals', 3], ['Travel', 1]], json_encode($hs));
    $b0 = $x['blocks'][1];
    check('docx: a heading block\'s offsets point at its text', mb_substr($x['text'], $b0['char_from'], $b0['char_to'] - $b0['char_from']) === 'Leave');
    check('docx: the table flattened to rows', str_contains($x['text'], "Item | Limit | Receipt\nHotel | 150 | yes\nMeals | 40 | no"));
    check('docx: paragraphs separated by a blank line', str_contains($x['text'], "Staff Handbook\n\n" . fx_para(1, 400)));
    check('docx: no pages (a DOCX has none to anchor)', $x['pages'] === []);
    $s = kn_section_document($x, ['title' => 'Staff Handbook']);
    check('docx: sections tile the text', ($t = tiles($s, $x['text'])) === '', $t);
    $leave = by_heading($s, 'Leave');
    $carry = by_heading($s, 'Carry-over');
    check('docx: heading path Handbook › Leave › Carry-over', $carry !== null && $carry['heading_path'] === 'Staff Handbook › Leave › Carry-over', (string) ($carry['heading_path'] ?? ''));
    check('docx: Carry-over\'s parent is Leave', $carry !== null && $leave !== null && $s[$carry['parent_index']]['heading'] === 'Leave');
    check('docx: Leave has no parent (Title is a sibling at level 1)', $leave !== null && $leave['parent_index'] === null);
    $merged = by_heading($s, 'Notice · Requests');
    check('docx: the short Notice merged into its following sibling Requests', $merged !== null && str_contains($merged['text'], 'Short notice text.') && str_contains($merged['text'], 'Requests'));
    check('docx: Approvals (child of the merged section) keeps its parent link', ($ap = by_heading($s, 'Approvals')) !== null && $s[$ap['parent_index']]['heading'] === 'Notice · Requests');
    check('docx: Approvals heading path runs through the merged heading', $ap !== null && $ap['heading_path'] === 'Staff Handbook › Leave › Notice · Requests › Approvals', (string) ($ap['heading_path'] ?? ''));
    check('docx: positions are 1..n in order', array_column($s, 'position') === range(1, count($s)));
    check('docx: page_no null without pages', $s[0]['page_no'] === null);
    check('docx: every section kind is heading', array_unique(array_column($s, 'kind')) === ['heading']);
    $nostyles = kn_extract_text(fx_docx('nostyles.docx', [['h1', 'Alpha'], ['p', fx_para(7, 300)], ['h2', 'Beta'], ['p', fx_para(8, 300)]], false), 'nostyles.docx');
    check('docx without styles.xml: Heading1/Heading2 style ids still headings', array_column($nostyles['blocks'], 'level') === [1, 2]);

    // ==================================================================================================== PPTX
    group('PPTX');
    $pptx = fx_pptx('deck.pptx', [
        ['title' => 'Quarterly Review', 'body' => [fx_para(10, 250), 'Second point here'], 'notes' => 'Speaker notes for the first slide.'],
        ['title' => 'Budget', 'body' => [fx_para(11, 300)], 'notes' => null],
        ['title' => null, 'body' => [fx_para(12, 260)], 'notes' => 'Untitled slide notes.'],
    ]);
    $x = kn_extract_text($pptx, 'deck.pptx');
    check('pptx: ok and kind pptx', $x['ok'] && $x['kind'] === 'pptx', (string) $x['error']);
    check('pptx: slide titles as slide blocks, an untitled slide named by number', array_column($x['blocks'], 'heading') === ['Quarterly Review', 'Budget', 'Slide 3'] && array_unique(array_column($x['blocks'], 'type')) === ['slide'], json_encode(array_column($x['blocks'], 'heading')));
    check('pptx: speaker notes included', str_contains($x['text'], "Notes:\nSpeaker notes for the first slide.") && str_contains($x['text'], 'Untitled slide notes.'));
    check('pptx: slide numbers are not text', !preg_match('/^\d$/m', $x['text']));
    check('pptx: one page per slide', count($x['pages']) === 3 && array_column($x['pages'], 0) === [1, 2, 3]);
    check('pptx: page 2 holds the Budget slide', str_starts_with(mb_substr($x['text'], $x['pages'][1][1], $x['pages'][1][2] - $x['pages'][1][1]), 'Budget'));
    $s = kn_section_document($x, ['title' => 'Deck']);
    check('pptx: sections tile the text', ($t = tiles($s, $x['text'])) === '', $t);
    check('pptx: three slide sections with pages 1–3', count($s) === 3 && array_column($s, 'page_no') === [1, 2, 3] && array_unique(array_column($s, 'kind')) === ['slide'], json_encode(array_column($s, 'page_no')));
    check('pptx: slide heading path', $s[1]['heading_path'] === 'Deck › Budget');

    // ==================================================================================================== XLSX
    group('XLSX');
    $rows = [['Name', 'Department', 'Days', 'Remote']];
    for ($i = 1; $i <= 150; $i++) {
        $rows[] = ['Person ' . $i, $i % 2 ? 'Front Office' : 'Accounting', $i, $i % 3 === 0];
    }
    $xlsx = fx_xlsx('leave.xlsx', ['Balances' => $rows, 'Notes' => [['Topic', 'Text'], ['Policy', '=inline:' . fx_para(13, 120)], ['Gap', null]]]);
    $x = kn_extract_text($xlsx, 'leave.xlsx');
    check('xlsx: ok and kind xlsx', $x['ok'] && $x['kind'] === 'xlsx', (string) $x['error']);
    check('xlsx: sheet names as sheet blocks', array_column($x['blocks'], 'heading') === ['Balances', 'Notes'] && array_unique(array_column($x['blocks'], 'type')) === ['sheet']);
    check('xlsx: shared strings resolved, header repeated on each row', str_contains($x['text'], 'Name: Person 1 | Department: Front Office | Days: 1 | Remote: FALSE'));
    check('xlsx: booleans as TRUE/FALSE', str_contains($x['text'], 'Name: Person 3 | Department: Front Office | Days: 3 | Remote: TRUE'));
    check('xlsx: inline strings read', str_contains($x['text'], 'Topic: Policy | Text: ' . fx_para(13, 120)));
    check('xlsx: a row with only its first cell keeps it', str_contains($x['text'], 'Topic: Gap'));
    check('xlsx: one page per sheet', count($x['pages']) === 2);
    $s = kn_section_document($x, ['title' => 'Leave balances']);
    check('xlsx: sections tile the text', ($t = tiles($s, $x['text'])) === '', $t);
    check('xlsx: the long sheet split into rows parts under 6,000', max_len($s) <= 6000 && count(array_filter($s, static fn($q) => $q['kind'] === 'rows')) >= 2, (string) max_len($s));
    check('xlsx: parts named "(part k of n)"', ($s[0]['heading'] ?? '') === 'Balances (part 1 of ' . (count($s) - 1) . ')', (string) ($s[0]['heading'] ?? ''));
    check('xlsx: every part of a sheet still has the column names', count(array_filter($s, static fn($q) => $q['kind'] === 'rows' && !str_contains($q['text'], 'Department: '))) === 0);
    check('xlsx: the Notes sheet on page 2', end($s)['page_no'] === 2 && end($s)['kind'] === 'sheet');

    // ==================================================================================================== HTML
    group('HTML');
    $html = '<!DOCTYPE html><html lang="en-US"><head><title>Expenses Guide</title><style>body{color:red}</style><script>alert("x")</script></head><body>'
        . '<header><div class="logo">SITE BANNER</div></header><nav><a href="/">Home</a> <a href="/x">Menu item</a></nav>'
        . '<main><h1>Expenses</h1><p>' . fx_para(20, 300) . '</p><script>document.write("injected")</script>'
        . '<h2>Claims</h2><p>' . fx_para(21, 400) . '</p><ul><li>First rule</li><li>Second rule</li></ul>'
        . '<h3>Receipts</h3><p>' . fx_para(22, 350) . '</p><form><input value="secret"><button>Send</button></form>'
        . '<table><tr><th>Kind</th><th>Limit</th></tr><tr><td>Taxi</td><td>30</td></tr></table>'
        . '<div hidden>HIDDEN TEXT</div><div style="display: none">INVISIBLE</div><iframe src="https://x.invalid"></iframe><p onclick="evil()">Clickable &amp; safe.</p>'
        . '<pre>code   line 1' . "\n" . '    indented line 2</pre></main>'
        . '<aside>Related links</aside><footer>Copyright footer text</footer></body></html>';
    $hp = fx_write('page.txt', $html);
    $x = kn_extract_text($hp, 'page.txt');
    check('html: detected from the bytes despite a .txt name', $x['ok'] && $x['kind'] === 'html', (string) $x['kind']);
    check('html: title from <title>', $x['title'] === 'Expenses Guide');
    check('html: language from <html lang>', $x['language'] === 'en-US');
    foreach (['alert', 'injected', 'SITE BANNER', 'Menu item', 'secret', 'Send', 'HIDDEN TEXT', 'INVISIBLE', 'Related links', 'Copyright footer', 'color:red'] as $noise) {
        check("html: noise removed — $noise", !str_contains($x['text'], $noise));
    }
    check('html: h1–h3 as heading blocks', array_map(static fn($b) => [$b['heading'], $b['level']], $x['blocks']) === [['Expenses', 1], ['Claims', 2], ['Receipts', 3]]);
    check('html: list items as dashes', str_contains($x['text'], "- First rule\n\n- Second rule"));
    check('html: table rows flattened', str_contains($x['text'], "Kind | Limit\nTaxi | 30"));
    check('html: entities decoded, handlers gone', str_contains($x['text'], 'Clickable & safe.') && !str_contains($x['text'], 'evil'));
    check('html: <pre> keeps its lines', str_contains($x['text'], "code   line 1\n    indented line 2"));
    $s = kn_section_document($x, ['title' => 'Expenses Guide']);
    check('html: sections tile the text', ($t = tiles($s, $x['text'])) === '', $t);
    check('html: Receipts path', ($r = by_heading($s, 'Receipts')) !== null && $r['heading_path'] === 'Expenses Guide › Expenses › Claims › Receipts');
    $u = kn_extract_url_text('<html><body><article><h2>Only Article</h2><p>' . fx_para(23, 300) . '</p></article><footer>f</footer></body></html>', 'https://example.com/docs/page');
    check('url text: the reader pass on a fetched page', $u['ok'] && $u['kind'] === 'html' && $u['blocks'][0]['heading'] === 'Only Article');
    check('url text: title falls back to the first heading', $u['title'] === 'Only Article');
    $u2 = kn_extract_url_text('<p>no headings here at all</p>', 'https://example.com/docs/page');
    check('url text: with no title nor heading, host and path', $u2['title'] === 'example.com/docs/page', (string) $u2['title']);
    $latin = kn_extract_url_text("<html><head><meta charset=\"iso-8859-1\"></head><body><p>Caf\xE9 cr\xE8me</p></body></html>", 'https://example.com/');
    check('url text: a Latin-1 page read as UTF-8', str_contains($latin['text'], 'Café crème'));

    // ==================================================================================================== Markdown
    group('Markdown');
    $md = "# Engineering Guide\n\n" . fx_para(30, 300) . "\n\n## Setup\n\n" . fx_para(31, 400) . "\n\n```bash\n# not a heading\nmake install\n```\n\n"
        . "### Tools ###\n\n" . fx_para(32, 300) . "\n\nDeployment\n==========\n\n" . fx_para(33, 300) . "\n\nRollback\n--------\n\n" . fx_para(34, 300) . "\n\n~~~\n## also not a heading\n~~~\n";
    $mp = fx_write('guide.bin', $md);
    $x = kn_extract_text($mp, 'guide.bin');
    check('markdown: detected by its headings, not its name', $x['ok'] && $x['kind'] === 'markdown', (string) $x['kind']);
    check('markdown: ATX and setext headings with levels, closing #s stripped', array_map(static fn($b) => [$b['heading'], $b['level']], $x['blocks']) === [['Engineering Guide', 1], ['Setup', 2], ['Tools', 3], ['Deployment', 1], ['Rollback', 2]], json_encode(array_column($x['blocks'], 'heading')));
    check('markdown: fenced code kept verbatim, its # lines not headings', str_contains($x['text'], "```bash\n# not a heading\nmake install\n```") && str_contains($x['text'], "~~~\n## also not a heading\n~~~"));
    check('markdown: text is the source verbatim', $x['text'] === rtrim($md, "\n"));
    $s = kn_section_document($x, ['title' => 'Guide']);
    check('markdown: sections tile the text', ($t = tiles($s, $x['text'])) === '', $t);
    check('markdown: setext Rollback under Deployment', ($r = by_heading($s, 'Rollback')) !== null && $r['heading_path'] === 'Guide › Deployment › Rollback');
    check('markdown: the code block belongs to Setup', ($su = by_heading($s, 'Setup')) !== null && str_contains($su['text'], 'make install'));
    check('markdown: a setext heading\'s range covers its underline', ($d = by_heading($s, 'Deployment')) !== null && mb_substr($x['text'], $d['char_from'], $d['body_from'] - $d['char_from']) === "Deployment\n==========");

    // ==================================================================================================== CSV and JSON
    group('CSV and JSON');
    $csv = "Code,Description,Amount\n";
    for ($i = 1; $i <= 200; $i++) {
        $csv .= "C$i,\"Item $i, with a comma\",$i.50\n";
    }
    $csv .= "C201,\"Quoted\nnewline\",1\n";
    $cp = fx_write('ledger.dat', $csv);
    $x = kn_extract_text($cp, 'ledger.dat');
    check('csv: detected from the bytes', $x['ok'] && $x['kind'] === 'csv', (string) $x['kind']);
    check('csv: header repeated on every row', str_contains($x['text'], 'Code: C7 | Description: Item 7, with a comma | Amount: 7.50'));
    check('csv: a quoted newline stays inside its row', str_contains($x['text'], 'Code: C201 | Description: Quoted newline | Amount: 1'));
    check('csv: no header line on its own', !str_contains($x['text'], 'Code,Description'));
    $s = kn_section_document($x, ['title' => 'Ledger']);
    check('csv: sections tile the text', ($t = tiles($s, $x['text'])) === '', $t);
    check('csv: rows sections of about 4,000 characters', count($s) >= 3 && array_unique(array_column($s, 'kind')) === ['rows'] && max_len($s) <= 6000, count($s) . ' / ' . max_len($s));
    check('csv: no row cut in half', count(array_filter($s, static fn($q) => !preg_match('/^Code: C\d+ /', $q['text']))) === 0);
    check('csv: parts named "Ledger (part k of n)"', $s[0]['heading'] === 'Ledger (part 1 of ' . count($s) . ')');
    $tsv = kn_extract_text(fx_write('t.tsv', "a\tb\n1\t2\n"), 't.tsv');
    check('tsv: tab-separated by its name when two lines are too few to sniff', $tsv['kind'] === 'csv' && $tsv['text'] === 'a: 1 | b: 2', $tsv['text']);
    $json = json_encode(['policy' => ['name' => 'Leave', 'days' => 25, 'carry' => true, 'notes' => null, 'tags' => ['annual', 'paid']], 'owners' => [['name' => 'Ann'], ['name' => 'Bo']], 'empty' => []]);
    $x = kn_extract_text(fx_write('data.csv', (string) $json), 'data.csv');
    check('json: detected from the bytes despite .csv', $x['ok'] && $x['kind'] === 'json', (string) $x['kind']);
    check('json: flattened path: value lines', str_contains($x['text'], "policy.name: Leave\npolicy.days: 25\npolicy.carry: true\npolicy.notes: null\npolicy.tags[0]: annual\npolicy.tags[1]: paid"));
    check('json: one paragraph per top-level member', str_contains($x['text'], "policy.tags[1]: paid\n\nowners[0].name: Ann\nowners[1].name: Bo\n\nempty: (empty)"));
    $x = kn_extract_text(fx_write('list.json', '[{"a":1},{"a":2}]'), 'list.json');
    check('json: a top-level list indexes its members', $x['text'] === "[0].a: 1\n\n[1].a: 2");

    // ==================================================================================================== Plain text, no structure
    group('Plain text and the no-structure fallback');
    $paras = [];
    for ($i = 1; $i <= 30; $i++) {
        $paras[] = fx_para(40 + $i, 700);
    }
    $plain = implode("\n\n", $paras);
    $x = kn_extract_text(fx_write('notes.txt', $plain), 'notes.txt');
    check('text: kind text, no blocks', $x['ok'] && $x['kind'] === 'text' && $x['blocks'] === []);
    check('text: title from the file name', $x['title'] === 'notes');
    $s = kn_section_document($x, ['title' => 'Notes']);
    check('text: sections tile the text', ($t = tiles($s, $x['text'])) === '', $t);
    $lens = array_map(static fn($q) => mb_strlen($q['text']), $s);
    check('text: cut into parts of about 4,000 characters', count($s) >= 5 && min($lens) >= 2500 && max($lens) <= 6000, json_encode($lens));
    check('text: cuts fall on paragraph boundaries', count(array_filter($s, static fn($q) => !in_array(explode("\n\n", $q['text'])[0], $paras, true))) === 0);
    check('text: part sections, level 1, no parents', array_unique(array_column($s, 'kind')) === ['part'] && array_unique(array_column($s, 'level')) === [1] && array_unique(array_column($s, 'parent_index')) === [null]);
    check('text: "Notes (part 2 of n)" heading and path', $s[1]['heading'] === 'Notes (part 2 of ' . count($s) . ')' && $s[1]['heading_path'] === $s[1]['heading']);
    $s2 = kn_section_document($x, ['title' => 'Notes', 'target_chars' => 2000, 'max_chars' => 3000]);
    check('text: target and max are options', max_len($s2) <= 3000 && count($s2) > count($s));
    $short = kn_section_document(kn_extract_text(fx_write('short.txt', "One short paragraph.\n\nAnother."), 'short.txt'), ['title' => 'Short']);
    check('text: a short text is one section named by the title', count($short) === 1 && $short[0]['heading'] === 'Short' && $short[0]['kind'] === 'part');

    // ==================================================================================================== Oversize sections
    group('Oversize sections');
    $big = "# Big\n\n" . implode("\n\n", array_map(static fn($i) => fx_para(80 + $i, 900), range(1, 15))) . "\n\n## Child\n\n" . fx_para(99, 500);
    $x = kn_extract_text(fx_write('big.md', $big), 'big.md');
    $s = kn_section_document($x, ['title' => 'Doc']);
    check('oversize: sections tile the text', ($t = tiles($s, $x['text'])) === '', $t);
    $parts = array_values(array_filter($s, static fn($q) => str_starts_with($q['heading'], 'Big (part ')));
    check('oversize: a 13,000-character section split into parts', count($parts) >= 3, (string) count($parts));
    check('oversize: every part ≤ 6,000', max_len($parts) <= 6000);
    check('oversize: parts are kind part, same level, numbered k of n', array_unique(array_column($parts, 'kind')) === ['part'] && $parts[1]['heading'] === 'Big (part 2 of ' . count($parts) . ')');
    check('oversize: part 1 keeps the heading line, later parts have none', $parts[0]['body_from'] > $parts[0]['char_from'] && $parts[1]['body_from'] === $parts[1]['char_from']);
    $child = by_heading($s, 'Child');
    check('oversize: the child hangs on part 1 and its path uses the plain heading', $child !== null && $s[$child['parent_index']]['heading'] === 'Big (part 1 of ' . count($parts) . ')' && $child['heading_path'] === 'Doc › Big › Child', (string) ($child['heading_path'] ?? ''));
    $mono = "# Mono\n\n" . str_repeat('x', 14000);
    $x = kn_extract_text(fx_write('mono.md', $mono), 'mono.md');
    $s = kn_section_document($x, ['title' => 'M']);
    check('oversize: a 14,000-character single line hard-cut at ≤ 6,000, no whitespace-only part', max_len($s) <= 6000 && count($s) >= 3 && min(array_map(static fn($q) => mb_strlen($q['text']), $s)) > 1000 && tiles($s, $x['text']) === '', (string) count($s));
    $longLine = str_repeat('word ', 3000);
    $x = kn_extract_text(fx_write('line.txt', $longLine), 'line.txt');
    $s = kn_section_document($x, ['title' => 'L']);
    check('oversize: a very long single line of words cut at spaces', max_len($s) <= 6000 && tiles($s, $x['text']) === '' && count(array_filter($s, static fn($q) => str_contains($q['text'], 'wor ') || str_ends_with($q['text'], 'wor'))) === 0);

    // ==================================================================================================== PDF
    group('PDF');
    $pages = [];
    for ($p = 1; $p <= 5; $p++) {
        $lines = ['SMOKE SYNTHETIC HANDBOOK — CONFIDENTIAL', ''];
        if ($p === 1) {
            $lines = array_merge($lines, ['1. Introduction', ''], fx_wrap(fx_para(100, 700)), [''], ['1.1 Purpose', ''], fx_wrap(fx_para(101, 500)), ['']);
        } elseif ($p === 2) {
            $lines = array_merge($lines, ['2. Leave Policy', ''], fx_wrap(fx_para(102, 900)), [''], ['2.1 Annual Leave', ''], fx_wrap(fx_para(103, 600)), ['']);
        } elseif ($p === 3) {
            $lines = array_merge($lines, fx_wrap(fx_para(104, 400)), [''], ['2.1.1 Carry Over Rules', ''], fx_wrap(fx_para(105, 600)), [''], ['DEFINITIONS', ''], fx_wrap(fx_para(106, 500)), ['']);
        } elseif ($p === 4) {
            $lines = array_merge($lines, ['Chapter 3 Expenses', ''], fx_wrap(fx_para(107, 800)), [''], ['Mileage Claims', ''], fx_wrap(fx_para(108, 500)), ['']);
        } else {
            $lines = array_merge($lines, ['Section 4 Closing', ''], fx_wrap(fx_para(109, 600)), ['']);
        }
        $lines[] = 'Page ' . $p . ' of 5';
        $pages[] = $lines;
    }
    $pdf = fx_write('handbook.txt', fx_pdf($pages));
    $x = kn_extract_text($pdf, 'handbook.txt');
    check('pdf: detected from the bytes despite .txt', $x['ok'] && $x['kind'] === 'pdf', (string) $x['error']);
    check('pdf: five page anchors in order', array_column($x['pages'], 0) === [1, 2, 3, 4, 5]);
    check('pdf: page anchors contiguous and inside the text', $x['pages'][0][1] === 0 && end($x['pages'])[2] === mb_strlen($x['text']) && $x['pages'][1][1] > $x['pages'][0][2]);
    check('pdf: the running header removed', !str_contains($x['text'], 'CONFIDENTIAL'));
    check('pdf: the page-number footer removed', !preg_match('/Page \d of 5/', $x['text']));
    check('pdf: page 2 text begins with its heading', str_starts_with(mb_substr($x['text'], $x['pages'][1][1], 30), '2. Leave Policy'));
    check('pdf: no blocks from the reader (the sectioner finds PDF headings)', $x['blocks'] === []);
    $s = kn_section_document($x, ['title' => 'Handbook']);
    check('pdf: sections tile the text', ($t = tiles($s, $x['text'])) === '', $t);
    $hl = array_map(static fn($q) => [$q['heading'], $q['level']], $s);
    $want = [['1. Introduction', 1], ['1.1 Purpose', 2], ['2. Leave Policy', 1], ['2.1 Annual Leave', 2], ['2.1.1 Carry Over Rules', 3], ['DEFINITIONS', 1], ['Chapter 3 Expenses', 1], ['Mileage Claims', 2], ['Section 4 Closing', 2]];
    check('pdf: numbered, chapter, section, all-caps and short-line headings with levels', $hl === $want, json_encode($hl));
    check('pdf: Carry Over path through its numbered parents', ($c = by_heading($s, '2.1.1 Carry Over Rules')) !== null && $c['heading_path'] === 'Handbook › 2. Leave Policy › 2.1 Annual Leave › 2.1.1 Carry Over Rules');
    check('pdf: page_no is the section\'s first page', array_column($s, 'page_no') === [1, 1, 2, 2, 3, 3, 4, 4, 5], json_encode(array_column($s, 'page_no')));
    check('pdf: 2.1 Annual Leave runs across the page break into page 3', ($a = by_heading($s, '2.1 Annual Leave')) !== null && str_contains($a['text'], explode(' ', fx_para(104, 400))[2]) && $a['char_to'] > $x['pages'][2][1]);
    check('pdf: the short-line heading sits under Chapter 3', ($mc = by_heading($s, 'Mileage Claims')) !== null && $s[$mc['parent_index']]['heading'] === 'Chapter 3 Expenses');
    $img = kn_extract_text(fx_write('scan.pdf', fx_pdf([[], [], []], true)), 'scan.pdf');
    check('pdf image-only: ok with no_text', $img['ok'] && $img['no_text'] && $img['text'] === '' && $img['kind'] === 'pdf');
    check('pdf image-only: three empty page anchors', count($img['pages']) === 3);
    check('pdf image-only: no sections', kn_section_document($img) === []);
    $broken = kn_extract_text(fx_write('broken.pdf', "%PDF-1.4\nthis is not a pdf at all\n"), 'broken.pdf');
    check('pdf: a broken PDF is refused as pdf_unreadable', !$broken['ok'] && $broken['error'] === 'pdf_unreadable', (string) $broken['error']);
    putenv('KN_PDFTOTEXT=/nonexistent/pdftotext');
    $miss = kn_extract_text($pdf, 'x.pdf');
    putenv('KN_PDFTOTEXT');
    check('pdf: a missing pdftotext is an error, not a crash', !$miss['ok'] && $miss['error'] === 'pdftotext_missing');
    [$code, , , $to] = kn_run(['/bin/sleep', '5'], 1, 1000);
    check('pdf: the runner kills a process at its timeout', $to === true && $code === 124);

    // ==================================================================================================== Refusals
    group('Refusals');
    $exe = kn_extract_text(fx_write('report.pdf', "MZ\x90\x00\x03\x00\x00\x00" . str_repeat("\0", 64) . 'This program cannot be run in DOS mode'), 'report.pdf');
    check('refuse: a Windows executable named report.pdf', !$exe['ok'] && $exe['error'] === 'executable', (string) $exe['error']);
    $elf = kn_extract_text(fx_write('notes.txt.2', "\x7FELF\x02\x01\x01" . str_repeat("\0", 40)), 'notes.txt');
    check('refuse: an ELF binary named notes.txt', !$elf['ok'] && $elf['error'] === 'executable');
    $sh = kn_extract_text(fx_write('run.md', "#!/bin/sh\nrm -rf /\n"), 'run.md');
    check('refuse: a shell script', !$sh['ok'] && $sh['error'] === 'executable');
    $macho = kn_extract_text(fx_write('m.docx', "\xCF\xFA\xED\xFE" . str_repeat("\0", 32)), 'm.docx');
    check('refuse: a Mach-O binary named .docx', !$macho['ok'] && $macho['error'] === 'executable');
    $svg = kn_extract_text(fx_write('logo.html', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'logo.html');
    check('refuse: SVG (even named .html)', !$svg['ok'] && $svg['error'] === 'svg');
    $svg2 = kn_extract_text(fx_write('pic.txt', "<!-- c -->\n<!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 1.1//EN\" \"x\">\n<svg width=\"1\"></svg>"), 'pic.txt');
    check('refuse: SVG behind a comment and a doctype', !$svg2['ok'] && $svg2['error'] === 'svg');
    $png = kn_extract_text(fx_write('img.txt', "\x89PNG\r\n\x1a\n" . str_repeat("\0", 30)), 'img.txt');
    check('refuse: an image is unsupported', !$png['ok'] && $png['error'] === 'unsupported');
    $doc = kn_extract_text(fx_write('old.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 64)), 'old.doc');
    check('refuse: legacy Office (OLE) by name legacy_office', !$doc['ok'] && $doc['error'] === 'legacy_office');
    $plainzip = kn_extract_text(fx_zip('a.docx', ['readme.txt' => 'hello']), 'a.docx');
    check('refuse: a plain zip named .docx', !$plainzip['ok'] && $plainzip['error'] === 'unsupported');
    $bomb = fx_zip('bomb.docx', ['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="' . KN_NS_W . '"><w:body><w:p><w:r><w:t>' . str_repeat('A', 30 * 1048576) . '</w:t></w:r></w:p></w:body></w:document>']);
    check('refuse: the zip bomb is small on disk', filesize($bomb) < 200000, (string) filesize($bomb));
    $b = kn_extract_text($bomb, 'bomb.docx');
    check('refuse: a zip bomb (30 MB inflating from a few KB)', !$b['ok'] && $b['error'] === 'zip_bomb', (string) $b['error']);
    putenv('KN_ZIP_MAX_ENTRIES=3');
    $many = kn_extract_text($docx, 'handbook.docx');
    putenv('KN_ZIP_MAX_ENTRIES');
    check('refuse: too many zip entries', !$many['ok'] && $many['error'] === 'zip_bomb');
    $xxe = fx_docx('xxe.docx', [['p', 'x']]);
    $z = new ZipArchive();
    $z->open($xxe);
    $z->addFromString('word/document.xml', '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY e SYSTEM "file:///etc/passwd">]><w:document xmlns:w="' . KN_NS_W . '"><w:body><w:p><w:r><w:t>&e;</w:t></w:r></w:p></w:body></w:document>');
    $z->close();
    $xx = kn_extract_text($xxe, 'xxe.docx');
    check('refuse: an OOXML part with a DOCTYPE (XXE, entity bombs)', !$xx['ok'] && $xx['error'] === 'unsafe_xml', (string) $xx['error']);
    putenv('KN_MAX_BYTES=1000');
    $too = kn_extract_text(fx_write('big.txt', str_repeat('a', 1001)), 'big.txt');
    $tooUrl = kn_extract_url_text(str_repeat('a', 1001), 'https://example.com/');
    $tooPaste = kn_extract_pasted_text(str_repeat('a', 1001));
    putenv('KN_MAX_BYTES');
    check('refuse: over KN_MAX_BYTES', !$too['ok'] && $too['error'] === 'too_large' && $too['text'] === '');
    check('refuse: an over-size fetched page and pasted text', $tooUrl['error'] === 'too_large' && $tooPaste['error'] === 'too_large');
    check('refuse: a missing file is unreadable', kn_extract_text('/nonexistent/file', 'x.txt')['error'] === 'unreadable');
    check('refuse: a refusal carries no text and the kind of no structure', $exe['text'] === '' && $exe['blocks'] === [] && $exe['no_text'] === false);

    // ==================================================================================================== URL public-address check
    group('URL public-address check');
    $res = $stubResolver([
        'example.com' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c'],
        'internal.example.com' => ['10.1.2.3'],
        'mixed.example.com' => ['93.184.215.14', '127.0.0.1'],
        'meta.example.com' => ['169.254.169.254'],
        'v6local.example.com' => ['fd00:ec2::254'],
        'mapped.example.com' => ['::ffff:127.0.0.1'],
        'mapped2.example.com' => ['::ffff:93.184.215.14'],
        'cgnat.example.com' => ['100.100.100.200'],
        'linklocal6.example.com' => ['fe80::1'],
        'garbage.example.com' => ['not-an-ip'],
    ]);
    $cases = [
        ['https://example.com/handbook', true],
        ['http://example.com:8080/x', true],
        ['https://example.com:8443/x', true],
        ['https://example.com:443/x', true],
        ['ftp://example.com/x', false],
        ['file:///etc/passwd', false],
        ['gopher://example.com/', false],
        ['javascript:alert(1)', false],
        ['https://user:pass@example.com/', false],
        ['https://user@example.com/', false],
        ['https://example.com@10.0.0.1/', false],
        ['https://example.com:22/', false],
        ['https://example.com:6379/', false],
        ['https://example.com:/', false],
        ['https://internal.example.com/', false],
        ['https://mixed.example.com/', false],
        ['https://meta.example.com/latest/meta-data', false],
        ['https://v6local.example.com/', false],
        ['https://mapped.example.com/', false],
        ['https://mapped2.example.com/', false],
        ['https://cgnat.example.com/', false],
        ['https://linklocal6.example.com/', false],
        ['https://garbage.example.com/', false],
        ['https://unknown.example.com/', false],
        ['http://127.0.0.1/', false],
        ['http://10.0.0.5/', false],
        ['http://172.16.3.4/', false],
        ['http://192.168.1.1/', false],
        ['http://169.254.169.254/latest/meta-data/', false],
        ['http://0.0.0.0/', false],
        ['http://100.64.0.1/', false],
        ['http://224.0.0.1/', false],
        ['http://255.255.255.255/', false],
        ['http://93.184.215.14/', true],
        ['http://[::1]/', false],
        ['http://[::]/', false],
        ['http://[fc00::1]/', false],
        ['http://[fe80::1]/', false],
        ['http://[::ffff:127.0.0.1]/', false],
        ['http://[::ffff:7f00:1]/', false],
        ['http://[::ffff:93.184.215.14]/', false],
        ['http://[64:ff9b::7f00:1]/', false],
        ['http://[2001:db8::1]/', false],
        ['http://[2606:2800:21f:cb07:6820:80da:af6b:8b2c]/', true],
        ['http://[2606:2800:21f:cb07:6820:80da:af6b:8b2c]:22/', false],
        ['http://2130706433/', false],
        ['http://0x7f000001/', false],
        ['http://0x7f.0.0.1/', false],
        ['http://0177.0.0.1/', false],
        ['http://127.1/', false],
        ['http://localhost/', false],
        ['http://LOCALHOST./', false],
        ['http://printer.local/', false],
        ['http://metadata.google.internal/', false],
        ['http://intranet/', false],
        ['http://exa mple.com/', false],
        ["http://example.com\\@evil.com/", false],
        ["http://example.com\x00.evil/", false],
        ['', false],
        ['https://' . str_repeat('a', 2050) . '.com/', false],
    ];
    foreach ($cases as [$url, $want]) {
        check('url ' . ($want ? 'allows ' : 'refuses ') . (strlen($url) > 60 ? substr($url, 0, 40) . '…' : json_encode($url)), kn_url_is_public($url, $res) === $want);
    }
    check('url: the resolver is consulted only for names', kn_url_is_public('http://93.184.215.14/', static fn() => throw new LogicException('called')) === true);

    // ==================================================================================================== Text hygiene
    group('Text hygiene');
    check('clean: CRLF and CR become LF', kn_text_clean("a\r\nb\rc") === "a\nb\nc");
    check('clean: NUL and C0 controls removed, tab and newline kept', kn_text_clean("a\x00b\x01c\x07d\te\nf\x1Bg\x7F") === "abcd\te\nfg");
    check('clean: C1 controls removed', kn_text_clean("a\u{0085}b\u{009F}c") === 'abc');
    check('clean: a leading BOM and stray BOMs removed', kn_text_clean("\xEF\xBB\xBFhello\u{FEFF} world") === 'hello world');
    check('clean: UTF-16LE with its BOM converted', kn_text_clean("\xFF\xFE" . mb_convert_encoding('Grüße', 'UTF-16LE', 'UTF-8')) === 'Grüße');
    check('clean: invalid UTF-8 read as Windows-1252', kn_text_clean("caf\xE9 \x93quoted\x94") === 'café “quoted”');
    check('clean: NFC — e + combining acute composes', kn_text_clean("e\u{0301}") === "\u{00E9}");
    check('clean: NFC — marks reordered canonically then composed', kn_nfc("a\u{0323}\u{0302}") === "\u{1EAD}" && kn_nfc("a\u{0302}\u{0323}") === "\u{1EAD}");
    check('clean: NFC — Hangul jamo compose to a syllable', kn_nfc("\u{1100}\u{1161}\u{11A8}") === "\u{AC01}");
    check('clean: NFC — a singleton decomposes (Ohm sign → Omega)', kn_nfc("\u{2126}") === "\u{03A9}");
    check('clean: NFC — an exclusion stays decomposed', kn_nfc("\u{0958}") === "\u{0915}\u{093C}");
    check('clean: NFC — already-composed text untouched', kn_nfc('Zürich São Paulo 東京') === 'Zürich São Paulo 東京');
    check('clean: U+2028 is a line break', kn_text_clean("a\u{2028}b") === "a\nb");
    check('clean: three or more newlines collapse to a blank line, trailing blanks trimmed', kn_text_clean("a   \n\n\n\nb\t") === "a\n\nb");
    $ctl = kn_extract_text(fx_write('ctl.txt', "Line\x00one\x08\r\nLine two\r\n"), 'ctl.txt');
    check('clean: an extracted file carries no control characters', !preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $ctl['text']) && $ctl['text'] === "Lineone\nLine two");

    // ==================================================================================================== Determinism
    group('Determinism');
    foreach (['handbook.docx' => $docx, 'deck.pptx' => $pptx, 'leave.xlsx' => $xlsx, 'handbook.pdf' => $pdf, 'guide.md' => $mp, 'page.html' => $hp] as $n => $p) {
        $a = kn_extract_text($p, $n);
        $b = kn_extract_text($p, $n);
        check("determinism: $n extracted and sectioned twice, identical", $a === $b && kn_section_document($a, ['title' => 'T']) === kn_section_document($b, ['title' => 'T']));
    }

    // ==================================================================================================== Adversarial
    group('Adversarial');
    $empty = kn_extract_text(fx_write('empty.txt', ''), 'empty.txt');
    check('adversarial: an empty file is ok, no_text, no sections', $empty['ok'] && $empty['no_text'] && kn_section_document($empty) === []);
    $ws = kn_extract_text(fx_write('ws.txt', " \n\n\t \n"), 'ws.txt');
    check('adversarial: whitespace only is no_text', $ws['ok'] && $ws['no_text']);
    $x = kn_extract_text(fx_write('nobody.md', "# Lonely heading\n"), 'nobody.md');
    $s = kn_section_document($x, ['title' => 'T']);
    check('adversarial: a heading with no body is one empty section', count($s) === 1 && $s[0]['heading'] === 'Lonely heading' && $s[0]['text'] === '' && tiles($s, $x['text']) === '');
    $x = kn_extract_text(fx_write('heads.md', "# A\n\n## B\n\n## C\n\n### D\n\n# E\n"), 'heads.md');
    $s = kn_section_document($x, ['title' => 'T']);
    check('adversarial: a document of only headings tiles', tiles($s, $x['text']) === '');
    check('adversarial: only headings — childless siblings merge, parents stay', array_column($s, 'heading') === ['A', 'B · C', 'D', 'E'], json_encode(array_column($s, 'heading')));
    check('adversarial: only headings — D under the merged B · C', $s[2]['heading_path'] === 'T › A › B · C › D');
    $deep = ['kind' => 'html', 'title' => 'Deep', 'text' => '', 'pages' => [], 'blocks' => []];
    $t = '';
    for ($i = 1; $i <= 5000; $i++) {
        $from = mb_strlen($t);
        $t .= "H$i";
        $deep['blocks'][] = ['type' => 'heading', 'level' => $i, 'heading' => "H$i", 'char_from' => $from, 'char_to' => mb_strlen($t)];
        $t .= "\n\nbody $i\n\n";
    }
    $deep['text'] = rtrim($t);
    $s = kn_section_document($deep);
    check('adversarial: 5,000 nested heading levels capped at 9', max(array_column($s, 'level')) === 9 && tiles($s, $deep['text']) === '');
    check('adversarial: the deep chain keeps eight ancestors then merges level-9 siblings', count($s) < 5000 && $s[8]['level'] === 9 && mb_strlen($s[8]['heading']) <= 200, count($s) . ' sections');
    $mixed = kn_extract_text(fx_write('mixed.md', "# One\r\n\r\nalpha\rbeta\n\r\n## Two\r\ngamma"), 'mixed.md');
    check('adversarial: mixed line endings become \\n and headings still found', !str_contains($mixed['text'], "\r") && array_column($mixed['blocks'], 'heading') === ['One', 'Two']);
    $bom = kn_extract_text(fx_write('bom.md', "\xEF\xBB\xBF# Title With BOM\n\nbody"), 'bom.md');
    check('adversarial: a BOM is dropped and the first heading still found at 0', $bom['kind'] === 'markdown' && ($bom['blocks'][0]['char_from'] ?? -1) === 0 && !str_contains($bom['text'], "\u{FEFF}"));
    $uni = "# Überblick\n\n" . str_repeat('日本語のテキスト。', 1000) . "\n\n## Ärger\n\n" . fx_para(120, 300);
    $x = kn_extract_text(fx_write('uni.md', $uni), 'uni.md');
    $s = kn_section_document($x, ['title' => 'Ü']);
    check('adversarial: multibyte text — offsets are characters and sections tile', tiles($s, $x['text']) === '' && ($ae = by_heading($s, 'Ärger')) !== null && mb_substr($x['text'], $ae['char_from'], 5) === '## Är');
    check('adversarial: multibyte text — CJK split at sentence ends under 6,000 chars', max_len($s) <= 6000 && count(array_filter($s, static fn($q) => str_starts_with($q['heading'], 'Überblick (part'))) >= 2);
    $pre = kn_extract_text(fx_write('pre.md', fx_para(130, 400) . "\n\n# After\n\n" . fx_para(131, 400)), 'pre.md');
    $s = kn_section_document($pre, ['title' => 'Pre']);
    check('adversarial: text before the first heading is a preamble named by the title', $s[0]['heading'] === 'Pre' && $s[0]['heading_path'] === 'Pre' && $s[0]['kind'] === 'part' && $s[1]['heading_path'] === 'Pre › After');
    $pre2 = kn_extract_text(fx_write('pre2.md', "Tiny intro.\n\n# After\n\n" . fx_para(132, 400)), 'pre2.md');
    $s = kn_section_document($pre2, ['title' => 'Pre']);
    check('adversarial: a tiny preamble merges into the first heading without adding a heading', count($s) === 1 && $s[0]['heading'] === 'After' && str_starts_with($s[0]['text'], "Tiny intro.") && tiles($s, $pre2['text']) === '');
    $bad = ['kind' => 'markdown', 'text' => "abc\n\ndef", 'blocks' => [['type' => 'heading', 'level' => 1, 'heading' => 'X', 'char_from' => 5, 'char_to' => 99], ['type' => 'heading', 'level' => 0, 'heading' => 'Y', 'char_from' => 0, 'char_to' => 3]], 'pages' => []];
    $s = kn_section_document($bad, ['title' => 'B']);
    check('adversarial: out-of-range blocks ignored, level 0 lifted to 1', count($s) === 1 && $s[0]['heading'] === 'Y' && $s[0]['level'] === 1 && tiles($s, $bad['text']) === '');
    $pasted = kn_extract_pasted_text("Intro line\n\n## Pasted Heading\n\nText", 'My paste');
    check('pasted: Markdown headings recognised, title kept', $pasted['kind'] === 'markdown' && $pasted['title'] === 'My paste' && $pasted['blocks'][0]['heading'] === 'Pasted Heading');
    check('pasted: plain pasted text is text', kn_extract_pasted_text("just words\n\nmore words")['kind'] === 'text');

    // ==================================================================================================== Engine title and metadata
    group('Engine title and metadata');
    $sec = ['position' => 4, 'heading_path' => 'Handbook › Leave › Carry-over', 'page_no' => 3];
    check('engine title: the heading path', kn_section_title_for_engine($sec, 'Handbook') === 'Handbook › Leave › Carry-over');
    check('engine title: the source title when there is no path', kn_section_title_for_engine(['heading_path' => ''], 'Source Title') === 'Source Title');
    $long = ['heading_path' => implode(' › ', array_fill(0, 40, 'Chapter heading words'))];
    $et = kn_section_title_for_engine($long, 'X');
    check('engine title: truncated to ≤ 200 characters at a word boundary', mb_strlen($et) <= 200 && str_ends_with($et, '…') && preg_match('/(words|Chapter|heading)…$/u', $et) === 1, $et);
    check('engine metadata: base, source, section, page, heading_path, position', kn_section_metadata(7, 42, 901, $sec) === ['base' => 7, 'source' => 42, 'section' => 901, 'page' => 3, 'heading_path' => 'Handbook › Leave › Carry-over', 'position' => 4]);
    check('engine metadata: page null when the section has none', kn_section_metadata(1, 2, 3, ['position' => 1, 'heading_path' => 'A', 'page_no' => null])['page'] === null);

    // ==================================================================================================== Detection with lying names
    group('Kind detection from the bytes');
    $lies = [
        ['deck.pptx', 'slides.xlsx', 'pptx'],
        ['leave.xlsx', 'sheet.docx', 'xlsx'],
        ['handbook.docx', 'handbook.pdf', 'docx'],
    ];
    foreach ($lies as [$src, $name, $want]) {
        $copy = fx_dir() . '/lie-' . $name;
        copy(fx_dir() . '/' . $src, $copy);
        check("detect: $want named $name", kn_detect_kind($copy, $name)['kind'] === $want);
    }
    check('detect: a .md name does not make CSV bytes Markdown', kn_detect_kind(fx_write('t1.md', "a,b,c\n1,2,3\n4,5,6\n7,8,9\n"), 't1.md')['kind'] === 'csv');
    check('detect: a .csv name on prose without delimiters is text', kn_detect_kind(fx_write('t2.csv', "Just a sentence\nand another one\n"), 't2.csv')['kind'] === 'text');
    check('detect: HTML fragment with a script is HTML (and so stripped)', kn_detect_kind(fx_write('t3.txt', '<div><p>Hi</p><script>x()</script></div>'), 't3.txt')['kind'] === 'html');
    check('detect: UTF-16 text is text', kn_detect_kind(fx_write('t4.txt', "\xFF\xFE" . mb_convert_encoding("hello there\nsecond line", 'UTF-16LE', 'UTF-8')), 't4.txt')['kind'] === 'text');
} catch (Throwable $e) {
    check('no exception or warning', false, get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    fx_cleanup();
}

echo "\n" . $GLOBALS['pass'] . ' passed, ' . $GLOBALS['fail'] . " failed\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);
