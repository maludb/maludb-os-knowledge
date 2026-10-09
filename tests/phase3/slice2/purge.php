<?php
/** Slice 2 proof — purge: slice 1's deletion of a base the engine holds finishes in the worker (sources-sections.md "Proof", bullet 7). */
require __DIR__ . '/lib.php';
define('APP_ROOT', dirname(__DIR__, 3));
require APP_ROOT . '/app/knowledge/engine.php';

echo "7. Purge\n";
$priya = as_member(PRIYA);
$bid = make_base(PRIYA, 'SMOKE Doomed Base');
$ns = 'kb:' . $bid;
$cfg = kn_engine_config_namespace($ns, ['provider_name' => 'kb-fake', 'provider_kind' => 'local_http', 'model_alias' => 'kb-extract', 'model_identifier' => 'fake-model',
    'base_url' => need('KN_PROOF_FAKE_URL') . '/v1', 'embedding_model' => 'maludb-local-dev', 'secret_name' => 'knowledge_provider', 'token' => need('KN_PROOF_PROVIDER_SECRET'),
    'prompt_template' => "KNOWLEDGE BASE $ns\nReturn ONLY JSON of the form {\"candidate_edges\":[...]}.\n\nText:\n{{chunk}}"]);
[$c, $b] = upload($priya, '/sources/add.php', ['base' => $bid, 'kind' => 'file'], ['Doomed.md' => fx_write('Doomed.md', "# Doomed\n\n" . PLANT . ' ' . fx_para(41, 300) . "\n")]);
$src = (int) ($b['record_id'] ?? 0);
worker();
$sec = (int) one('SELECT id FROM sections WHERE source_id = :s ORDER BY position LIMIT 1', ['s' => $src]);
$r = kn_engine_ingest_section($ns, 'Doomed', 'Zorblat Pump is doomed. ' . (string) one('SELECT text FROM sections WHERE id = :x', ['x' => $sec]), ['base' => $bid, 'source' => $src, 'section' => $sec]);
$doc = (int) ($r['data']['document_id'] ?? 0);
pdo()->exec("UPDATE sections SET maludb_document_id = '$doc', status = 'extracted', extracted_version = 1 WHERE id = $sec");
ok($cfg['ok'] && $doc > 0 && source_of($src)['status'] === 'ready', "a base with a real engine document ($doc)");
$file = APP_DIR_STORAGE . '/' . one('SELECT a.storage_path FROM attachments a JOIN sources s ON s.attachment_id = a.id WHERE s.id = :s', ['s' => $src]);
[$c, $b] = act($priya, '/bases/delete.php', ['base' => $bid, 'confirm_name' => 'SMOKE Doomed Base']);
ok($c === 200 && ($b['phase'] ?? '') === 'forgetting', 'slice 1 deletes it: phase forgetting');
ok(base_row_of($bid) !== [] && is_file($file), 'the row and the file wait for the engine');
$since = last_activity_id();
$line = worker();
clearstatcache();                                                   // is_file() above is cached in this process; the worker deleted it in another
ok(kn_engine_document($doc)['status'] === 404, 'the next pass forgets the document in the engine (' . $line . ')');
ok(base_row_of($bid) === [] && !is_file($file), 'and deletes the base and its attachment file (' . (base_row_of($bid) === [] ? 'row gone' : 'row kept') . ', ' . (is_file($file) ? 'file kept: ' . $file : 'file gone') . ')');
$d = array_values(array_filter(activity('base.delete', $since), static fn ($r) => (int) $r['base_id'] === $bid));
ok(count($d) === 1 && json_decode($d[0]['after'], true)['phase'] === 'done' && $d[0]['source'] === 'cron' && $d[0]['actor_member_id'] === null, 'logged base.delete phase done, by cron');
pdo()->exec("SELECT set_config('app.member_id', '" . PRIYA . "', false)");
ok((int) one('SELECT count(*) FROM mcp_bases WHERE base_id = :b', ['b' => $bid]) === 0, 'mcp_bases no longer lists it');
ok(!str_contains(page($priya, '/bases')['body'], 'SMOKE Doomed Base'), 'nor does the list');
finish();
