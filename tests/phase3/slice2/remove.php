<?php
/**
 * Slice 2 proof — remove and replace, against the REAL engine (sources-sections.md "Proof", bullet 6): the test ingests sections with
 * kn_engine_ingest_section() (the fake provider extracts) and writes their document ids, as slice 4's build will; the worker forgets them.
 */
require __DIR__ . '/lib.php';
define('APP_ROOT', dirname(__DIR__, 3));
require APP_ROOT . '/app/knowledge/engine.php';

echo "6. Remove and replace, against the engine\n";
$bid = world()['base'];
$priya = as_member(PRIYA);
$ns = 'kb:' . $bid;
$cfg = kn_engine_config_namespace($ns, ['provider_name' => 'kb-fake', 'provider_kind' => 'local_http', 'model_alias' => 'kb-extract', 'model_identifier' => 'fake-model',
    'base_url' => need('KN_PROOF_FAKE_URL') . '/v1', 'embedding_model' => 'maludb-local-dev', 'secret_name' => 'knowledge_provider', 'token' => need('KN_PROOF_PROVIDER_SECRET'),
    'prompt_template' => "KNOWLEDGE BASE $ns — extraction (compiled)\nReturn ONLY JSON of the form {\"candidate_edges\":[...]}.\n\nText:\n{{chunk}}"]);
ok($cfg['ok'], 'the base\'s namespace is configured on the scratch engine (' . ($cfg['code'] ?? 'ok') . ')');
/** Ingest a section as slice 4's build will, and write its document id. */
$ingest = static function (int $sectionId) use ($ns): int {
    $x = q('SELECT x.*, s.title FROM sections x JOIN sources s ON s.id = x.source_id WHERE x.id = :x', ['x' => $sectionId])[0];
    $r = kn_engine_ingest_section($ns, $x['heading_path'], 'Zorblat Pump needs a check. ' . $x['text'], ['base' => (int) $x['base_id'], 'source' => (int) $x['source_id'], 'section' => $sectionId]);
    $doc = (int) ($r['data']['document_id'] ?? 0);
    if ($doc > 0) { pdo()->exec("UPDATE sections SET maludb_document_id = '$doc', status = 'extracted', extracted_version = 1 WHERE id = $sectionId"); }
    return $doc;
};
$notes = (int) one("SELECT id FROM sources WHERE base_id = :b AND origin = 'Notes.md'", ['b' => $bid]);
$secs = sections_of($notes);
$d1 = $ingest((int) $secs[0]['id']);
$d2 = $ingest((int) $secs[1]['id']);
ok($d1 > 0 && $d2 > 0 && kn_engine_document($d1)['ok'] && kn_engine_document($d2)['ok'], "two of Notes.md's sections are documents in the engine ($d1, $d2)");
$att = (int) one('SELECT attachment_id FROM sources WHERE id = :s', ['s' => $notes]);
$file = APP_DIR_STORAGE . '/' . one('SELECT storage_path FROM attachments WHERE id = :a', ['a' => $att]);
$since = last_activity_id();
[$c, $b] = act($priya, '/sources/remove.php', ['source' => $notes]);
ok($c === 200 && ($b['location'] ?? '') === '/bases/' . $bid . '/sources' && str_contains($b['did'] ?? '', 'within the minute'), 'source_remove: "' . ($b['did'] ?? msg($b)) . '"');
$s = source_of($notes);
ok($s['status'] === 'forgotten' && (int) one("SELECT count(*) FROM sections WHERE source_id = :s AND status <> 'forgotten'", ['s' => $notes]) === 0, 'the source forgotten, its sections forgotten');
ok((int) one('SELECT count(*) FROM source_text WHERE source_id = :s', ['s' => $notes]) === 0 && (int) one('SELECT count(*) FROM source_pages WHERE source_id = :s', ['s' => $notes]) === 0
    && (int) one("SELECT count(*) FROM sections WHERE source_id = :s AND text <> ''", ['s' => $notes]) === 0, 'source_text and source_pages gone, the sections\' text empty');
ok(!is_file($file) && page($priya, '/files/' . $att)['code'] === 404, 'the file gone; /files/{id} 404');
$rm = activity('source.remove', $since);
ok(count($rm) === 1 && json_decode($rm[0]['after'], true)['sections'] === count($secs), 'source.remove logged with the sections');
ok(str_contains(page($priya, '/sections/' . (int) $secs[0]['id'])['body'], 'This section was removed with its source.'), 'a removed section says so and shows no text');
ok((int) one('SELECT count(*) FROM sections WHERE source_id = :s AND maludb_document_id IS NOT NULL', ['s' => $notes]) === 2, 'the document ids wait for the worker');
worker();
$g1 = kn_engine_document($d1);
$g2 = kn_engine_document($d2);
ok(!$g1['ok'] && $g1['status'] === 404 && !$g2['ok'] && $g2['status'] === 404, 'the next pass: GET /v1/documents/{id} answers 404 for both');
ok((int) one('SELECT count(*) FROM sections WHERE source_id = :s AND maludb_document_id IS NOT NULL', ['s' => $notes]) === 0, 'the ids are cleared');
$fg = activity('source.forget', $since);
ok(count($fg) === 1 && (int) $fg[0]['entity_id'] === $notes && json_decode($fg[0]['after'], true)['sections'] === 2 && $fg[0]['source'] === 'cron', 'source.forget logged (2 sections, by cron)');
// replace a source by a new DOCX; its area row is copied
$hand = (int) one("SELECT id FROM sources WHERE base_id = :b AND origin = 'Staff_Handbook.docx'", ['b' => $bid]);
$area = (int) one("INSERT INTO focus_areas (base_id, name, created_by) VALUES (:b, 'SMOKE Policies', 26) RETURNING id", ['b' => $bid]);
pdo()->exec("INSERT INTO source_focus_areas (source_id, focus_area_id, assigned_by) VALUES ($hand, $area, 26)");
$hd = $ingest((int) sections_of($hand)[0]['id']);
[$c, $b] = upload($priya, '/sources/replace.php', ['source' => $hand, 'kind' => 'file'], ['Handbook_v2.docx' => fixtures()['Handbook_v2.docx']]);
$new = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $new > 0 && ($b['location'] ?? '') === '/sources/' . $new, 'source_replace by a new DOCX: location the new source (' . ($b['did'] ?? msg($b)) . ')');
$old = source_of($hand);
ok((int) $old['superseded_by'] === $new && $old['status'] === 'forgotten' && (int) one("SELECT count(*) FROM sections WHERE source_id = :s AND status <> 'forgotten'", ['s' => $hand]) === 0,
    'the old one superseded by the new, forgotten, its sections too');
ok((int) one('SELECT count(*) FROM source_focus_areas WHERE source_id = :s AND focus_area_id = :a', ['s' => $new, 'a' => $area]) === 1, 'its area row copied to the new one');
$rp = activity('source.replace', $since);
ok(count($rp) === 1 && json_decode($rp[0]['after'], true)['new_source_id'] === $new, 'source.replace logged with both ids');
ok(str_contains(page($priya, '/sources/' . $hand)['body'], 'id="source-replaced-by"'), 'the old one says "Replaced by …"');
worker();
ok(source_of($new)['status'] === 'ready' && count(sections_of($new)) >= 1 && kn_engine_document($hd)['status'] === 404, 'the next pass reads the new one and forgets the old one\'s document');
$fed = (int) one("INSERT INTO sources (base_id, kind, title, origin, added_by, status) VALUES (:b, 'feed', 'SMOKE Fed', 'feed:helpdesk.tickets#1', 26, 'ready') RETURNING id", ['b' => $bid]);
[$c, $b] = act($priya, '/sources/replace.php', ['source' => $fed, 'kind' => 'text', 'text' => 'New words.']);
ok($c === 422 && msg($b) === 'A fed source is replaced by its feed.', 'a fed source → refused in words');
// the engine down, then back
$pv = (int) one("SELECT id FROM sources WHERE base_id = :b AND origin = 'Quarterly_Review.pptx'", ['b' => $bid]);
$dp = $ingest((int) sections_of($pv)[0]['id']);
act($priya, '/sources/remove.php', ['source' => $pv]);
shell_exec(escapeshellarg(dirname(__DIR__) . '/engine.sh') . ' restart-api');
$line = worker();
ok((int) one('SELECT count(*) FROM sections WHERE source_id = :s AND maludb_document_id IS NOT NULL', ['s' => $pv]) === 1, 'the API stopped: the forget step stops, the id kept (' . $line . ')');
shell_exec(escapeshellarg(dirname(__DIR__) . '/engine.sh') . ' restart-api');
worker();
ok((int) one('SELECT count(*) FROM sections WHERE source_id = :s AND maludb_document_id IS NOT NULL', ['s' => $pv]) === 0 && kn_engine_document($dp)['status'] === 404, '…and resumes after the restart');
// a document already gone
$csv = (int) one("SELECT id FROM sources WHERE base_id = :b AND origin = 'Claims.csv'", ['b' => $bid]);
$cs = (int) sections_of($csv)[0]['id'];
pdo()->exec("UPDATE sections SET maludb_document_id = '987654321', status = 'extracted', extracted_version = 1 WHERE id = $cs");
act($priya, '/sources/remove.php', ['source' => $csv]);
worker();
ok(one('SELECT maludb_document_id FROM sections WHERE id = :x', ['x' => $cs]) === null, 'a document already gone from the engine (not_found) → cleared');
[$c, $b] = act(as_member(REID), '/sources/remove.php', ['source' => $pv]);
ok($c === 403, 'a Reader may not remove a source');
[$c, $b] = act($priya, '/sources/remove.php', ['source' => $notes]);
ok($c === 422 && str_contains(msg($b), 'removed already'), 'a removed source is not removed twice');
ok(!plant_in_log(), 'the planted phrase is in no activity_log row');
finish();
