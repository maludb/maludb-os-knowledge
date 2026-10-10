<?php
/** Slice 4 proof — failures and timeouts (builds.md "Proof", bullets 4 and 5): what the worker does with each of the engine's answers. */
require __DIR__ . '/lib.php';

/** A base with one pasted source per text, read and cut: [base, [section ids in order]]. */
function failing_base(string $name, string $key, array $texts): array
{
    $b = curated_base($name, $key);
    foreach ($texts as $i => $t) { add_text($b, "SMOKE $name " . ($i + 1), "# Part " . ($i + 1) . "\n\n$t"); }
    worker();
    return [$b, array_map('intval', array_column(q("SELECT x.id FROM sections x JOIN sources s ON s.id = x.source_id WHERE x.base_id = :b ORDER BY s.added_at, s.id, x.position", ['b' => $b]), 'id'))];
}
function item_of(int $build, int $section): array { return q('SELECT * FROM build_items WHERE build_id = :b AND section_id = :s', ['b' => $build, 's' => $section])[0] ?? []; }
function section_row(int $id): array { return q('SELECT * FROM sections WHERE id = :s', ['s' => $id])[0] ?? []; }
/** Rewind the engine's subject sequence so the next $n subject ids it hands out already exist (the M7 defect, as the engine proof does): a run of
 *  $n taken ids is found (the API's own allocations leave gaps) and the sequence set just before it. */
function rewind_subjects(int $n): bool
{
    $t = 'maludb_core."malu$svpor_subject"';
    $start = (int) as_postgres("SELECT max(s.subject_id) FROM $t s WHERE (SELECT count(*) FROM $t x WHERE x.subject_id BETWEEN s.subject_id AND s.subject_id + " . ($n - 1) . ") = $n", 'kn_engine_p3');
    if ($start < 2) { return false; }
    as_postgres("SELECT setval(pg_get_serial_sequence('maludb_core.\"malu\$svpor_subject\"', 'subject_id'), " . ($start - 1) . ', true)', 'kn_engine_p3');
    return true;
}
function restore_subjects(): void { as_postgres("SELECT setval(pg_get_serial_sequence('maludb_core.\"malu\$svpor_subject\"', 'subject_id'), (SELECT max(subject_id) FROM maludb_core.\"malu\$svpor_subject\"), true)", 'kn_engine_p3'); }

echo "7. A provider failure: three attempts, then the section failed\n";
world4();
$run = substr(bin2hex(random_bytes(3)), 0, 6);
[$fb, $fs] = failing_base('Fail', 'smoke-fail', ["The Zorblat Pump hums. FAILPROVIDER", "The Merrow Filter traps dust."]);
[, $b] = start(PRIYA, $fb);
$id = (int) $b['record_id'];
$u0 = usage_month($fb);
$t = microtime(true);
run_build($id);
$secs = microtime(true) - $t;
$it = item_of($id, $fs[0]);
$s = section_row($fs[0]);
$r = build_row($id);
$bu = json_decode($r['usage'], true);
ok($it['status'] === 'failed' && str_starts_with((string) $it['error'], 'model_failed: Model endpoint returned HTTP 500'), 'the FAILPROVIDER item failed with the engine\'s words: "' . $it['error'] . '"');
ok($s['status'] === 'failed' && $s['error'] === $it['error'] && $s['maludb_document_id'] === null, '…and so did its section, with the same words');
ok((int) $bu['attempts'] === 4 && (int) $bu['retries'] === 2 && $secs >= 10, sprintf('three attempts (retries 2), after 2 s and 8 s (%.1f s)', $secs));
ok($r['status'] === 'done' && (int) $r['items_done'] === 1 && (int) $r['items_failed'] === 1, 'the build done with one failed');
ok((int) usage_month($fb)['calls'] === 4 && (int) usage_month($fb)['chars'] === 3 * (int) $it['chars'] + (int) item_of($id, $fs[1])['chars'], 'every paid attempt counted in ai_usage (4 calls, the failed section\'s characters three times)');
ok(str_contains(page(as_member(PRIYA), "/builds/$id")['body'], 'id="build-failed-' . $fs[0] . '"'), 'build-view lists the failed section, linked');
[$bj, $bjs] = failing_base('Bad Json', 'smoke-badjson', ["The Quillon Valve leaks. BADJSON"]);
[, $b] = start(PRIYA, $bj);
run_build((int) $b['record_id'], 4, ['KN_BUILD_BACKOFF' => '0']);
$it = item_of((int) $b['record_id'], $bjs[0]);
ok($it['status'] === 'failed' && str_contains((string) $it['error'], 'not valid JSON') && (int) json_decode(build_row((int) $b['record_id'])['usage'], true)['attempts'] === 3, 'BADJSON the same: three attempts, failed "' . $it['error'] . '"');
ok(build_row((int) $b['record_id'])['status'] === 'failed' && build_row((int) $b['record_id'])['error'] === 'every_section_failed', 'a build whose every section failed: failed / every_section_failed');

echo "8. Five failures in a row stop the build\n";
$texts = array_merge(array_fill(0, 6, 'The pump fails. FAILPROVIDER'), ['The Merrow Filter traps dust.']);
[$five, $fives] = failing_base('Five', 'smoke-five', $texts);
$p0 = told(PRIYA, 'build_failed'); $m0 = told(MARCO, 'build_failed');
[, $b] = start(PRIYA, $five);
$id5 = (int) $b['record_id'];
run_build($id5, 4, ['KN_BUILD_BACKOFF' => '0']);
$r = build_row($id5);
$st = array_column(q('SELECT status, count(*) AS n FROM build_items WHERE build_id = :b GROUP BY 1', ['b' => $id5]), 'n', 'status');
ok($r['status'] === 'failed' && $r['error'] === 'too_many_failures' && (int) ($st['failed'] ?? 0) === 5 && (int) ($st['skipped'] ?? 0) === 2, 'five failed in a row: failed / too_many_failures, the other two skipped');
ok(told(PRIYA, 'build_failed') === $p0 + 1 && told(MARCO, 'build_failed') === $m0 + 1, 'the curators told build_failed once');
ok(count(array_filter(activity('build.fail'), static fn ($a) => (int) $a['entity_id'] === $id5 && json_decode($a['after'], true)['error'] === 'too_many_failures')) === 1, 'build.fail logged with the error code');
ok(section_row($fives[6])['status'] === 'queued', 'the untouched section stays queued for the next build');

echo "9. The duplicate-key refusal (M7)\n";
[$dup1, $d1] = failing_base('Dup One', 'smoke-dup-one', ["Novel Widget$run connects the Brand Gadget$run."]);
[, $b] = start(PRIYA, $dup1);
$idd = (int) $b['record_id'];
call_step('prepare', (string) $idd);
ok(rewind_subjects(1), 'the subject sequence rewound one place (the next id exists)');
run_build($idd);
$bu = json_decode(build_row($idd)['usage'], true);
ok(build_row($idd)['status'] === 'done' && item_of($idd, $d1[0])['status'] === 'done' && (int) $bu['duplicate_key'] === 1 && (int) $bu['attempts'] === 2, 'one refusal: retried at once and done (duplicate_key 1, attempts 2)');
[$dup2, $d2] = failing_base('Dup Two', 'smoke-dup-two', ["Other Widget$run joins the Fresh Gadget$run.", "Third Widget$run meets the Last Gadget$run."]);
[, $b] = start(PRIYA, $dup2);
$idd2 = (int) $b['record_id'];
call_step('prepare', (string) $idd2);
ok(rewind_subjects(3), 'the subject sequence rewound before three taken ids');
run_build($idd2);
$r = build_row($idd2);
$bu = json_decode($r['usage'], true);
ok(item_of($idd2, $d2[0])['status'] === 'failed' && str_contains((string) item_of($idd2, $d2[0])['error'], 'duplicate key'), 'the first item refused twice: failed, the engine\'s words kept');
ok($r['status'] === 'failed' && $r['error'] === 'engine_subject_ids' && (int) $bu['duplicate_key'] === 2, 'the second item refused: the build failed / engine_subject_ids');
ok((int) usage_month($dup2)['calls'] === (int) $bu['attempts'] && (int) $bu['attempts'] === 3, 'every paid attempt counted (3)');
restore_subjects();

echo "10. The engine down, then back\n";
[$dn, $dns] = failing_base('Down', 'smoke-down', ['The Zorblat Pump hums.', 'The Merrow Filter traps dust.']);
[, $b] = start(PRIYA, $dn);
$idn = (int) $b['record_id'];
call_step('prepare', (string) $idn);
engine('restart-api');                                   // stops the API
$u0 = usage_month($dn);
worker();
ok(build_row($idn)['status'] === 'running' && (int) build_row($idn)['items_done'] === 0, 'a pass with the API stopped: the build waits');
$item = (int) item_of($idn, $dns[0])['id'];
$ans = call_step('item', (string) $idn, (string) $item);
ok($ans === 'down' && item_of($idn, $dns[0])['status'] === 'queued' && usage_month($dn) == $u0, 'an item when the engine is down: back to queued, nothing counted');
ok(str_contains((string) build_row($idn)['log'], "waiting: engine_unavailable"), 'the build\'s log says "waiting: engine_unavailable"');
engine('restart-api');                                   // starts it
run_build($idn);
ok(build_row($idn)['status'] === 'done' && (int) build_row($idn)['items_done'] === 2, 'restarted: the build resumes and finishes');
[$dc, $dcs] = failing_base('Clock', 'smoke-clock', ['The Zorblat Pump hums.']);
[, $b] = start(PRIYA, $dc);
$idc = (int) $b['record_id'];
engine('restart-api');                                   // stops it
$p0 = told(PRIYA, 'build_failed');
as_postgres("ALTER TABLE builds DISABLE TRIGGER builds_touch; UPDATE builds SET updated_at = now() - interval '31 minutes' WHERE id = $idc; ALTER TABLE builds ENABLE TRIGGER builds_touch;");
worker();
worker();
ok(build_row($idc)['status'] === 'failed' && build_row($idc)['error'] === 'engine_unavailable', 'left 31 minutes with the engine down: failed / engine_unavailable');
ok(told(PRIYA, 'build_failed') === $p0 + 1, 'Priya told once (two passes)');
engine('restart-api');                                   // starts it

echo "11. The model not set up\n";
[$nm, $nms] = failing_base('No Model', 'smoke-no-model', ['The Zorblat Pump hums.']);
[, $b] = start(PRIYA, $nm);
$idm = (int) $b['record_id'];
as_postgres("UPDATE \"malu\$memory_extraction_config\" SET namespace = 'kb:0-off' WHERE namespace = 'kb:0'; DELETE FROM \"malu\$memory_extraction_config\" WHERE namespace = 'kb:$nm'", 'engine');
worker();
ok(build_row($idm)['status'] === 'failed' && build_row($idm)['error'] === 'model_not_configured' && section_row($nms[0])['status'] === 'queued', 'kb:0 removed: the build failed / model_not_configured, the section still queued');
as_postgres("UPDATE \"malu\$memory_extraction_config\" SET namespace = 'kb:0' WHERE namespace = 'kb:0-off'", 'engine');

echo "12. A timeout: the engine asked before paying again\n";
[$sl, $sls] = failing_base('Slow', 'smoke-slow', ['The Zorblat Pump hums slowly. SLOWPROVIDER']);
[, $b] = start(PRIYA, $sl);
$ids = (int) $b['record_id'];
$x0 = (int) (fake('/calls')['extract'] ?? 0);
run_build($ids, 3, ['MALUDB_KB_API_TIMEOUT' => '1']);
$docs = engine_docs($sl);
ok(build_row($ids)['status'] === 'done' && item_of($ids, $sls[0])['status'] === 'done', 'MALUDB_KB_API_TIMEOUT=1 and a slow provider: the item done');
ok(count($docs) === 1 && (int) section_row($sls[0])['maludb_document_id'] === $docs[0], 'the document the engine kept is the section\'s — one document');
ok((int) (fake('/calls')['extract'] ?? 0) - $x0 === 1 && str_contains((string) build_row($ids)['log'], 'adopted'), 'no second ingest (one extraction at the provider); the log says adopted');
ok((int) json_decode(build_row($ids)['usage'], true)['attempts'] === 1, 'the timed-out attempt counted once');
finish();
