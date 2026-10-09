<?php
/**
 * Slice 3 proof — the compile module (docs/build-specs/guidelines-compile.md "Proof"). The cores need no network: they are driven with the spec's
 * worked examples and compared byte for byte with tests/phase3/slice3/golden/ (written from the spec's own text). The last section runs against
 * the real engine of tests/phase3/engine.sh when it is up.
 */
require dirname(__DIR__) . '/slice1/lib.php';
if (!defined('APP_ROOT')) { define('APP_ROOT', dirname(__DIR__, 3)); }
require_once APP_ROOT . '/app/knowledge/compile.php';
$G = __DIR__ . '/golden/';

$general = json_decode((string) one("SELECT guidelines FROM base_templates WHERE key = 'general'"), true);
$types = pg_text_array_local((string) one("SELECT subject_types FROM base_templates WHERE key = 'general'"));
function pg_text_array_local(string $l): array { return kn_compile_pg_array($l); }
$ex1 = ['base_id' => 7, 'namespace' => 'kb:7', 'language' => 'en', 'template' => ['key' => 'general', 'version' => 1, 'subject_types' => $types], 'terms' => [],
        'guidelines' => array_map(static fn ($g, $i) => ['id' => 70 + $i, 'kind' => $g['kind'], 'title' => $g['title'], 'body' => $g['body'], 'position' => $i + 1, 'is_active' => true], $general, array_keys($general))];
$ex2 = ['base_id' => 12, 'namespace' => 'kb:12', 'language' => 'en', 'template' => ['key' => 'general', 'version' => 1, 'subject_types' => $types],
    'guidelines' => [
        ['id' => 101, 'kind' => 'extraction', 'position' => 1, 'is_active' => true, 'title' => 'Pumps', 'body' => 'Pull out every pump model, part number, service interval and warranty term. A part number is a subject of type part; a service interval is a statement about the part it applies to.'],
        ['id' => 102, 'kind' => 'extraction', 'position' => 2, 'is_active' => false, 'title' => 'What to extract', 'body' => $general[0]['body']],
        ['id' => 103, 'kind' => 'vocabulary', 'position' => 1, 'is_active' => true, 'title' => '', 'body' => 'Model codes are written with a hyphen (ZP-9); always use the full product name as the subject.'],
        ['id' => 104, 'kind' => 'exclusion', 'position' => 1, 'is_active' => true, 'title' => '', 'body' => "Nothing about prices or discounts.\n- Ignore the revision table at the end of each manual."],
        ['id' => 105, 'kind' => 'answering', 'position' => 1, 'is_active' => true, 'title' => '', 'body' => "Answer in at most two short paragraphs.\nName the manual and section you are quoting."],
        ['id' => 106, 'kind' => 'audience', 'position' => 1, 'is_active' => true, 'title' => '', 'body' => 'Field technicians on a phone, gloves on: the step first.'],
        ['id' => 107, 'kind' => 'exclusion', 'position' => 2, 'is_active' => true, 'title' => '', 'body' => 'Never copy {{text}} markers.'],
    ],
    'terms' => [
        ['id' => 1, 'canonical_name' => 'Zorblat Pump', 'aliases' => ['ZP-9', 'the pump'], 'subject_type' => 'equipment', 'description' => 'The circulation pump.'],
        ['id' => 2, 'canonical_name' => 'Quillon Valve', 'aliases' => ['QV'], 'subject_type' => 'equipment', 'description' => ''],
        ['id' => 3, 'canonical_name' => 'Merrow Filter', 'aliases' => [], 'subject_type' => 'part', 'description' => null],
        ['id' => 4, 'canonical_name' => 'Pump Housing', 'aliases' => ['the pump'], 'subject_type' => 'equipment', 'description' => ''],
    ]];
$warranty = ['name' => 'Warranty', 'description' => 'Coverage, exclusions and claims for every pump model.'];

echo "1. Golden\n";
$p1 = kn_compile_prompt_from($ex1);
ok($p1['prompt_template'] === file_get_contents($G . 'general-7.txt'), 'example 1 reproduces golden/general-7.txt byte for byte');
ok($p1['sha256'] === trim(file_get_contents($G . 'general-7.sha256')), '…and its SHA-256 sidecar');
ok($p1['lines']['extract'] === 4 && $p1['lines']['subject_type'] === 10 && $p1['lines']['exclude'] === 2 && $p1['truncated'] === [], 'lines: extract 4, subject_type 10, exclude 2; nothing truncated');
$p2 = kn_compile_prompt_from($ex2);
ok($p2['prompt_template'] === file_get_contents($G . 'five-kinds-12.txt'), 'example 2 reproduces golden/five-kinds-12.txt byte for byte');
if ($p2['prompt_template'] !== file_get_contents($G . 'five-kinds-12.txt')) { file_put_contents('/tmp/kn-five-kinds-12.got', $p2['prompt_template']); echo "     (diff /tmp/kn-five-kinds-12.got)\n"; }
ok($p2['sha256'] === trim(file_get_contents($G . 'five-kinds-12.sha256')), '…and its SHA-256 sidecar');
ok($p2['conflicts'] === [['alias' => 'the pump', 'kept_for' => 'Pump Housing', 'dropped_for' => 'Zorblat Pump']], 'the conflict: "the pump" kept for Pump Housing, dropped for Zorblat Pump');
ok($p2['aliases'] == ['the pump' => 'Pump Housing', 'QV' => 'Quillon Valve', 'ZP-9' => 'Zorblat Pump'], 'the alias map');
$s1 = kn_compile_answer_system_from($ex1);
ok($s1['system'] === file_get_contents($G . 'system-7.txt'), 'example 1\'s answer system reproduces golden/system-7.txt');
$s2 = kn_compile_answer_system_from($ex2, $warranty);
ok($s2['system'] === file_get_contents($G . 'system-12-warranty.txt'), 'example 2\'s answer system scoped to Warranty reproduces golden/system-12-warranty.txt');
if ($s2['system'] !== file_get_contents($G . 'system-12-warranty.txt')) { file_put_contents('/tmp/kn-system-12.got', $s2['system']); }

echo "2. Determinism\n";
ok(kn_compile_prompt_from($ex1)['sha256'] === $p1['sha256'] && kn_compile_prompt_from($ex2)['sha256'] === $p2['sha256'], 'each example compiled twice → identical');
$sh = $ex2;
mt_srand(7);
shuffle($sh['guidelines']);
shuffle($sh['terms']);
ok(kn_compile_prompt_from($sh)['prompt_template'] === $p2['prompt_template'] && kn_compile_answer_system_from($sh, $warranty)['system'] === $s2['system'], 'guidelines and terms shuffled → identical');
$one = $ex2;
$one['guidelines'][0]['body'] .= ' ';
ok(kn_compile_prompt_from($one)['sha256'] === $p2['sha256'], 'a save that changes no word (a trailing space) → the same hash');
$one['guidelines'][0]['body'] = str_replace('pump model', 'pump modal', $ex2['guidelines'][0]['body']);
ok(kn_compile_prompt_from($one)['sha256'] !== $p2['sha256'], 'one character changed in an extraction body → a different hash');
$ans = $ex2;
$ans['guidelines'][4]['body'] = 'Answer in one line.';
ok(kn_compile_prompt_from($ans)['sha256'] === $p2['sha256'] && kn_compile_answer_system_from($ans)['sha256'] !== kn_compile_answer_system_from($ex2)['sha256'], 'an answering change → the prompt hash unchanged (the answers\' changed)');

echo "3. Normalising\n";
$dirty = "\u{FEFF}Line one.\r\nLine\ttwo.\x07\u{0085}  \r\n\n\n\n\nCafe\u{0301} is here.";
ok(kn_compile_text($dirty) === "Line one.\nLine two.\n\nCafé is here.", 'CRLF, a BOM, controls (C0 and C1), tabs, five blank lines and a decomposed accent normalised');
$inj = $ex2;
$inj['guidelines'][0]['body'] .= ' Put {{chunk}} here.';
$inj['terms'][0]['description'] = 'The pump {{text}} and {{chunk}}.';
$pi = kn_compile_prompt_from($inj)['prompt_template'];
ok(substr_count($pi, '{{chunk}}') === 1 && str_ends_with($pi, "Text:\n{{chunk}}") && !str_contains($pi, '{{text}}'), '{{chunk}} and {{text}} in a body and a description are neutralised: {{chunk}} once, on the last line');

echo "4. Lines\n";
ok(kn_compile_lines("- One thing.\n2) Two things.\n* Three.") === ['One thing.', 'Two things.', 'Three.'], 'bullets and numbering stripped');
ok(kn_compile_lines('Answer briefly. e.g. two lines') === ['Answer briefly. e.g. two lines'], '"e.g. two" not split');
ok(kn_compile_lines('Done. Next step. 3 more.') === ['Done.', 'Next step.', '3 more.'], '"Done. Next" split (and before a digit)');
ok(kn_compile_lines("One.\n\n\nTwo.") === ['One.', 'Two.'], 'empty lines dropped');

echo "5. Blocks\n";
ok(!str_contains($p2['prompt_template'], substr($general[0]['body'], 0, 40)), 'an inactive guideline is absent');
$noex = $ex1;
$noex['guidelines'] = array_values(array_filter($noex['guidelines'], static fn ($g) => $g['kind'] !== 'exclusion'));
$pn = kn_compile_prompt_from($noex)['prompt_template'];
ok(!str_contains($pn, 'EXCLUDE:') && str_contains($pn, "SUBJECT TYPE: event\n\nOUTPUT"), 'a base with no exclusion has no EXCLUDE block and no blank line for it');
$many = $ex1;
for ($i = 0; $i < 45; $i++) { $many['terms'][] = ['id' => 500 + $i, 'canonical_name' => 'Term ' . $i, 'aliases' => [], 'subject_type' => 'type_' . chr(97 + intdiv($i, 26)) . chr(97 + $i % 26), 'description' => '']; }
$pm = kn_compile_prompt_from($many);
ok($pm['lines']['subject_type'] === 40 && str_contains($pm['prompt_template'], "SUBJECT TYPE: event\nSUBJECT TYPE: type_aa\nSUBJECT TYPE: type_ab"), 'subject types: the template\'s order, then the terms\' ascending; at most 40');

echo "6. Vocabulary\n";
ok(strpos($p2['prompt_template'], 'TERM: Merrow') < strpos($p2['prompt_template'], 'TERM: Pump Housing') && strpos($p2['prompt_template'], 'TERM: Quillon') < strpos($p2['prompt_template'], 'TERM: Zorblat'), 'TERM lines in canonical order');
$self = kn_compile_vocabulary_from([['id' => 1, 'canonical_name' => 'Zorblat Pump', 'aliases' => ['zorblat pump', 'ZP', 'zp'], 'subject_type' => null, 'description' => str_repeat('word ', 60)]]);
ok($self['terms'][0]['aliases'] === ['ZP'] && $self['terms'][0]['subject_type'] === 'other', 'the self-alias skipped, aliases de-duplicated, no type → other');
$long = kn_compile_prompt_from(['terms' => [['id' => 1, 'canonical_name' => 'Long', 'aliases' => [], 'subject_type' => 'term', 'description' => str_repeat('alphabet ', 40)]]] + $ex1);
preg_match('/^TERM: Long \(term\) — (.*)$/m', $long['prompt_template'], $m);
ok(isset($m[1]) && mb_strlen($m[1]) <= 160 && str_ends_with($m[1], '…') && !str_contains($m[1], 'alphab…'), 'a description cut at 160 on a word boundary with …');
$v2 = kn_compile_vocabulary_from($ex2['terms']);
ok(array_keys($v2['terms'][0]) === ['canonical_name', 'aliases', 'subject_type', 'description'] && $v2['terms'][0]['canonical_name'] === 'Merrow Filter' && isset($v2['sha256']), 'kn_compile_vocabulary_from() gives kn_engine_seed_subjects()\'s shape');

echo "7. Caps\n";
$big = $ex1;
$big['guidelines'][0]['body'] = implode(' ', array_map(static fn ($i) => "Extract the item number $i and every one of its parts and their intervals.", range(1, 110)));
$pb = kn_compile_prompt_from($big);
preg_match_all('/^EXTRACT: .*$/m', $pb['prompt_template'], $em);
ok(mb_strlen(implode("\n", $em[0])) <= 6000 && ($pb['truncated']['extract'] ?? 0) > 0 && count($em[0]) + $pb['truncated']['extract'] === 110, '7,000+ characters of extraction → EXTRACT ≤ 6,000, whole lines dropped and counted (' . ($pb['truncated']['extract'] ?? 0) . ')');
$t600 = $ex1;
for ($i = 0; $i < 600; $i++) { $t600['terms'][] = ['id' => $i + 1, 'canonical_name' => sprintf('T%03d', $i), 'aliases' => ['A' . $i], 'subject_type' => null, 'description' => '']; }
$pt = kn_compile_prompt_from($t600);
ok($pt['lines']['term'] < 500 && $pt['lines']['alias'] === $pt['lines']['term'] && mb_strlen(implode("\n", preg_grep('/^(TERM|ALIAS): /', explode("\n", $pt['prompt_template'])))) <= 8000, '600 terms with an alias each → TERM + ALIAS ≤ 8,000, a dropped TERM taking its ALIAS lines (' . $pt['lines']['term'] . ' kept)');
$t600b = $ex1;
for ($i = 0; $i < 600; $i++) { $t600b['terms'][] = ['id' => $i + 1, 'canonical_name' => sprintf('T%03d', $i), 'aliases' => [], 'subject_type' => null, 'description' => '']; }
$pt2 = kn_compile_prompt_from($t600b);
ok($pt2['lines']['term'] === 500 && ($pt2['truncated']['term'] ?? 0) === 100, '600 short terms → exactly 500 TERM lines, 100 counted as dropped');
ok(mb_strlen(str_replace("\n\nText:\n{{chunk}}", '', $pt['prompt_template'])) <= 24000, 'the whole template ≤ 24,000 before the chunk');

echo "8. The answer system\n";
$noarea = kn_compile_answer_system_from($ex2);
ok(!str_contains($noarea['system'], 'FOCUS AREA'), 'no FOCUS AREA line without an area');
$huge = $ex2;
$huge['guidelines'][4]['body'] = str_repeat('Answer with care and quote the words. ', 240);
$sh9 = kn_compile_answer_system_from($huge, $warranty);
ok(str_contains($sh9['system'], '…') && $sh9['chars'] <= 8000 && ($sh9['truncated']['answering'] ?? 0) === 1, 'a 9,000-character answering guideline is cut with … and the total ≤ 8,000');

echo "9. Hints and projects\n";
$text = 'Replace the ZP-9 seal every 2,000 hours. The Merrow Filter is checked weekly. A claim needs the serial number.';
ok(kn_compile_section_hints($v2, ['warranty', 'claim'], $text) === ['Zorblat Pump', 'Merrow Filter', 'claim'], 'example 2\'s hints, in order');
ok(kn_compile_section_hints($v2, [], 'The QVX unit and the QV valve.') === ['Quillon Valve'] && kn_compile_section_hints($v2, [], 'The QVX unit.') === [], 'an alias matched on word boundaries only (QVX is not QV)');
$terms30 = [];
for ($i = 0; $i < 30; $i++) { $terms30[] = ['id' => $i, 'canonical_name' => 'Word' . $i, 'aliases' => [], 'subject_type' => null, 'description' => '']; }
ok(count(kn_compile_section_hints(kn_compile_vocabulary_from($terms30), [], implode(' ', array_map(static fn ($i) => 'Word' . $i, range(0, 29))))) === 20, '30 matching terms → 20');

echo "10. Against the engine\n";
if (getenv('MALUDB_KB_API_URL') === false || getenv('MALUDB_KB_API_URL') === '') {
    ok(false, 'the engine is not up (run through tests/phase3/slice3/run.sh)');
    finish();
}
$base = make_base(PRIYA, 'SMOKE Compile Base');
ok(kn_compile_connection()['code'] === 'model_not_configured' && kn_compile_send(pdo(), $base)['code'] === 'model_not_configured', 'kb:0 absent → kn_compile_send() model_not_configured, nothing sent');
ok(kn_compile_engine_sha('kb:' . $base) === null, '…the base\'s namespace holds nothing');
$secret = need('KN_PROOF_PROVIDER_SECRET');
$ref = kn_engine_config_namespace('kb:0', ['provider_name' => 'kb-fake', 'provider_kind' => 'local_http', 'model_alias' => 'kb-extract', 'model_identifier' => 'fake-model',
    'base_url' => need('KN_PROOF_FAKE_URL') . '/v1', 'embedding_model' => 'maludb-local-dev', 'secret_name' => 'knowledge_provider', 'token' => $secret, 'prompt_template' => "Reference.\n\nText:\n{{chunk}}"]);
ok($ref['ok'], 'kb:0 configured with the fake provider\'s connection and a secret (the owner\'s step)');
$sent = kn_compile_send(pdo(), $base);
$cfg = kn_engine_get_namespace_config('kb:' . $base);
ok($sent['ok'] && ($cfg['data']['config']['secret_ref'] ?? '') === 'knowledge_provider' && !str_contains((string) json_encode($cfg), $secret) && !str_contains((string) json_encode($sent), $secret), 'a base\'s namespace configured with the secret by name; no response carries the credential');
ok(kn_compile_engine_sha('kb:' . $base) === kn_compile_prompt(pdo(), $base)['sha256'] && $sent['sha256'] === kn_compile_prompt(pdo(), $base)['sha256'], 'kn_compile_engine_sha() equals the compiled hash');
$e = kn_compile_ensure(pdo(), $base);
ok($e['ok'] && $e['sent'] === false, 'kn_compile_ensure() sends nothing the second time');
pdo()->exec("SELECT set_config('app.member_id', '" . PRIYA . "', false)");
pdo()->exec("INSERT INTO guidelines (base_id, kind, title, body, position) VALUES ($base, 'exclusion', 'No salary', 'salary', 9)   -- the fake provider reads an EXCLUDE line as one word");
pdo()->exec("INSERT INTO vocabulary_terms (base_id, canonical_name, aliases, subject_type) VALUES ($base, 'Zorblat Pump', '{ZP-9}', 'equipment')");
$e = kn_compile_ensure(pdo(), $base);
ok($e['ok'] && $e['sent'] === true && kn_compile_engine_sha('kb:' . $base) === $e['sha256'], '…and sends after a guideline save');
$fake = need('KN_PROOF_FAKE_URL');
@file_get_contents($fake . '/reset', false, stream_context_create(['http' => ['method' => 'POST']]));
$r = kn_engine_ingest_section('kb:' . $base, 'Pump note', 'ZP-9 needs a seal every year. Payroll Office pays the salary monthly.', ['base' => $base]);
$edges = $r['data']['edges'] ?? [];
$last = json_decode((string) @file_get_contents($fake . '/last'), true);
$types = array_values(array_unique(array_column($edges, 'subject_type')));
ok($r['ok'] && in_array('Zorblat Pump', array_column($edges, 'subject_text'), true) && !in_array('ZP-9', array_column($edges, 'subject_text'), true), 'the provider obeys the compiled ALIAS line (ZP-9 extracted as Zorblat Pump)');
ok(!in_array('Payroll Office', array_column($edges, 'subject_text'), true), '…and the EXCLUDE line (the salary sentence left out)');
$req = json_encode($last['extract'] ?? []);
ok(str_contains($req, 'ALIAS: ZP-9 => Zorblat Pump') && str_contains($req, 'Text:\\nZP-9 needs a seal'), 'its last request carries the compiled text with the section after Text:');
finish();
