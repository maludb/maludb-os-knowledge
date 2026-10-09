<?php
/** Slice 2 proof — the worker reads and cuts (sources-sections.md "Proof", bullet 2). Runs after upload.php. */
require __DIR__ . '/lib.php';

echo "2. The worker\n";
$bid = world()['base'];
$since = last_activity_id();
$notesBefore = count(notifications_of(PRIYA, 'source_failed'));
$line = worker();
ok(str_contains($line, 'sources 8'), 'one pass reads and cuts the eight uploaded sources: ' . $line);
$id = static fn (string $origin): int => (int) one('SELECT id FROM sources WHERE base_id = :b AND origin = :o ORDER BY id DESC LIMIT 1', ['b' => $bid, 'o' => $origin]);
$ready = ['Staff_Handbook.docx', 'Safety_Manual.pdf', 'Quarterly_Review.pptx', 'Rota.xlsx', 'Notes.md', 'Claims.csv'];
$allReady = true;
foreach ($ready as $o) {
    $s = source_of($id($o));
    $hasText = (int) one('SELECT count(*) FROM source_text WHERE source_id = :s', ['s' => $s['id']]) === 1;
    if ($s['status'] !== 'ready' || !$hasText || (int) $s['sections_count'] < 1) { $allReady = false; echo "     $o: {$s['status']} {$s['error']}\n"; }
}
ok($allReady, 'each of the six documents is ready with its text and its sections');
$pages = static fn (string $o): int => (int) one('SELECT count(*) FROM source_pages WHERE source_id = :s', ['s' => $id($o)]);
ok($pages('Safety_Manual.pdf') === 5 && $pages('Quarterly_Review.pptx') === 3 && $pages('Rota.xlsx') === 2 && $pages('Staff_Handbook.docx') === 0,
    'page anchors: the PDF 5, the PPTX one per slide (3), the XLSX one per sheet (2), none for the DOCX (' . implode(', ', array_map($pages, ['Safety_Manual.pdf', 'Quarterly_Review.pptx', 'Rota.xlsx', 'Staff_Handbook.docx'])) . ')');
$tiles = true;
foreach ($ready as $o) {
    $sid = $id($o);
    $len = (int) one('SELECT length(text) FROM source_text WHERE source_id = :s', ['s' => $sid]);
    $secs = sections_of($sid);
    $at = 0;
    foreach ($secs as $x) { if ((int) $x['char_from'] !== $at) { $tiles = false; } $at = (int) $x['char_to']; }
    if ($at !== $len) { $tiles = false; echo "     $o ends at $at of $len\n"; }
}
ok($tiles, 'the sections tile each text: the first at 0, each where the last ended, the last at its length');
$docx = sections_of($id('Staff_Handbook.docx'));
$paths = array_column($docx, 'heading_path');
$carry = array_values(array_filter($docx, static fn ($x) => str_ends_with($x['heading_path'], '› Leave › Carry-over')));
$leave = array_values(array_filter($docx, static fn ($x) => str_ends_with($x['heading_path'], '› Leave')));
ok($carry !== [] && $leave !== [] && (int) $carry[0]['parent_id'] === (int) $leave[0]['id'], 'the DOCX path … › Leave › Carry-over, its parent the Leave section (parent_index → parent_id): ' . implode(' | ', $paths));
$h = source_of($id('Staff_Handbook.docx'));
ok($h['title'] === 'Staff Handbook' && source_of($id('Safety_Manual.pdf'))['title'] !== 'Safety_Manual.pdf', 'the provisional file names give way to the documents\' titles ("' . $h['title'] . '")');
ok($h['language'] === 'en-GB', 'the language from the DOCX (' . $h['language'] . ')');
$scan = source_of($id('Scan.pdf'));
ok($scan['status'] === 'no_text', 'the image-only PDF is no_text');
$n = notifications_of(PRIYA, 'source_failed');
ok(count($n) === $notesBefore + 1 && str_contains($n[count($n) - 1]['body'], 'No text could be read'), 'Priya is told once, in words');
$broken = source_of($id('Broken.pdf'));
ok($broken['status'] === 'failed' && $broken['error'] === 'pdf_unreadable', 'the broken PDF is failed / pdf_unreadable');
$page = page(as_member(PRIYA), '/sources/' . $broken['id'])['body'];
ok(str_contains($page, 'The PDF is damaged.') && str_contains($page, 'id="source-action-retry"'), 'its page says so in words, with Try again');
ok(count(notifications_of(MARCO, 'source_failed')) === 1, 'Marco, who added it, is told');
$b = base_row_of($bid);
ok($b['status'] === 'empty' && (int) one("SELECT count(*) FROM sections WHERE base_id = :b AND status = 'extracted'", ['b' => $bid]) === 0
    && (int) one("SELECT count(*) FROM sections WHERE base_id = :b AND status = 'queued'", ['b' => $bid]) > 0, 'the base stays empty until a build: its sections queued, none extracted');
$te = activity('source.text_extracted', $since);
$se = activity('source.sectioned', $since);
$a = json_decode($se[0]['after'] ?? '{}', true);
ok(count($te) === 6 && count($se) === 6 && isset($a['sections'], $a['by_kind']) && $te[0]['source'] === 'cron' && $te[0]['actor_member_id'] === null && (int) $te[0]['base_id'] === $bid,
    'source.text_extracted and source.sectioned logged per source, with counts, by cron, with base_id');
ok(count(activity('source.no_text', $since)) === 1 && count(activity('source.fail', $since)) === 1, 'source.no_text and source.fail logged');
$count = (int) one('SELECT count(*) FROM sections');
$line = worker();
ok(str_contains($line, 'sources 0') && (int) one('SELECT count(*) FROM sections') === $count, 'a second pass changes nothing');
// two passes at once: the lock
$p = make_base(PRIYA, 'SMOKE Lock Base');
act(as_member(PRIYA), '/sources/add.php', ['base' => $p, 'kind' => 'text', 'title' => 'SMOKE Lock one', 'text' => "# One\n\n" . PLANT . " words one."]);
act(as_member(PRIYA), '/sources/add.php', ['base' => $p, 'kind' => 'text', 'title' => 'SMOKE Lock two', 'text' => "# Two\n\n" . PLANT . " words two."]);
pdo()->query("SELECT pg_advisory_lock(hashtext('knowledge_worker'))");
$held = worker();
pdo()->query("SELECT pg_advisory_unlock(hashtext('knowledge_worker'))");
ok(str_contains($held, 'another worker pass holds the lock'), 'a pass started while another holds the lock exits at once: ' . $held);
$cmd = 'cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && (php bin/worker.php & php bin/worker.php & wait) 2>&1';
$both = (string) shell_exec($cmd);
$per = q("SELECT s.id, count(x.id) AS n FROM sources s LEFT JOIN sections x ON x.source_id = s.id WHERE s.base_id = :b GROUP BY s.id", ['b' => $p]);
ok(count($per) === 2 && array_unique(array_map(static fn ($r) => (int) $r['n'], $per)) === [1], 'two passes started together: each source sectioned once (' . str_replace("\n", ' / ', trim($both)) . ')');
// a pass that died: recovered
$t = make_base(PRIYA, 'SMOKE Interrupted');
[, $r] = act(as_member(PRIYA), '/sources/add.php', ['base' => $t, 'kind' => 'text', 'title' => 'SMOKE Stuck', 'text' => "# Stuck\n\nWords."]);
$stuck = (int) $r['record_id'];
pdo()->exec("UPDATE sources SET status = 'extracting_text' WHERE id = $stuck");
su_sql("ALTER TABLE sources DISABLE TRIGGER sources_touch; UPDATE sources SET updated_at = now() - interval '16 minutes' WHERE id = $stuck; ALTER TABLE sources ENABLE TRIGGER sources_touch;");
worker();
$s = source_of($stuck);
ok($s['status'] === 'failed' && $s['error'] === 'worker_interrupted', 'a source left in extracting_text 16 minutes ago → failed / worker_interrupted');
[$c, $b] = act(as_member(PRIYA), '/sources/retry.php', ['source' => $stuck]);
ok($c === 200 && source_of($stuck)['status'] === 'uploaded' && count(activity('source.retry')) >= 1, 'Retry puts it back in the queue (source.retry)');
worker();
ok(source_of($stuck)['status'] === 'ready', '…and the next pass reads it');
[$c, $b] = act(as_member(PRIYA), '/sources/retry.php', ['source' => $scan['id']]);
ok($c === 422, 'a no_text source is not retried (' . msg($b) . ')');
ok(!plant_in_log(), 'the planted phrase is in no activity_log row');
finish();
