<?php
/**
 * Slice 4 proof — the worker builds (builds.md "Proof", bullets 2, 3 and 9): prepare, the passes, what lands in the engine, the counts, the
 * notifications, the log; stale sections forgotten then ingested; a guideline saved mid-build; rebuild everything.
 */
require __DIR__ . '/lib.php';

echo "3. The worker builds\n";
$w = world4();
$bid = $w['base'];
$ns = 'kb:' . $bid;
$id = live_build($bid) ?? (int) start(PRIYA, $bid)[1]['record_id'];
$total = (int) build_row($id)['items_total'];
$since = last_activity_id();
$extract0 = (int) (fake('/calls')['extract'] ?? 0);
$state = call_step('prepare', (string) $id);
$r = build_row($id);
$first = explode("\n", (string) $r['log'])[0];
ok($state === 'running' && $r['status'] === 'running' && (int) $r['items_done'] === 0, 'prepare: the build running, no item yet');
ok(preg_match('/^prompt sha256=([0-9a-f]{64}) guideline_version=(\d+) terms=3 areas=1$/', $first, $m) === 1 && $m[1] === kn_compile_prompt(pdo(), $bid)['sha256'] && (int) $m[2] === (int) $r['guideline_version'],
    'the log\'s first line: the prompt\'s hash, the version, 3 terms, 1 area');
ok(kn_compile_engine_sha($ns) === $m[1], 'the namespace is configured with that prompt (the engine\'s hashes the same)');
ok((int) one('SELECT count(*) FROM vocabulary_terms WHERE base_id = :b AND maludb_subject_id IS NULL', ['b' => $bid]) === 0, 'every term\'s subject id written');
ok((int) (fake('/calls')['extract'] ?? 0) === $extract0, 'seeding the vocabulary called no model (the fake provider\'s extract count unchanged)');
ok(one('SELECT maludb_project_id FROM focus_areas WHERE id = :a', ['a' => $w['area']]) !== null, 'the area is linked to its project');
ok(count(activity('vocabulary.sync', $since)) === 1 && json_decode(activity('vocabulary.sync', $since)[0]['after'], true)['seeded'] === 3, 'vocabulary.sync logged (3 seeded)');
worker();
$r = build_row($id);
ok($r['status'] === 'running' && (int) $r['items_done'] === 10, 'pass 1: 10 items done, no more (' . $r['items_done'] . " of $total)");
ok(count(activity('build.progress', $since)) === 1, 'build.progress logged once that pass');
$passes = run_build($id);
$r = build_row($id);
ok($r['status'] === 'done' && (int) $r['items_done'] === $total && $passes === 1, "the next pass finishes it: done, $total of $total");
$secs = sections_by($bid);
ok(count(array_filter($secs, static fn ($s) => $s['status'] === 'extracted' && (int) $s['extracted_version'] === (int) $r['guideline_version'] && $s['maludb_document_id'] !== null)) === count($secs), 'every section extracted at the build\'s version with a document');
$docs = engine_docs($bid);
sort($docs);
$held = array_map('intval', array_column($secs, 'maludb_document_id'));
sort($held);
ok($docs === $held, 'the namespace holds exactly the sections\' documents (' . count($docs) . ')');
$meta = json_decode(as_postgres("SELECT json_agg(json_build_object('id', document_id, 'type', document_type, 'meta', metadata_jsonb)) FROM maludb_document", 'engine'), true) ?: [];
$byDoc = array_column($meta, null, 'id');
$good = 0;
foreach ($secs as $s) {
    $mm = $byDoc[(int) $s['maludb_document_id']] ?? null;
    $good += (int) ($mm && $mm['type'] === $ns && (int) $mm['meta']['base'] === $bid && (int) $mm['meta']['source'] === (int) $s['source_id'] && (int) $mm['meta']['section'] === (int) $s['id'] && array_key_exists('page', $mm['meta']));
}
ok($good === count($secs), 'each document: document_type kb:N, metadata naming base, source, section and page');
$tags = q('SELECT 1') && true;
$projTagged = static fn (int $source): array => array_map('intval', explode(',', as_postgres("SELECT string_agg(DISTINCT (t.tag_value = 'kb:$bid/SMOKE Warranty')::int::text, ',') FROM maludb_document_tag t WHERE t.tag_kind = 'project' AND t.document_id IN (" .
    implode(',', array_map(static fn ($s) => (int) $s['maludb_document_id'], array_filter($secs, static fn ($s) => (int) $s['source_id'] === $source))) . ')', 'engine') ?: '0'));
ok($projTagged($w['manual']) === [1] && $projTagged($w['returns']) === [1], 'the Warranty sources\' documents are tagged with the area\'s project');
ok(as_postgres("SELECT count(*) FROM maludb_document_tag WHERE tag_kind = 'project' AND document_id IN (" . implode(',', array_map(static fn ($s) => (int) $s['maludb_document_id'], array_filter($secs, static fn ($s) => (int) $s['source_id'] === $w['training']))) . ')', 'engine') === '0',
    '…and the training notes\' are not');
$cal = q("SELECT maludb_document_id FROM sections WHERE source_id = :s AND heading = 'Calibration'", ['s' => $w['manual']])[0]['maludb_document_id'] ?? 0;
ok(as_postgres("SELECT count(*) FROM maludb_document_tag WHERE tag_kind = 'subject' AND tag_value = 'Zorblat Pump' AND document_id = " . (int) $cal, 'engine') === '1', 'the hints arrive as subject tags (Calibration names the Zorblat Pump)');
$items = q('SELECT chars, usage FROM build_items WHERE build_id = :id', ['id' => $id]);
$u = usage_month($bid);
$chunks = array_sum(array_map(static fn ($i) => (int) (json_decode($i['usage'], true)['chunks'] ?? 0), $items));
ok((int) $u['chars'] === array_sum(array_column($items, 'chars')) && (int) $u['chars'] === (int) $r['chars_processed'], 'ai_usage this month: the characters sent (' . $u['chars'] . ')');
ok((int) $u['calls'] === $chunks && $chunks >= $total, "…and the calls = the chunks ($chunks)");
$base = base_row_of($bid);
$edges = array_sum(array_map(static fn ($i) => (int) (json_decode($i['usage'], true)['edges'] ?? 0), $items));
ok($base['status'] === 'ready' && $base['last_built_at'] !== null, 'the base ready, last_built_at set');
ok((int) $base['documents_count'] === $total && (int) $base['statements_count'] === $edges && $edges > 0 && (int) $base['subjects_count'] > 0 && (int) $r['subjects_added'] === (int) $base['subjects_count'],
    "the counts: {$base['documents_count']} documents, {$base['statements_count']} statements, {$base['subjects_count']} subjects (all added by this build)");
ok(told(PRIYA, 'build_done') === 1 && told(MARCO, 'build_done') === 1 && told(SEAMUS, 'build_done') === 0, 'Priya and Marco told build_done once; the agent not');
$done = activity('build.done', $since);
$a = json_decode($done[0]['after'] ?? '{}', true);
ok(count($done) === 1 && $done[0]['source'] === 'cron' && $done[0]['actor_member_id'] === null && $a['done'] === $total && $a['statements_added'] === $edges && $a['calls'] === $chunks && $a['stopped'] === null, 'build.done (cron) with the counts');
ok(log_clean([MANUAL_WORDS, 'quarterly calibration', 'Zorblat Pump']), 'no section text, no subject name in any log row');
[, $d] = screen(as_member(PRIYA), "/bases/$bid/builds");
ok(($d['blocker'] ?? '') === 'Nothing to build — every section is in the graph under version ' . base_version($bid) . '.', 'now: "' . ($d['blocker'] ?? '') . '"');
$p = page(as_member(PRIYA), "/builds/$id/progress", ['headers' => ['HX-Request: true']]);
ok($p['code'] === 286 && !str_contains($p['body'], 'hx-trigger'), 'the progress partial of a finished build answers 286 without the polling attributes');

echo "4. Stale sections: forget, then ingest\n";
$old = array_map(static fn ($s) => (int) $s['maludb_document_id'], sections_by($bid));
[$c, $b] = act(as_member(PRIYA), '/guidelines/save.php', ['base' => $bid, 'kind' => 'extraction', 'title' => 'SMOKE Parts', 'body' => 'Extract every spare part and its interval.']);
ok($c === 200 && stale_count($bid) === $total, "an extraction guideline saved: the $total sections stale");
[$c, $b] = start(PRIYA, $bid);
$id2 = (int) $b['record_id'];
run_build($id2);
$r2 = build_row($id2);
$new = array_map(static fn ($s) => (int) $s['maludb_document_id'], sections_by($bid));
$gone = count(array_filter($old, static fn ($d) => kn_engine_document($d)['status'] === 404));
ok($r2['status'] === 'done' && (int) $r2['items_done'] === $total, 'the rebuild of the stale sections: done');
ok($gone === $total && array_intersect($old, $new) === [] && count(engine_docs($bid)) === $total, "each old document forgotten (GET → 404, $gone) and a new one recorded; the namespace still holds $total");
ok(stale_count($bid) === 0 && (int) one('SELECT count(*) FROM sections WHERE base_id = :b AND extracted_version = :v', ['b' => $bid, 'v' => base_version($bid)]) === $total, 'every section extracted under the new version');

echo "5. A guideline saved during a build\n";
$mv = curated_base('SMOKE Guidelines Move', 'smoke-guidelines-move');
add_text($mv, 'SMOKE Slow One', "# Slow\n\nThe Zorblat Pump hums slowly. SLOWPROVIDER");
add_text($mv, 'SMOKE Quick Two', "# Quick\n\nThe Merrow Filter traps dust.");
worker();
[$c, $b] = start(PRIYA, $mv);
$id3 = (int) $b['record_id'];
$v0 = base_version($mv);
$proc = worker_bg();
$t = microtime(true);
while (one("SELECT count(*) FROM build_items WHERE build_id = :b AND status = 'running'", ['b' => $id3]) == 0 && microtime(true) - $t < 15) { usleep(100000); }
[$c, $b] = act(as_member(PRIYA), '/guidelines/save.php', ['base' => $mv, 'kind' => 'exclusion', 'title' => 'SMOKE Moved', 'body' => 'Nothing about colours.']);
wait_worker($proc);
$r3 = build_row($id3);
$slow = q("SELECT x.* FROM sections x JOIN sources s ON s.id = x.source_id WHERE s.title = 'SMOKE Slow One'")[0];
$quick = q("SELECT x.* FROM sections x JOIN sources s ON s.id = x.source_id WHERE s.title = 'SMOKE Quick Two'")[0];
ok($c === 200 && base_version($mv) === $v0 + 1, 'the guideline saved while the slow section was in the engine');
ok($r3['status'] === 'done' && $r3['error'] === 'stopped_guidelines' && (int) $r3['items_done'] === 1, 'the build done with the stopped_guidelines note');
ok($slow['status'] === 'stale' && (int) $slow['extracted_version'] === $v0 && $slow['maludb_document_id'] !== null, 'the in-flight section: its document recorded, stale again (extracted at the old version)');
ok($quick['status'] === 'queued' && one('SELECT status FROM build_items WHERE build_id = :b AND section_id = :s', ['b' => $id3, 's' => $quick['id']]) === 'skipped', 'the rest left for the next build (queued; its item skipped)');
$dn = activity('build.done', $since);
ok(json_decode(end($dn)['after'], true)['stopped'] === 'guidelines_changed', 'build.done says stopped: guidelines_changed');
ok(str_contains(page(as_member(PRIYA), "/builds/$id3")['body'], 'The guidelines changed during the build — 1 section left for the next one.'), 'build-view says so in words');

echo "6. Rebuild everything\n";
[$c, $b] = start(PRIYA, $bid, 'rebuild_all');
ok($c === 422 && isset(fields($b)['confirm']), 'without confirm: refused (' . (fields($b)['confirm'] ?? '') . ')');
$before = array_map(static fn ($s) => (int) $s['maludb_document_id'], sections_by($bid));
[$c, $b] = start(PRIYA, $bid, 'rebuild_all', ['confirm' => '1']);
$id4 = (int) ($b['record_id'] ?? 0);
ok($c === 200 && build_row($id4)['kind'] === 'rebuild_all' && (int) build_row($id4)['items_total'] === $total && str_starts_with($b['did'] ?? '', 'Rebuilding everything:'), "with it: a rebuild_all build of all $total sections");
ok(count(activity('build.rebuild_all', $since)) === 1, 'build.rebuild_all logged');
run_build($id4);
$after = array_map(static fn ($s) => (int) $s['maludb_document_id'], sections_by($bid));
ok(build_row($id4)['status'] === 'done' && array_intersect($before, $after) === [] && count(array_filter($before, static fn ($d) => kn_engine_document($d)['status'] === 404)) === $total, 'every live section forgotten then ingested again');
$reg = json_decode(file_get_contents(APP_ROOT . '/mcp/action_registry.json'), true);
ok(($reg['actions']['build_rebuild_all']['approval'] ?? null) === 'other' && empty($reg['actions']['build_start']['approval']) && empty($reg['actions']['build_cancel']['approval']), 'the registry: build_rebuild_all other (the pause is Phase 4\'s proof); start and cancel free');
finish();
