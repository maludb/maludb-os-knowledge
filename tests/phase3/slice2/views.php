<?php
/** Slice 2 proof — the screens (sources-sections.md "Proof", bullet 5). Runs after upload, worker, paste, url. */
require __DIR__ . '/lib.php';

echo "5. Views\n";
$bid = world()['base'];
$priya = as_member(PRIYA);
$reid = as_member(REID);
$list = page($priya, '/bases/' . $bid . '/sources');
$docx = (int) one("SELECT id FROM sources WHERE base_id = :b AND origin = 'Staff_Handbook.docx'", ['b' => $bid]);
$broken = (int) one("SELECT id FROM sources WHERE base_id = :b AND origin = 'Broken.pdf'", ['b' => $bid]);
ok($list['code'] === 200 && str_contains($list['body'], 'id="source-card-' . $docx . '"') && str_contains($list['body'], 'id="sources-add-btn"') && str_contains($list['body'], 'id="base-tab-sources"'),
    'the sources: cards, Add documents, and the Sources tab lit on the base');
ok(str_contains($list['body'], 'id="source-card-' . $broken . '-error"') && str_contains($list['body'], 'The PDF is damaged.'), 'a failed card says why, in words');
ok(str_contains($list['body'], 'feather-file-text') && str_contains($list['body'], 'feather-type') && str_contains($list['body'], 'feather-globe'), 'each kind its icon (file, text, address)');
$f = page($priya, '/bases/' . $bid . '/sources?kind=url')['body'];
ok(!str_contains($f, 'id="source-card-' . $docx . '"') && str_contains($f, 'feather-globe'), 'the kind filter');
$f = page($priya, '/bases/' . $bid . '/sources?status=failed')['body'];
ok(str_contains($f, 'id="source-card-' . $broken . '"') && !str_contains($f, 'id="source-card-' . $docx . '"'), 'the status filter');
$f = page($priya, '/bases/' . $bid . '/sources?q=Handbook')['body'];
ok(str_contains($f, 'id="source-card-' . $docx . '"') && !str_contains($f, 'id="source-card-' . $broken . '"'), 'the search');
$r = page($priya, '/bases/' . $bid . '/sources-status');
ok($r['code'] === 286 && !str_contains($r['body'], 'hx-trigger'), 'the polling partial answers 286 when nothing is pending, without the polling attributes');
[, $rb] = act($priya, '/sources/add.php', ['base' => $bid, 'kind' => 'text', 'title' => 'SMOKE Pending', 'text' => "# Pending\n\nWords."]);
$r = page($priya, '/bases/' . $bid . '/sources-status');
ok($r['code'] === 200 && str_contains($r['body'], 'hx-trigger="every 5s') && str_contains($r['body'], 'id="sources-pending"'), '…and 200 with them while a source waits');
ok(str_contains(page($priya, '/bases/' . $bid . '/sources')['body'], 'every 5s [document.visibilityState'), 'the list polls only while the page is visible');
worker();
ok(!str_contains(page($reid, '/bases/' . $bid . '/sources')['body'], 'id="sources-add-btn"'), 'a Reader sees the cards and no add button');
// source-view
$v = page($priya, '/sources/' . $docx);
ok($v['code'] === 200 && str_contains($v['body'], 'id="source-outline"') && preg_match('#<ul class="kn-outline">#', $v['body']) === 1, 'the outline, nested');
$carry = (int) one("SELECT id FROM sections WHERE source_id = :s AND heading = 'Carry-over'", ['s' => $docx]);
ok(str_contains($v['body'], 'id="outline-node-' . $carry . '"') && str_contains($v['body'], 'href="/sections/' . $carry . '"'), 'each heading links to its section');
ok(str_contains($v['body'], 'id="source-download"') && str_contains($v['body'], 'id="source-action-replace"') && str_contains($v['body'], 'id="source-action-remove"'), 'Download, and the curator\'s Replace and Remove');
ok(str_contains($v['body'], 'not built yet'), 'a curator sees the sections\' status chips');
$vr = page($reid, '/sources/' . $docx)['body'];
ok(str_contains($vr, 'id="source-outline"') && !str_contains($vr, 'not built yet') && !str_contains($vr, 'id="source-action-remove"'), 'Reid reads it: no status chips, no curator buttons');
$pdf = (int) one("SELECT id FROM sources WHERE base_id = :b AND origin = 'Safety_Manual.pdf'", ['b' => $bid]);
$pv = page($priya, '/sources/' . $pdf)['body'];
$first3 = (int) one('SELECT id FROM sections WHERE source_id = :s AND (page_no = 3 OR (char_from < (SELECT char_to FROM source_pages WHERE source_id = :s AND page_no = 3) AND char_to > (SELECT char_from FROM source_pages WHERE source_id = :s AND page_no = 3))) ORDER BY position LIMIT 1', ['s' => $pdf]);
ok(str_contains($pv, 'id="source-pages"') && preg_match('#href="/sections/' . $first3 . '"[^>]*id="source-page-3"|id="source-page-3"[^>]*href="/sections/' . $first3 . '"#', $pv) === 1, 'the pages, each linking to its first section (p. 3 → ' . $first3 . ')');
ok(str_contains(page($priya, '/sources/' . $broken)['body'], 'The PDF is damaged.'), 'the error in words');
// section-view
$secs = sections_of($docx);
$mid = (int) $secs[1]['id'];
$sv = page($priya, '/sections/' . $mid);
ok($sv['code'] === 200 && str_contains($sv['body'], 'id="section-prev"') && str_contains($sv['body'], 'href="/sections/' . (int) $secs[0]['id'] . '"') && str_contains($sv['body'], 'id="section-next"'), 'previous and next across the source');
ok(str_contains($sv['body'], 'href="/sources/' . $docx . '"') && str_contains($sv['body'], 'href="/sections/' . (int) $secs[0]['id'] . '"'), 'the breadcrumb: the source, then the ancestors');
$text = (string) one('SELECT text FROM sections WHERE id = :x', ['x' => $mid]);
$words = implode(' ', array_slice(preg_split('/\s+/', $text), 3, 6));
$m = page($priya, '/sections/' . $mid . '?quote=' . rawurlencode($words))['body'];
ok(str_contains($m, '<mark id="quote">' . htmlspecialchars($words, ENT_QUOTES) . '</mark>'), '?quote= marks the exact words');
$spaced = str_replace(' ', "  \n ", $words);
$m = page($priya, '/sections/' . $mid . '?quote=' . rawurlencode($spaced))['body'];
ok(str_contains($m, '<mark id="quote">') && !str_contains($m, 'id="quote-missing"'), 'a quote with its whitespace changed is still marked (collapsed)');
$m = page($priya, '/sections/' . $mid . '?quote=' . rawurlencode('words found nowhere at all'))['body'];
ok(!str_contains($m, '<mark') && str_contains($m, 'The quoted words are not in this section.'), 'an absent quote is said');
$pdfSec = (int) one('SELECT id FROM sections WHERE source_id = :s AND page_no IS NOT NULL ORDER BY position LIMIT 1', ['s' => $pdf]);
ok(str_contains(page($priya, '/sections/' . $pdfSec)['body'], 'id="section-page"'), 'the page chip');
ok(page($reid, '/sections/' . $mid)['code'] === 200, 'Reid reads a section');
$ann = as_member(ANN);
ok(page($ann, '/sources/' . $docx)['code'] === 200 && page($ann, '/sections/' . $mid)['code'] === 200, 'Ann (a guest named on the base) reads the source and the section');
$bea = as_member(BEA);
ok(page($bea, '/sources/' . $docx)['code'] === 404 && page($bea, '/sections/' . $mid)['code'] === 404, 'Bea → 404 on a source and a section');
ok(str_contains(page($priya, '/sections/' . $mid)['body'], 'id="section-status"') && !str_contains(page($reid, '/sections/' . $mid)['body'], 'id="section-status"'), 'a curator sees the section\'s status, a Reader not');
// the admin's look at the private base
$since = last_activity_id();
page(as_member(AVERY), '/sources/' . $docx);
page(as_member(AVERY), '/sections/' . $mid);
$av = activity('base.admin_view', $since);
ok(count($av) === 2 && (int) $av[0]['base_id'] === $bid, 'Avery\'s look at the private base logs base.admin_view on each screen');
// the file door
$att = (int) one('SELECT attachment_id FROM sources WHERE id = :s', ['s' => $docx]);
ok(page($priya, '/files/' . $att)['code'] === 200 && page($reid, '/files/' . $att)['code'] === 200 && page($bea, '/files/' . $att)['code'] === 404, '/files/{id} serves Priya and Reid, 404s Bea');
$sv2 = activity('screen.view', $since);
$sec = array_values(array_filter($sv2, static fn ($r) => $r['screen'] === 'section-view'));
ok($sec !== [] && (int) json_decode($sec[0]['after'], true)['section_id'] === $mid && (int) $sec[0]['base_id'] === $bid, 'section-view logs screen.view with after.section_id and base_id');
ok(!plant_in_log(), 'the planted phrase is in no activity_log row — the quotes asked for included');
finish();
