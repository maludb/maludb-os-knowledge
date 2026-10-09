<?php
/** Slice 2 proof — upload (sources-sections.md "Proof", bullet 1). */
require __DIR__ . '/lib.php';

echo "1. Upload\n";
$w = world();
$bid = $w['base'];
$priya = as_member(PRIYA);
$f = fixtures();
$since = last_activity_id();
[$c, $b] = upload($priya, '/sources/add.php', ['base' => $bid, 'kind' => 'file'], [
    'Staff_Handbook.docx' => $f['Staff_Handbook.docx'], 'Safety_Manual.pdf' => $f['Safety_Manual.pdf'], 'Quarterly_Review.pptx' => $f['Quarterly_Review.pptx'],
    'Rota.xlsx' => $f['Rota.xlsx'], 'Notes.md' => $f['Notes.md'], 'Claims.csv' => $f['Claims.csv'], 'Scan.pdf' => $f['Scan.pdf'],
    'setup.pdf' => $f['setup.pdf'], 'logo.svg' => $f['logo.svg']]);
ok($c === 200 && ($b['did'] ?? '') === '7 added, 2 refused', 'nine files in one request: "' . ($b['did'] ?? json_encode($b)) . '" (200)');
$res = array_column($b['results'] ?? [], null, 'name');
ok(($res['setup.pdf']['ok'] ?? true) === false && ($res['logo.svg']['ok'] ?? true) === false && str_contains((string) ($res['setup.pdf']['sentence'] ?? ''), 'setup.pdf'), 'the executable named .pdf and the SVG are refused, each in its own sentence: ' . ($res['setup.pdf']['sentence'] ?? '') . ' / ' . ($res['logo.svg']['sentence'] ?? ''));
ok(count($b['records'] ?? []) === 7 && (int) $b['record_id'] === (int) $b['records'][0], 'record_id is the first, records every id');
$rows = q("SELECT s.id, s.status, s.kind, s.title, a.storage_path FROM sources s JOIN attachments a ON a.id = s.attachment_id WHERE s.base_id = :b ORDER BY s.id", ['b' => $bid]);
ok(count($rows) === 7 && array_unique(array_column($rows, 'status')) === ['uploaded'], 'seven sources uploaded, each with its attachment');
$under = array_filter($rows, static fn ($r) => str_starts_with($r['storage_path'], 'attachments/source-' . $r['id'] . '/') && is_file(APP_DIR_STORAGE . '/' . $r['storage_path']));
ok(count($under) === 7, 'the bytes are stored under storage/attachments/source-{id}/');
$adds = activity('source.add', $since);
$a0 = json_decode($adds[0]['after'] ?? '{}', true);
ok(count($adds) === 7 && $a0['kind'] === 'file' && isset($a0['bytes'], $a0['title'], $a0['mime_type']) && !isset($a0['text']) && (int) $adds[0]['base_id'] === $bid && $adds[0]['entity_type'] === 'source',
    'source.add per source: kind, title, bytes and mime type, never text, base_id set');
// the size limit (a setting), the same bytes again, every file refused
pdo()->exec('UPDATE kn_settings SET max_source_bytes = 2097152');
$big = fx_write('Large.txt', str_repeat(PLANT . " filler words for the size limit\n", 90000));
[$c, $b] = upload($priya, '/sources/add.php', ['base' => $bid, 'kind' => 'file'], ['Large.txt' => $big, 'Staff_Handbook.docx' => $f['Staff_Handbook.docx']]);
$msgs = implode(' | ', $b['error']['errors'] ?? []);
ok($c === 422 && str_contains($msgs, 'Over 2.0 MB: Large.txt') && str_contains($msgs, 'Staff_Handbook.docx is already here as "Staff_Handbook.docx"'),
    'a file over a 2 MB max_source_bytes → its sentence; the same DOCX again → "already here as …"; every file refused → 422: ' . $msgs);
ok(isset($b['error']['fields']['files']), '…with fields.files');
pdo()->exec('UPDATE kn_settings SET max_source_bytes = 52428800');
[$c, $b] = upload(as_member(REID), '/sources/add.php', ['base' => $bid, 'kind' => 'file'], ['Notes.md' => $f['Notes.md']]);
ok($c === 403, 'Reid (a reader of the base) → 403 (' . msg($b) . ')');
[$c, $b] = upload(as_member(BEA), '/sources/add.php', ['base' => $bid, 'kind' => 'file'], ['Notes.md' => $f['Notes.md']]);
ok($c === 404, 'Bea (no share) → 404');
$arch = make_base(PRIYA, 'SMOKE Archived Intake');
act($priya, '/bases/archive.php', ['base' => $arch]);
[$c, $b] = act($priya, '/sources/add.php', ['base' => $arch, 'kind' => 'text', 'title' => 'SMOKE late', 'text' => 'Words.']);
ok($c === 422 && (fields($b)['base'] ?? '') === 'An archived base takes no sources.', 'an archived base → the trigger\'s words (' . json_encode(fields($b)) . ')');
[$c, $b] = upload(as_member(MARCO), '/sources/add.php', ['base' => $bid, 'kind' => 'file'], ['Broken.pdf' => $f['Broken.pdf']]);
ok($c === 200 && ($b['did'] ?? '') === 'Added Broken.pdf', 'Marco (curate share + Curator role) adds a broken PDF (the worker judges it)');
[$c, $b] = act($priya, '/sources/add.php', ['base' => $bid, 'kind' => 'file']);
ok($c === 422 && isset(fields($b)['files']), 'no file at all → the files field');
// a request over post_max_size arrives empty (its CSRF token too): a body PHP does not parse stands in for one
$r = req('POST', '/sources/add.php', ['jar' => $priya, 'headers' => ['Content-Type: application/octet-stream', 'Accept: application/json'], 'raw' => str_repeat('x', 4096)]);
ok($r['code'] === 422 && str_contains($r['body'], 'The upload was larger than the server accepts (100 MB a request).'), 'a POST that arrives empty is told the upload was too large, before the CSRF check refuses it (' . $r['code'] . ')');
// the no-JavaScript form: one POST of several files, the result lines shown on the form after a redirect
$tok = csrf_of(req('GET', '/', ['jar' => $priya])['body']);
$ch = curl_init(BASE . '/sources/add.php');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEFILE => $priya, CURLOPT_COOKIEJAR => $priya,
    CURLOPT_POSTFIELDS => ['csrf_token' => $tok, 'base' => $bid, 'kind' => 'file', 'files[0]' => new CURLFile($f['logo.svg'], '', 'logo.svg')]]);
$raw = (string) curl_exec($ch);
ok((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 303 && str_contains($raw, 'Location: /sources/new?base=' . $bid . '&tab=files&done=1'), 'a browser\'s plain POST is answered with a redirect to the form');
$page = page($priya, '/sources/new?base=' . $bid . '&tab=files&done=1')['body'];
ok(str_contains($page, 'id="upload-result-1"') && str_contains($page, 'logo.svg') && str_contains($page, 'refused:'), '…which shows the result lines once');
ok(!str_contains(page($priya, '/sources/new?base=' . $bid . '&tab=files&done=1')['body'], 'id="upload-result-1"'), '…and only once');
ok(!plant_in_log(), 'the planted phrase is in no activity_log row');
finish();
