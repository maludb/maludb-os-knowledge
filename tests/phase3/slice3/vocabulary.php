<?php
/** Slice 3 proof — the vocabulary (guidelines-areas.md "Proof", bullet 4). */
require __DIR__ . '/lib.php';

echo "4. Vocabulary\n";
$w = world();
$bid = $w['base'];
$priya = as_member(PRIYA);
$v0 = base_version($bid);
$since = last_activity_id();
[$c, $b] = act($priya, '/vocabulary/save.php', ['base' => $bid, 'canonical_name' => 'Zorblat Pump', 'aliases' => "ZP-9\nthe pump, Zorblat Pump", 'subject_type' => 'equipment', 'description' => 'The circulation pump.']);
$zp = (int) ($b['record_id'] ?? 0);
ok($c === 200 && base_version($bid) === $v0 + 1 && count(activity('vocabulary.save', $since)) === 1 && count(activity('guideline.version', $since)) === 1, 'a term with aliases → a version bump, vocabulary.save and guideline.version logged');
ok(pg_text_array_q((string) one('SELECT aliases FROM vocabulary_terms WHERE id = :t', ['t' => $zp])) === ['ZP-9', 'the pump'], 'aliases split on lines and commas, the canonical name never an alias');
$cp = page($priya, '/bases/' . $bid . '/compiled')['body'];
ok(str_contains($cp, 'ALIAS: ZP-9 =&gt; Zorblat Pump') && str_contains($cp, 'TERM: Zorblat Pump (equipment) — The circulation pump.'), 'the TERM and ALIAS lines in /bases/{id}/compiled');
ok(kn_compile_engine_sha('kb:' . $bid) === kn_compile_prompt(pdo(), $bid)['sha256'], 'the engine has the new prompt');
[$c, $b] = act($priya, '/vocabulary/save.php', ['base' => $bid, 'canonical_name' => 'zorblat pump']);
ok($c === 422 && (fields($b)['canonical_name'] ?? '') === 'The base already has the term zorblat pump.', 'a duplicate name (any case) → the field');
[$c, $b] = act($priya, '/vocabulary/save.php', ['base' => $bid, 'canonical_name' => 'Gizmo', 'subject_type' => 'type9']);
ok($c === 422 && isset(fields($b)['subject_type']), 'a type with a digit → the field');
[$c, $b] = act($priya, '/vocabulary/save.php', ['base' => $bid, 'canonical_name' => 'Pump Housing', 'aliases' => 'the pump', 'subject_type' => 'equipment']);
$page = page($priya, '/bases/' . $bid . '/vocabulary')['body'];
ok($c === 200 && str_contains($page, 'id="alias-conflicts"') && str_contains($page, '"the pump" is claimed by Pump Housing and Zorblat Pump'), 'an alias conflict is shown');
[$c, $b] = act($priya, '/vocabulary/save.php', ['base' => $bid, 'canonical_name' => 'Flux Capacitor', 'subject_type' => 'equipment']);
$flux = (int) ($b['record_id'] ?? 0);
$page = page($priya, '/bases/' . $bid . '/vocabulary')['body'];
preg_match('#<tr id="term-row-' . $flux . '">.*?</tr>#s', $page, $fr);
preg_match('#<tr id="term-row-' . $zp . '">.*?</tr>#s', $page, $zr);
ok(str_contains($fr[0] ?? '', 'never met') && !str_contains($zr[0] ?? 'never met', 'never met'), 'never met for a term no section mentions; not for one that does');
ok(str_contains(page($priya, '/bases/' . $bid . '/vocabulary?never=1')['body'], 'id="term-row-' . $flux . '"') && !str_contains(page($priya, '/bases/' . $bid . '/vocabulary?never=1')['body'], 'id="term-row-' . $zp . '"'), 'the never-met filter');
// the CSV import
$csv = "term,aliases,type,description\n";
for ($i = 1; $i <= 46; $i++) { $csv .= sprintf("SMOKE Part %02d,P%02d;Part%02d,part,Part number %d\n", $i, $i, $i, $i); }
$csv .= "Zorblat Pump,ZP9,equipment,The circulation pump (updated).\nPump Housing,,equipment,\n";
$csv .= "Bad Type,,Type9,\n,,,\n\"" . str_repeat('x', 210) . "\",,,\n";
$before = (int) one('SELECT count(*) FROM vocabulary_terms WHERE base_id = :b', ['b' => $bid]);
$v1 = base_version($bid);
$tok = csrf_of(page($priya, '/')['body']);
$r = req('POST', '/vocabulary/import.php', ['jar' => $priya, 'form' => ['csrf_token' => $tok, 'base' => $bid, 'step' => 'check', 'csv' => $csv]]);
ok($r['code'] === 200 && str_contains($r['body'], '46 new · 2 to update · 2 refused') && (int) one('SELECT count(*) FROM vocabulary_terms WHERE base_id = :b', ['b' => $bid]) === $before && base_version($bid) === $v1,
    'a CSV of 50 rows with two bad lines: Check shows 46 new, 2 to update, 2 refused — and writes nothing');
ok(str_contains($r['body'], 'id="import-check-line-50"') && str_contains($r['body'], 'no digits'), '…each refused line with its reason');
$since = last_activity_id();
[$c, $b] = act($priya, '/vocabulary/import.php', ['base' => $bid, 'step' => 'apply', 'csv' => $csv]);
ok($c === 200 && ($b['added'] ?? 0) === 46 && ($b['updated'] ?? 0) === 2 && (int) one('SELECT count(*) FROM vocabulary_terms WHERE base_id = :b', ['b' => $bid]) === $before + 46, 'Import adds 46 and updates 2 in one transaction');
ok(base_version($bid) === $v1 + 47, 'the version rises once per changed term (46 new and the one updated with new words; the unchanged one shapes nothing — divergence 1): ' . ($v1 + 47) . ' = ' . base_version($bid));
ok(str_starts_with($b['did'] ?? '', '46 added, 2 updated, 2 refused — the base is now at version'), 'the did words it: "' . ($b['did'] ?? '') . '"');
$imp = activity('vocabulary.save', $since);
ok(count($imp) === 1 && json_decode($imp[0]['after'], true)['import'] == ['added' => 46, 'updated' => 2, 'refused' => 2], 'one vocabulary.save with after.import');
ok(pg_text_array_q((string) one("SELECT aliases FROM vocabulary_terms WHERE base_id = :b AND canonical_name = 'Pump Housing'", ['b' => $bid])) === ['the pump'], 'an empty cell keeps (Pump Housing\'s alias stays)');
[$c, $b] = act($priya, '/vocabulary/delete.php', ['term' => $flux]);
ok($c === 200 && count(activity('vocabulary.delete')) === 1 && (int) one('SELECT count(*) FROM vocabulary_terms WHERE id = :t', ['t' => $flux]) === 0, 'delete → vocabulary.delete');
$big = "term\n" . implode("\n", array_map(static fn ($i) => "SMOKE Many $i", range(1, 501)));
[$c, $b] = act($priya, '/vocabulary/import.php', ['base' => $bid, 'step' => 'apply', 'csv' => $big]);
ok($c === 422 && str_contains((string) (fields($b)['csv'] ?? ''), 'At most 500 rows') && (int) one("SELECT count(*) FROM vocabulary_terms WHERE canonical_name LIKE 'SMOKE Many%'") === 0, '501 rows → refused whole');
ok(page(as_member(REID), '/bases/' . $bid . '/vocabulary')['code'] === 403, 'Reid → 403 on the vocabulary');
finish();

function pg_text_array_q(string $l): array { return kn_compile_pg_array($l); }
