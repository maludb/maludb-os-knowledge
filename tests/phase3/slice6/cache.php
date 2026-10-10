<?php
/** Slice 6 proof — the cache (graph-explorer.md "Proof", Cache). */
require __DIR__ . '/lib.php';

echo "7. Cache\n";
$w = graph_world();
$p = $w['pumps'];
g_set($w);
g_cache_clear($p);
g_built_reset($p);
$docNb = static fn (): int => count(array_filter(kb_log('/v1/graph/neighbors'), static fn ($r) => ($r['query']['kind'] ?? '') === 'document'));
$pages = ["/bases/$p/graph", "/bases/$p/graph?q=Zorblat", "/bases/$p/subject?name=" . rawurlencode('Zorblat Pump'), "/bases/$p/path?from=" . rawurlencode('Zorblat Pump') . '&to=' . rawurlencode('Merrow Filter')];
kb_clear();
foreach ($pages as $u) { gscreen(PRIYA, $u); }
$first = kb_calls();
ok($first > 0, "the first open of the four screens asks the engine ($first calls)");
kb_clear();
$firstData = array_map(static fn ($u) => gscreen(PRIYA, $u)[1], $pages);
ok(kb_calls() === 0, 'a second open makes no engine call (the fake\'s log is empty)');
ok(count(g_cache_files($p)) >= 6, 'the answers are files under storage/cache/graph/' . $p . ' (' . count(g_cache_files($p)) . ')');
$dir = APP_ROOT . '/storage/cache/graph/' . $p;
$files = g_cache_files($p);
ok(substr(sprintf('%o', fileperms($dir)), -4) === '0750' && substr(sprintf('%o', fileperms($files[0])), -4) === '0640' && preg_match('/^[0-9a-f]{40}\.json$/', basename($files[0])) === 1, 'directory 0750, files 0640, named by the sha1 of the key');
ok(array_filter(glob($dir . '/.tmp-*') ?: []) === [], 'no half-written file is left behind (written by rename)');
$cached = file_get_contents($files[0]);
ok(!str_contains($cached, 'Quillon') && !str_contains(implode('', array_map('file_get_contents', $files)), 'Quillon'), 'the cache holds nothing of the other base');

// another reader of the same base sees the same, from the same files
kb_clear();
[, $m] = gscreen(MARCO, "/bases/$p/subject?name=" . rawurlencode('Zorblat Pump'));
ok(array_column($m['sections'], 'section_id') === array_column($firstData[2]['sections'], 'section_id') && $m['subject'] === $firstData[2]['subject'], 'a second Reader sees the same subject (the cache is the base\'s, read after the gate)');

// a build moves the key
g_build_moved($p);
kb_clear();
gscreen(PRIYA, "/bases/$p/graph");
ok(kb_calls() > 0, 'a build (last_built_at moved) invalidates it');
kb_clear();
gscreen(PRIYA, "/bases/$p/graph");
ok(kb_calls() === 0, '…and the new answers are cached again');
pdo()->exec("UPDATE knowledge_bases SET guideline_version = guideline_version + 1 WHERE id = $p");
kb_clear();
gscreen(PRIYA, "/bases/$p/graph");
ok(kb_calls() > 0, 'a new guideline version moves the key too');
pdo()->exec("UPDATE knowledge_bases SET guideline_version = guideline_version - 1 WHERE id = $p");

// fresh
kb_clear();
gscreen(MARCO, "/bases/$p/graph?fresh=1");
ok(kb_calls() > 0, '?fresh=1 for a curator bypasses it');
kb_clear();
gscreen(PRIYA, "/bases/$p/graph?fresh=1");
ok(kb_calls() === 0, '…and for a Reader is ignored');

// TTL: 60 s for an engine read, 600 s for duplicate_subjects
foreach (g_cache_files($p) as $f) { touch($f, time() - 61); }
kb_clear();
gscreen(PRIYA, "/bases/$p/graph");
ok(kb_calls() > 0, 'a file older than 60 s is read again');
g_cache_clear($p);
gscreen(MARCO, "/bases/$p/graph");
foreach (g_cache_files($p) as $f) { touch($f, time() - 300); }
kb_clear();
gscreen(MARCO, "/bases/$p/graph");
ok(kb_calls() > 0 && $docNb() === 0, 'at 300 s the engine reads are fetched again but duplicate_subjects (600 s) is still the cached one');
foreach (g_cache_files($p) as $f) { touch($f, time() - 601); }
kb_clear();
gscreen(MARCO, "/bases/$p/graph");
ok($docNb() > 0, 'past 600 s duplicate_subjects reads the documents again');

// an engine failure is not cached
g_cache_clear($p);
g_set($w, ['fail' => true]);
[, $f1] = gscreen(PRIYA, "/bases/$p/graph?q=Zorblat");
$failFiles = count(g_cache_files($p));
ok(count($f1['errors']) >= 4 && $failFiles === 0, 'with the engine failing every region says so and no file is written (' . count($f1['errors']) . ' regions, ' . $failFiles . ' files)');
g_set($w);
kb_clear();
[, $f2] = gscreen(PRIYA, "/bases/$p/graph?q=Zorblat");
ok(kb_calls() > 0 && empty($f2['errors']) && $f2['size']['documents'] === 4, 'the next open calls the engine again and shows the data, not the failure');

// the old files go on the next write
g_cache_clear($p);
gscreen(PRIYA, "/bases/$p/graph");
$old = APP_ROOT . '/storage/cache/graph/' . $p . '/' . str_repeat('a', 40) . '.json';
file_put_contents($old, '{"ok":true}');
touch($old, time() - 2 * 86400);
gscreen(PRIYA, "/bases/$p/graph?q=" . rawurlencode('Merrow'));
ok(!file_exists($old) && count(g_cache_files($p)) >= 1, 'a file older than a day is removed by the next write');
// the directory does not grow without end
g_cache_clear($p);
for ($i = 0; $i < 6; $i++) { gscreen(PRIYA, "/bases/$p/graph?q=" . rawurlencode('Zorblat word' . $i)); }
ok(count(g_cache_files($p)) <= 400, 'arbitrary search words cannot grow the directory past its cap');
g_cache_clear($p);
g_built_reset($p);
finish();
