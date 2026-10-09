<?php
/** Slice 3 proof — Preview on a section (guidelines-areas.md "Proof", bullet 3). */
require __DIR__ . '/lib.php';

echo "3. Preview\n";
$w = world();
$bid = $w['base'];
$priya = as_member(PRIYA);
$sec = $w['sections'][0];
$text = (string) one('SELECT text FROM sections WHERE id = :x', ['x' => $sec]);
$since = last_activity_id();
$guidelinesBefore = (int) one('SELECT count(*) FROM guideline_versions');
$hx = ['HX-Request: true'];
$draft = 'Extract every DRAFT-ONLY-MARKER widget and its owner.';
$tok = csrf_of(page($priya, '/')['body']);
$r = req('POST', '/guidelines/preview.php', ['jar' => $priya, 'headers' => $hx, 'form' => ['csrf_token' => $tok, 'base' => $bid, 'kind' => 'extraction', 'body' => $draft, 'section' => $sec]]);
ok($r['code'] === 200 && str_contains($r['body'], 'EXTRACT: Extract every DRAFT-ONLY-MARKER widget and its owner.') && str_contains(html_entity_decode($r['body']), "Text:\n" . $text),
    'the unsaved draft is shown inside the prompt, the chosen section\'s text after Text:');
ok(str_contains($r['body'], 'this draft changes what the engine is told') && str_contains($r['body'], 'a dry run of the model comes with MaluDB M5'), '"this draft changes what the engine is told", and the note on M5');
ok(!str_contains($r['body'], '<html'), 'over HTMX: the partial for #preview, not a page');
$g = guideline_of($bid, 'extraction');
$r = req('POST', '/guidelines/preview.php', ['jar' => $priya, 'headers' => $hx, 'form' => ['csrf_token' => $tok, 'guideline' => $g['id'], 'kind' => 'extraction', 'body' => $g['body'], 'is_active' => $g['is_active'] ? 'yes' : 'no', 'section' => $sec]]);
ok($r['code'] === 200 && str_contains($r['body'], '>no change<'), 'an unchanged draft → "no change"');
$long = implode(' ', array_map(static fn ($i) => "Extract the thing number $i with every one of its many parts.", range(1, 160)));
$r = req('POST', '/guidelines/preview.php', ['jar' => $priya, 'headers' => $hx, 'form' => ['csrf_token' => $tok, 'base' => $bid, 'kind' => 'extraction', 'body' => $long, 'section' => $sec]]);
ok(str_contains($r['body'], 'EXTRACT lines did not fit — shorten the extract guidelines'), 'a draft over the cap → the truncation warning');
$r = req('POST', '/guidelines/preview.php', ['jar' => $priya, 'headers' => $hx, 'form' => ['csrf_token' => $tok, 'base' => $bid, 'kind' => 'answering', 'body' => 'Answer DRAFT-ANSWER-MARKER first.', 'section' => $sec]]);
ok(str_contains($r['body'], 'id="preview-system"') && str_contains($r['body'], 'HOW TO ANSWER') && str_contains($r['body'], 'Answer DRAFT-ANSWER-MARKER first.'), 'an answering draft → the answer guidelines with it');
$other = make_base(PRIYA, 'SMOKE Other Preview Base');
$otherSrc = mksource($other);
$otherSec = mksection($otherSrc);
$r = req('POST', '/guidelines/preview.php', ['jar' => $priya, 'headers' => $hx, 'form' => ['csrf_token' => $tok, 'base' => $bid, 'kind' => 'extraction', 'body' => $draft, 'section' => $otherSec]]);
ok($r['code'] === 404, 'a section of another base → 404');
$r = req('POST', '/guidelines/preview.php', ['jar' => $priya, 'headers' => $hx, 'form' => ['csrf_token' => $tok, 'base' => $bid, 'kind' => 'extraction', 'body' => $draft, 'section' => $sec]]);
ok(str_contains($r['body'], 'id="preview-engine"'), 'the engine\'s generic prompt is in the <details> while the engine is up');
$logged = (int) one("SELECT count(*) FROM activity_log WHERE route LIKE '%/guidelines/preview.php'");
$versions = (int) one('SELECT count(*) FROM guideline_versions');
ok($logged === 0 && $versions === $guidelinesBefore + 4 && !str_contains((string) one("SELECT string_agg(body, ' ') FROM guidelines"), 'DRAFT-ONLY-MARKER'),
    "nothing written and nothing logged ($logged rows from Preview; the guideline versions grew only by the other base's four template rows)");
// without JavaScript: the whole form again, the preview filled
$r = req('POST', '/guidelines/preview.php', ['jar' => $priya, 'form' => ['csrf_token' => $tok, 'base' => $bid, 'kind' => 'extraction', 'body' => $draft, 'section' => $sec]]);
ok($r['code'] === 200 && str_contains($r['body'], '<html') && str_contains($r['body'], 'id="guideline-form"') && str_contains($r['body'], 'DRAFT-ONLY-MARKER') && str_contains($r['body'], 'id="preview-prompt"'), 'without JavaScript: a full page, the form kept and the preview filled');
$empty = make_base(PRIYA, 'SMOKE Empty Preview Base');
$r = req('POST', '/guidelines/preview.php', ['jar' => $priya, 'headers' => $hx, 'form' => ['csrf_token' => $tok, 'base' => $empty, 'kind' => 'extraction', 'body' => $draft]]);
ok(str_contains($r['body'], '(no section yet — add documents)'), 'a base with no section previews with "(no section yet — add documents)"');
$r = req('POST', '/guidelines/preview.php', ['jar' => as_member(REID), 'headers' => $hx, 'form' => ['csrf_token' => csrf_of(page(as_member(REID), '/')['body']), 'base' => $bid, 'kind' => 'extraction', 'body' => $draft, 'section' => $sec]]);
ok($r['code'] === 403, 'a reader may not preview');
engine('restart-api');                                   // stops the API
$r = req('POST', '/guidelines/preview.php', ['jar' => $priya, 'headers' => $hx, 'form' => ['csrf_token' => $tok, 'base' => $bid, 'kind' => 'extraction', 'body' => $draft, 'section' => $sec]]);
ok($r['code'] === 200 && str_contains($r['body'], 'DRAFT-ONLY-MARKER') && !str_contains($r['body'], 'id="preview-engine"'), 'the engine down: Preview still works, the engine\'s generic prompt simply absent');
engine('restart-api');                                   // starts it again
finish();
