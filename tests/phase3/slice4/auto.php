<?php
/** Slice 4 proof — automatic builds (builds.md "Proof", bullet 8). */
require __DIR__ . '/lib.php';

echo "16. Automatic builds\n";
world4();
$ab = curated_base('SMOKE Auto', 'smoke-auto');
$fill = str_repeat(' Keep the unit clean and dry.', 8);                 // at least 200 characters a section: smaller ones are merged
add_text($ab, 'SMOKE Auto First', "# First\n\nThe Zorblat Pump hums.$fill\n\n# Second\n\nThe Merrow Filter traps dust.$fill");
worker();
[, $b] = start(PRIYA, $ab);
run_build((int) $b['record_id']);
act(as_member(PRIYA), '/guidelines/save.php', ['base' => $ab, 'kind' => 'extraction', 'title' => 'SMOKE Auto Rule', 'body' => 'Extract the parts.']);
$stale = array_column(sections_by($ab, 'stale'), 'id');
ok(count($stale) === 2, 'two sections stale after a guideline save');
pdo()->exec("UPDATE knowledge_bases SET auto_extract = true WHERE id = $ab");
$since = last_activity_id();
add_text($ab, 'SMOKE Auto New', "# New\n\nThe Quillon Valve controls the inlet.");
worker();
$auto = (int) one('SELECT id FROM builds WHERE base_id = :b AND started_by IS NULL ORDER BY id DESC LIMIT 1', ['b' => $ab]);
$new = array_column(q("SELECT x.id FROM sections x JOIN sources s ON s.id = x.source_id WHERE s.title = 'SMOKE Auto New'"), 'id');
$items = array_column(q('SELECT section_id FROM build_items WHERE build_id = :b', ['b' => $auto]), 'section_id');
ok($auto > 0 && $items == $new, 'auto-extract on: the next pass reads the new source and queues a build of its queued sections only');
ok(array_column(sections_by($ab, 'stale'), 'id') == $stale, 'the stale sections untouched (a rebuild is a person\'s)');
$st = activity('build.start', $since);
ok(count($st) === 1 && $st[0]['source'] === 'cron' && json_decode($st[0]['after'], true)['auto'] === true && (int) $st[0]['base_id'] === $ab, 'build.start logged by cron with auto true');
run_build($auto);
ok(build_row($auto)['status'] === 'done' && sections_by($ab, 'queued') === [], 'the automatic build finishes');
ok(str_contains(page(as_member(PRIYA), "/bases/$ab/builds")['body'], '>automatic<'), 'the builds table marks it automatic');
pdo()->exec("UPDATE knowledge_bases SET auto_extract = false WHERE id = $ab");
add_text($ab, 'SMOKE Auto Off', "# Off\n\nThe Spare Kit holds two seals.");
worker();
ok(live_build($ab) === null && count(sections_by($ab, 'queued')) === 1, 'auto-extract off: the new section waits, no build queued');

echo "   The engine down: no automatic build\n";
pdo()->exec("UPDATE knowledge_bases SET auto_extract = true WHERE id = $ab");
engine('restart-api');                                   // stops it
worker();
ok(live_build($ab) === null, 'with the engine down no automatic build is queued');
engine('restart-api');                                   // starts it
worker();
$again = live_build($ab) ?? (int) one('SELECT max(id) FROM builds WHERE base_id = :b', ['b' => $ab]);
ok(build_row($again)['started_by'] === null && sections_by($ab, 'queued') === [], 'back up: the next pass builds it');
pdo()->exec("UPDATE knowledge_bases SET auto_extract = false WHERE id = $ab");

echo "   A big build never starves a small base\n";
$big = curated_base('SMOKE Big', 'smoke-big');
add_text($big, 'SMOKE Big Manual', implode("\n\n", array_map(static fn ($i) => "# Item $i\n\nPart $i of the catalogue fits the pump." . str_repeat(' Store it dry.', 15), range(1, 300))));
worker();
$bigN = count(sections_by($big, 'queued'));
[, $b] = start(PRIYA, $big);
$bigId = (int) $b['record_id'];
$small = curated_base('SMOKE Small', 'smoke-small');
pdo()->exec("UPDATE knowledge_bases SET auto_extract = true WHERE id = $small");
add_text($small, 'SMOKE Small Note', "# Note\n\nThe Merrow Filter is cleaned weekly.");
worker();
$smallId = (int) one('SELECT max(id) FROM builds WHERE base_id = :b', ['b' => $small]);
ok($bigN >= 300 && (int) build_row($bigId)['items_done'] === 10, "the big base's build ($bigN sections) did 10 items that pass");
ok($smallId > 0 && build_row($smallId)['status'] === 'done', 'and the small base\'s new source was built in the same pass');
cancel_live($big);
pdo()->exec("UPDATE knowledge_bases SET auto_extract = false WHERE id = $small");
finish();
