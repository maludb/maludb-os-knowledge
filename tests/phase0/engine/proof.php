<?php
declare(strict_types=1);

/**
 * The ENGINE proof — drives ONLY app/knowledge/engine.php against the real MaluDB API (0.4.0, the M1 worktree) on a
 * scratch tenant, with a fake model provider. Run by tests/phase0/engine.sh, which sets:
 *   KN_PROOF_API_URL, KN_PROOF_OLD_API_URL (API 0.3.1, no answer route), KN_PROOF_FAKE_URL, KN_PROOF_TOKEN,
 *   KN_PROOF_DB, KN_PROOF_ROLE.
 * Synthetic text only. Raw SQL on the scratch database is used for three things the API cannot do — standing in for a
 * community detector, counting orphaned project links, and rewinding the subject sequence to reproduce id reuse — and
 * never to make engine.php's checks pass.
 */

require __DIR__ . '/../../../app/knowledge/engine.php';

$API = getenv('KN_PROOF_API_URL') ?: exit("KN_PROOF_API_URL missing\n");
$OLD = getenv('KN_PROOF_OLD_API_URL') ?: exit("KN_PROOF_OLD_API_URL missing\n");
$FAKE = getenv('KN_PROOF_FAKE_URL') ?: exit("KN_PROOF_FAKE_URL missing\n");
$TOKEN = getenv('KN_PROOF_TOKEN') ?: exit("KN_PROOF_TOKEN missing\n");
$DB = getenv('KN_PROOF_DB') ?: exit("KN_PROOF_DB missing\n");
$ROLE = getenv('KN_PROOF_ROLE') ?: exit("KN_PROOF_ROLE missing\n");
$PROVIDER_SECRET = 'fake-provider-credential-' . bin2hex(random_bytes(6));

$pass = 0;
$fail = 0;
$ALL = [];            // every structure engine.php returned, scanned for the token at the end
$NOTES = [];

function check(string $name, bool $ok, mixed $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS $name\n";
    } else {
        $fail++;
        echo "FAIL $name  -- " . mb_substr(is_string($detail) ? $detail : (string) json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 700) . "\n";
    }
}

function note(string $text): void
{
    global $NOTES;
    $NOTES[] = $text;
    echo "NOTE $text\n";
}

/** Keeps every result for the final token scan. */
function r(array $result): array
{
    global $ALL;
    $ALL[] = $result;
    return $result;
}

function use_engine(?string $url, ?string $token, ?string $timeout = null): void
{
    putenv('MALUDB_KB_API_URL' . ($url === null ? '' : '=' . $url));
    putenv('MALUDB_KB_API_TOKEN' . ($token === null ? '' : '=' . $token));
    putenv('MALUDB_KB_API_TIMEOUT' . ($timeout === null ? '' : '=' . $timeout));
}

function fake(string $path, string $method = 'GET'): array
{
    global $FAKE;
    $ch = curl_init($FAKE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 5]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return json_decode((string) $raw, true) ?: [];
}

/** SQL on the scratch tenant, as its role (see the header for the three uses). */
function scratch_sql(string $sql, bool $asRole = true): string
{
    global $DB, $ROLE;
    $full = ($asRole ? "SET ROLE $ROLE; SET search_path TO $ROLE, maludb_core, public; " : '') . $sql;
    $out = shell_exec('sudo -n -u postgres psql -v ON_ERROR_STOP=1 -qtA -d ' . escapeshellarg($DB) . ' -c ' . escapeshellarg($full) . ' 2>&1');
    return trim((string) $out);
}

function texts(array $evidence): string
{
    return implode("\n", array_map(fn ($e) => (string) ($e['text'] ?? ''), $evidence));
}

function sorted_desc(array $v): array
{
    rsort($v);
    return $v;
}

// =====================================================================================================================
echo "-- availability, and no configuration\n";
use_engine(null, null);
$r = r(kn_engine_available());
check('no URL/token: available answers engine_unavailable', !$r['ok'] && $r['code'] === 'engine_unavailable' && $r['status'] === 0, $r);
$r = r(kn_engine_answer('kb:1', 'Anything?'));
check('no URL/token: answer answers engine_unavailable, no throw', !$r['ok'] && $r['code'] === 'engine_unavailable', $r);
use_engine($API, null);
$r = r(kn_engine_search('Anything?', 'kb:1'));
check('a URL and no token: engine_unavailable', !$r['ok'] && $r['code'] === 'engine_unavailable', $r);
use_engine(null, $TOKEN);
$r = r(kn_engine_ingest_section('kb:1', 'T', 'Some text.'));
check('a token and no URL: engine_unavailable', !$r['ok'] && $r['code'] === 'engine_unavailable', $r);
check('kn_engine_error_message gives user-safe words', str_contains(kn_engine_error_message('engine_unavailable'), 'not available') && kn_engine_error_message(null) === '');

use_engine($API, $TOKEN);
$r = r(kn_engine_available());
check('configured: available', $r['ok'] && ($r['data']['health'] ?? '') === 'ok', $r);
$r = r(kn_engine_whoami());
check('whoami answers the principal report', $r['ok'] && array_key_exists('restricted', $r['data']), $r);

// =====================================================================================================================
echo "-- argument validation (no call is made)\n";
$before = fake('/calls');
foreach ([
    ['answer: a namespace that is not kb:<id>', kn_engine_answer('default', 'Q?')],
    ['answer: kb:x', kn_engine_answer('kb:x', 'Q?')],
    ['answer: an empty question', kn_engine_answer('kb:1', '   ')],
    ['answer: a 2,001-character question', kn_engine_answer('kb:1', str_repeat('a', 2001))],
    ['answer: project id 0', kn_engine_answer('kb:1', 'Q?', 0)],
    ['answer: bad history turn', kn_engine_answer('kb:1', 'Q?', null, [['role' => 'system', 'content' => 'x']])],
    ['answer: min_similarity 2', kn_engine_answer('kb:1', 'Q?', null, [], '', 12, 2.0)],
    ['ingest: empty text', kn_engine_ingest_section('kb:1', 'Title', '')],
    ['ingest: list metadata', kn_engine_ingest_section('kb:1', 'Title', 'Text.', ['a', 'b'])],
    ['config: template without {{chunk}}', kn_engine_config_namespace('kb:1', ['provider_name' => 'p', 'provider_kind' => 'local_http', 'model_alias' => 'a', 'model_identifier' => 'm', 'base_url' => 'http://x', 'embedding_model' => 'e', 'prompt_template' => 'no placeholder'])],
    ['config: missing base_url', kn_engine_config_namespace('kb:1', ['provider_name' => 'p', 'provider_kind' => 'local_http', 'model_alias' => 'a', 'model_identifier' => 'm', 'embedding_model' => 'e'])],
    ['neighbors: a kind that is not a-z_', kn_engine_neighbors('subject; DROP', 1)],
    ['path: an end without an id', kn_engine_path(['subject', 0], ['subject', 2])],
    ['project link: a project linked to itself', kn_engine_project_link_subject(5, 5)],
    ['forget: document 0', kn_engine_forget_document(0)],
    ['seed: no terms', kn_engine_seed_subjects([])],
    ['seed: a term without a name', kn_engine_seed_subjects([['aliases' => ['x']]])],
] as [$label, $res]) {
    r($res);
    check("invalid_argument — $label", !$res['ok'] && $res['code'] === 'invalid_argument' && $res['status'] === 0, $res);
}
check('validation made no model call', fake('/calls') == $before, fake('/calls'));

// =====================================================================================================================
echo "-- two bases, two namespace configurations (the compiled guidelines)\n";
$base = ['provider_name' => 'kb-fake', 'provider_kind' => 'local_http', 'model_alias' => 'kb-extract', 'model_identifier' => 'fake-model',
    'base_url' => $FAKE . '/v1', 'embedding_model' => 'maludb-local-dev', 'secret_name' => 'knowledge_provider', 'token' => $PROVIDER_SECRET];
$tpl1 = "KNOWLEDGE BASE kb:1 — extraction (compiled)\nSUBJECT TYPE: equipment\nALIAS: ZP-9 => Zorblat Pump\nALIAS: QV => Quillon Valve\n"
    . "Return ONLY JSON of the form {\"candidate_edges\":[{\"subject_text\":\"\",\"subject_type\":\"\",\"verb_text\":\"\",\"predicate\":[],\"source_span\":\"\",\"confidence\":0.0}]}.\n\nText:\n{{chunk}}";
$tpl2 = "KNOWLEDGE BASE kb:2 — extraction (compiled)\nSUBJECT TYPE: process\nEXCLUDE: salary\n"
    . "Return ONLY JSON of the form {\"candidate_edges\":[...]}.\n\nText:\n{{chunk}}";
$c1 = r(kn_engine_config_namespace('kb:1', $base + ['prompt_template' => $tpl1]));
check('kb:1 configured', $c1['ok'] && ($c1['data']['config']['namespace'] ?? '') === 'kb:1' && str_contains($c1['data']['config']['prompt_template'] ?? '', 'SUBJECT TYPE: equipment'), $c1);
$c2 = r(kn_engine_config_namespace('kb:2', $base + ['prompt_template' => $tpl2]));
check('kb:2 configured with its own prompt', $c2['ok'] && str_contains($c2['data']['config']['prompt_template'] ?? '', 'EXCLUDE: salary'), $c2);
check('the configuration names the secret, never the credential', ($c1['data']['config']['secret_ref'] ?? '') === 'knowledge_provider' && !str_contains((string) json_encode($c1), $PROVIDER_SECRET), $c1);
$g = r(kn_engine_get_namespace_config('kb:1'));
check('the configuration reads back', $g['ok'] && ($g['data']['config']['model_identifier'] ?? '') === 'fake-model', $g);

// =====================================================================================================================
echo "-- vocabulary: terms seeded as subjects, aliases\n";
$terms = [
    ['canonical_name' => 'Zorblat Pump', 'aliases' => ['ZP-9', 'the pump'], 'subject_type' => 'equipment', 'description' => 'The circulation pump.'],
    ['canonical_name' => 'Quillon Valve', 'aliases' => ['QV'], 'subject_type' => 'equipment'],
    ['canonical_name' => 'Merrow Filter', 'subject_type' => 'equipment'],
];
$extractBefore = fake('/calls')['extract'] ?? -1;
$s1 = r(kn_engine_seed_subjects($terms));
check('terms seeded', $s1['ok'] && count($s1['data']['subjects'] ?? []) === 3, $s1);
check('seeding called no model', (fake('/calls')['extract'] ?? -2) === $extractBefore, fake('/calls'));
$ids = array_column($s1['data']['subjects'] ?? [], 'subject_id', 'canonical_name');
check('the alias map names one canonical term per alias', ($s1['data']['aliases'] ?? []) === ['ZP-9' => 'Zorblat Pump', 'the pump' => 'Zorblat Pump', 'QV' => 'Quillon Valve'], $s1['data']['aliases'] ?? null);
check('the engine cannot store aliases (reported as such)', ($s1['data']['aliases_stored_in_engine'] ?? null) === false);
$s2 = r(kn_engine_seed_subjects([['description' => 'The circulation pump, revised.'] + $terms[0]]));
check('seeding again is idempotent: the same subject id', $s2['ok'] && ($s2['data']['subjects'][0]['subject_id'] ?? 0) === ($ids['Zorblat Pump'] ?? -1), $s2);
$f = r(kn_engine_find_subject('Zorblat Pump'));
check('the term is updated, not duplicated (type, description, aliases kept in it)', $f['ok'] && ($f['data']['subject']['type'] ?? '') === 'equipment' && str_contains($f['data']['subject']['description'] ?? '', 'revised') && str_contains($f['data']['subject']['description'] ?? '', 'Also called: ZP-9'), $f);
$all = r(kn_engine_request('GET', '/v1/subjects', ['label' => 'eq.Zorblat Pump']));
check('exactly one subject carries the canonical name', count($all['data']['subjects'] ?? []) === 1, $all);
$docsSeed = r(kn_engine_request('GET', '/v1/documents', ['document_type' => 'eq.' . KN_ENGINE_SEED_NAMESPACE, 'select' => 'id']));
check('the seed document was forgotten — nothing of it remains', $docsSeed['ok'] && ($docsSeed['data']['documents'] ?? null) === [], $docsSeed);

// =====================================================================================================================
echo "-- sections as documents\n";
$statsBefore = r(kn_engine_stats('kb:1'));
check('stats before the build', $statsBefore['ok'] && isset($statsBefore['data']['stats']['edges']) && ($statsBefore['data']['documents'] ?? -1) === 0, $statsBefore);
$sections = [
    ['Pump Manual › Calibration', "Zorblat Pump requires quarterly calibration by a certified technician. ZP-9 needs a filter change every 90 days. Merrow Filter traps particles larger than five microns.", ['base' => 1, 'source' => 11, 'section' => 101, 'page' => 3]],
    ['Pump Manual › Valves', "Quillon Valve controls the inlet pressure of the Zorblat Pump. QV opens at forty kilopascals. Merrow Filter sits upstream of the Quillon Valve.", ['base' => 1, 'source' => 11, 'section' => 102, 'page' => 4]],
    ['Pump Manual › Warranty', "Zorblat Pump carries a warranty of 24 months from installation. Quillon Valve carries a warranty of 12 months. Merrow Filter is a consumable and carries no warranty.", ['base' => 1, 'source' => 11, 'section' => 103, 'page' => 7]],
    ['Pump Manual › Safety', "Zorblat Pump must be isolated before any maintenance. Quillon Valve must never be forced past its stop. Merrow Filter must be replaced with gloves.", ['base' => 1, 'source' => 12, 'section' => 104, 'page' => 1]],
];
$docIds = [];
foreach ($sections as $i => [$title, $text, $meta]) {
    $hints = $i === 0 ? [['subject-type' => 'equipment', 'subject-name' => 'Merrow Filter']] : [];
    $res = r(kn_engine_ingest_section('kb:1', $title, $text, $meta, [], $hints));
    check('section ' . ($i + 1) . ' ingested as a document', $res['ok'] && $res['status'] === 201 && ($res['data']['document_id'] ?? 0) > 0 && ($res['data']['namespace'] ?? '') === 'kb:1', $res);
    $docIds[$meta['section']] = (int) ($res['data']['document_id'] ?? 0);
    if ($i === 0) {
        $edges = $res['data']['edges'] ?? [];
        check('kb:1 extracts with its own prompt (subject type equipment)', $edges && array_values(array_unique(array_column($edges, 'subject_type'))) === ['equipment'], $edges);
        check('an alias in the text is extracted as the canonical subject', in_array('Zorblat Pump', array_column($edges, 'subject_text'), true) && !in_array('ZP-9', array_column($edges, 'subject_text'), true), $edges);
        check('the documents route reports no usage (M2 not on this route)', !array_key_exists('usage', $res['data']), array_keys($res['data']));
    }
}
$noAlias = r(kn_engine_find_subject('ZP-9'));
check('no subject was created for the alias', $noAlias['ok'] && $noAlias['data']['subject'] === null, $noAlias);
$d = r(kn_engine_document($docIds[101]));
check('a document reads back with its title and type', $d['ok'] && ($d['data']['document']['title'] ?? '') === 'Pump Manual › Calibration' && ($d['data']['document']['document_type'] ?? '') === 'kb:1', $d);
$tagValues = array_column($d['data']['document']['tags'] ?? [], 'tag_value');
check('a hint became the document\'s subject tag', in_array('Merrow Filter', $tagValues, true), $d['data']['document']['tags'] ?? null);
$list = r(kn_engine_documents('kb:1'));
check('the base lists its four documents', $list['ok'] && ($list['data']['_total'] ?? 0) === 4 && count($list['data']['documents'] ?? []) === 4, $list);

// kb:2 — another base, another prompt
$p1 = r(kn_engine_ingest_section('kb:2', 'Staff Handbook › Travel', "Travel Allowance covers rail fares in second class. Travel Allowance excludes taxis after midnight. Payroll Office pays the salary on the last working day.", ['base' => 2, 'source' => 21, 'section' => 201, 'page' => 2]));
$e2 = $p1['data']['edges'] ?? [];
check('kb:2 extracts differently: its own subject type', $p1['ok'] && $e2 && array_values(array_unique(array_column($e2, 'subject_type'))) === ['process'], $p1);
check('kb:2\'s exclusion guideline held (the salary sentence was not extracted)', $e2 && !in_array('Payroll Office', array_column($e2, 'subject_text'), true) && count($e2) === 2, $e2);
$docIds[201] = (int) ($p1['data']['document_id'] ?? 0);
$p2 = r(kn_engine_ingest_section('kb:2', 'Staff Handbook › Remote work', "Remote Work Policy allows three days a week at home. Remote Work Policy requires a manager's approval.", ['base' => 2, 'source' => 21, 'section' => 202, 'page' => 5]));
check('a second kb:2 section ingested', $p2['ok'], $p2);

$statsAfter = r(kn_engine_stats('kb:1'));
check('graph stats moved after the build', $statsAfter['ok'] && $statsAfter['data']['stats']['edges'] > $statsBefore['data']['stats']['edges'] && $statsAfter['data']['stats']['nodes'] > $statsBefore['data']['stats']['nodes'], [$statsBefore['data'] ?? null, $statsAfter['data'] ?? null]);
check('the base\'s own document count is 4 (kb:2\'s are not counted)', ($statsAfter['data']['documents'] ?? -1) === 4, $statsAfter['data'] ?? null);

// =====================================================================================================================
echo "-- preview\n";
$callsBefore = fake('/calls');
$statsPre = r(kn_engine_stats('kb:1'));
$pv = r(kn_engine_preview('kb:1', 'Zorblat Pump hums at night.', [['subject-type' => 'equipment', 'subject-name' => 'Zorblat Pump']]));
check('preview answers the would-be extraction request', $pv['ok'] && str_contains($pv['data']['user_message'] ?? '', 'Zorblat Pump hums at night.') && str_contains($pv['data']['user_message'] ?? '', '"subject-name": "Zorblat Pump"'), $pv);
check('preview carries the base\'s own documents prompt with the text in it', str_contains($pv['data']['documents_prompt'] ?? '', 'SUBJECT TYPE: equipment') && str_contains($pv['data']['documents_prompt'] ?? '', 'hums at night'), $pv['data']['documents_prompt'] ?? null);
$statsPost = r(kn_engine_stats('kb:1'));
check('preview wrote no graph and no document', ($statsPost['data']['stats'] ?? 1) == ($statsPre['data']['stats'] ?? 2) && ($statsPost['data']['documents'] ?? -1) === 4, [$statsPre['data'] ?? null, $statsPost['data'] ?? null]);
check('preview called no model', fake('/calls') == $callsBefore, fake('/calls'));
note('MaluDB preview (/v1/memory/ingest preview:true) calls no model and ignores the namespace prompt_template: it shows the generic ingest prompt, not what the guidelines would extract.');

// =====================================================================================================================
echo "-- the graph: query, neighbours, path, god nodes, communities, surprises\n";
$kb1Docs = array_flip(array_filter([$docIds[101], $docIds[102], $docIds[103], $docIds[104]]));
$gq = r(kn_engine_graph_query('Zorblat Pump warranty', 'kb:1'));
$nodeDocs = array_map(fn ($n) => (int) $n['object_id'], array_filter($gq['data']['nodes'] ?? [], fn ($n) => $n['object_kind'] === 'document'));
check('graph query on kb:1 finds the pump and kb:1 documents', $gq['ok'] && in_array('Zorblat Pump', array_column($gq['data']['nodes'] ?? [], 'label'), true) && $nodeDocs && !array_diff($nodeDocs, array_keys($kb1Docs)), $gq);
$gq2 = r(kn_engine_graph_query('Zorblat Pump warranty', 'kb:2'));
check('graph query on kb:2 shows nothing of kb:1', $gq2['ok'] && ($gq2['data']['nodes'] ?? null) === [], $gq2);
$nb = r(kn_engine_neighbors('subject', $ids['Zorblat Pump'] ?? 0));
check('neighbours of the pump include its sections', $nb['ok'] && in_array('document', array_column($nb['data']['neighbors'] ?? [], 'neighbor_kind'), true), $nb);
$path = r(kn_engine_path(['subject', $ids['Zorblat Pump'] ?? 0], ['subject', $ids['Quillon Valve'] ?? 0]));
check('a path joins the pump and the valve', $path['ok'] && !empty($path['data']['path'] ?? $path['data']), $path);
$gn = r(kn_engine_god_nodes('kb:1', 10));
$gnDocs = array_map(fn ($n) => (int) $n['object_id'], array_filter($gn['data']['god_nodes'] ?? [], fn ($n) => $n['object_kind'] === 'document'));
check('god nodes of kb:1: its own documents only', $gn['ok'] && $gnDocs && !array_diff($gnDocs, array_keys($kb1Docs)), $gn);
$gn2 = r(kn_engine_god_nodes('kb:2', 10));
check('god nodes of kb:2 hold no kb:1 document', $gn2['ok'] && !array_intersect(array_map(fn ($n) => (int) $n['object_id'], array_filter($gn2['data']['god_nodes'] ?? [], fn ($n) => $n['object_kind'] === 'document')), array_keys($kb1Docs)), $gn2);
$cm = r(kn_engine_communities('kb:1'));
check('communities read (MaluDB computes none from documents)', $cm['ok'] && ($cm['data']['communities'] ?? null) === [], $cm);
note('No community detection runs over ingested documents: communities exist only when written (graph/import or maludb_community_replace).');
// standing in for the missing detector: two communities written for kb:1 through the tenant's own facade
$rep = scratch_sql("SELECT maludb_community_replace('kb:1', 'louvain', '[{\"key\":0,\"label\":\"Pump and filter\",\"members\":[\"Zorblat Pump\",\"Merrow Filter\"]},{\"key\":1,\"label\":\"Valve\",\"members\":[\"Quillon Valve\"]}]'::jsonb)");
$cm = r(kn_engine_communities('kb:1'));
$byLabel = array_column($cm['data']['communities'] ?? [], null, 'label');
check('communities exist once written, with sizes', $cm['ok'] && count($cm['data']['communities'] ?? []) === 2 && (int) ($byLabel['Pump and filter']['member_count'] ?? 0) === 2, [$rep, $cm]);
$cmid = (int) ($byLabel['Pump and filter']['community_id'] ?? 0);
$mem = r(kn_engine_community_members($cmid));
check('a community\'s members carry their labels', $mem['ok'] && in_array('Zorblat Pump', array_column($mem['data']['members'] ?? [], 'label'), true), $mem);
$cm2 = r(kn_engine_communities('kb:2'));
check('communities are per namespace (kb:2 has none)', $cm2['ok'] && ($cm2['data']['communities'] ?? null) === [], $cm2);
$sp = r(kn_engine_surprises('kb:1'));
check('surprises read for kb:1', $sp['ok'] && is_array($sp['data']['surprises'] ?? null), $sp);

// =====================================================================================================================
echo "-- answering (M1)\n";
fake('/reset', 'POST');
$sys = 'Answer in one sentence for a field technician. Say when the sources disagree.';
$a = r(kn_engine_answer('kb:1', 'How often does the Zorblat Pump need calibration?', null, [['role' => 'user', 'content' => 'We are servicing the plant room today.'], ['role' => 'assistant', 'content' => 'Understood.']], $sys, 12, -1.0));
check('answered', $a['ok'] && ($a['data']['status'] ?? '') === 'answered' && is_string($a['data']['answer_md'] ?? null), $a);
$cit = $a['data']['citations'][0] ?? [];
check('the citation is verified by the engine', ($cit['verified'] ?? false) === true && str_contains($cit['quote'] ?? '', 'quarterly calibration'), $cit);
check('the citation names the calibration section\'s document', (int) ($cit['document_id'] ?? 0) === $docIds[101], [$cit, $docIds[101]]);
$evByN = array_column($a['data']['evidence'] ?? [], null, 'n');
check('the cited evidence maps back to the section by its metadata', ($evByN[$cit['n'] ?? 0]['document']['metadata']['section'] ?? null) === 101 && ($evByN[$cit['n'] ?? 0]['document']['metadata']['page'] ?? null) === 3, $evByN[$cit['n'] ?? 0] ?? null);
check('usage is reported on the answer', ($a['data']['usage'] ?? null) === ['input_tokens' => 321, 'output_tokens' => 45, 'calls' => 1], $a['data']['usage'] ?? null);
$last = fake('/last')['answer'] ?? [];
check('history is carried to the model', str_contains($last['user'] ?? '', 'CONVERSATION') && str_contains($last['user'] ?? '', 'servicing the plant room'), $last['user'] ?? null);
check('the base\'s answering guidelines are the system prompt', str_starts_with($last['system'] ?? '', $sys), mb_substr($last['system'] ?? '', 0, 200));
check('the namespace configuration supplied the model (no tenant task choice needed)', ($a['data']['model'] ?? '') === 'fake-model' && ($last['model'] ?? '') === 'fake-model', [$a['data']['model'] ?? null, $last['model'] ?? null]);

$n = fake('/calls')['answer'] ?? -1;
$u = r(kn_engine_answer('kb:1', 'How often does the Zorblat Pump need calibration? FABRICATE', null, [], '', 12, -1.0));
check('a fabricated quote is withheld: unverified, no answer, two calls', $u['ok'] && ($u['data']['status'] ?? '') === 'unverified' && array_key_exists('answer_md', $u['data']) && $u['data']['answer_md'] === null && ($u['data']['usage']['calls'] ?? 0) === 2 && (fake('/calls')['answer'] ?? 0) === $n + 2, $u);
check('the withheld answer still returns its evidence', !empty($u['data']['evidence']), $u['data'] ?? null);
$n = fake('/calls')['answer'] ?? -1;
$ne = r(kn_engine_answer('kb:1', 'What colour are the Xylophone zebras of Plimbo?', null, [], '', 12, -1.0));
check('an unknown question: no_evidence', $ne['ok'] && ($ne['data']['status'] ?? '') === 'no_evidence' && ($ne['data']['evidence'] ?? null) === [], $ne);
check('no_evidence called no model', (fake('/calls')['answer'] ?? -2) === $n && ($ne['data']['usage']['calls'] ?? -1) === 0, [fake('/calls'), $ne['data']['usage'] ?? null]);
$ins = r(kn_engine_answer('kb:1', 'How often does the Zorblat Pump need calibration? NOEVIDENCE', null, [], '', 12, -1.0));
check('a model that finds the evidence insufficient: no_evidence', $ins['ok'] && ($ins['data']['status'] ?? '') === 'no_evidence', $ins);
$exp = kn_engine_expand_aliases('Is the ZP-9 under warranty?', $s1['data']['aliases'] ?? []);
check('an alias in a question names its canonical term', $exp === 'Is the ZP-9 (Zorblat Pump) under warranty?', $exp);
$al = r(kn_engine_answer('kb:1', $exp, null, [], '', 12, -1.0));
check('a question asked by alias is answered from the canonical subject', $al['ok'] && ($al['data']['status'] ?? '') === 'answered' && in_array('Zorblat Pump', $al['data']['subjects_tried'] ?? [], true), $al);
$iso = r(kn_engine_answer('kb:2', 'How often does the Zorblat Pump need calibration?', null, [], '', 12, -1.0));
check('namespaces isolate: kb:2 never sees kb:1\'s text', $iso['ok'] && ($iso['data']['status'] ?? '') === 'no_evidence' && !str_contains(texts($iso['data']['evidence'] ?? []), 'Zorblat'), $iso);
$a2 = r(kn_engine_answer('kb:2', 'What does the Travel Allowance cover?', null, [], '', 12, -1.0));
check('kb:2 answers from its own text', $a2['ok'] && ($a2['data']['status'] ?? '') === 'answered' && !str_contains(texts($a2['data']['evidence'] ?? []), 'Zorblat'), $a2);
$rc = r(kn_engine_recall('Zorblat Pump calibration', ['kb:2']));
check('recall in kb:2 returns none of kb:1\'s chunks', $rc['ok'] && ($rc['data']['results'] ?? null) === [], $rc);
$rc1 = r(kn_engine_recall('Zorblat Pump calibration', ['kb:1']));
check('recall in kb:1 returns kb:1\'s chunks', $rc1['ok'] && str_contains(implode("\n", array_column($rc1['data']['results'] ?? [], 'source_text')), 'quarterly calibration'), $rc1);

// =====================================================================================================================
echo "-- focus areas = projects\n";
$pa = r(kn_engine_project_create('kb:1', 'Valves', 'Valve pressure and stops.', "# Valves\nPressure, stops, warranty of valves."));
$pid = (int) ($pa['data']['project_id'] ?? 0);
check('a focus area becomes a project', $pa['ok'] && $pid > 0 && ($pa['data']['name'] ?? '') === 'kb:1/Valves', $pa);
$ln = r(kn_engine_project_link_subject($pid, $ids['Quillon Valve'] ?? 0));
check('a seed term is linked to the project', $ln['ok'], $ln);
$ln2 = r(kn_engine_project_link_subject($pid, $ids['Quillon Valve'] ?? 0));
check('linking twice is not an error', $ln2['ok'] && ($ln2['data']['already'] ?? false) === true, $ln2);
$pd = r(kn_engine_project($pid));
check('the project reads back with its classifier and one subject', $pd['ok'] && str_contains($pd['data']['project']['classifier_md'] ?? '', 'Pressure, stops') && array_column($pd['data']['project']['subjects'] ?? [], 'name') === ['Quillon Valve'], $pd);
$both = 'Zorblat Pump and Quillon Valve: what warranty applies?';
$scoped = r(kn_engine_answer('kb:1', $both, $pid, [], '', 12, -1.0));
check('a project-scoped answer retrieves only the project\'s subjects', $scoped['ok'] && ($scoped['data']['subjects_tried'] ?? null) === ['Quillon Valve'] && !empty($scoped['data']['evidence']) && !array_diff(array_unique(array_column($scoped['data']['evidence'], 'subject')), ['Quillon Valve']), $scoped);
$unscoped = r(kn_engine_answer('kb:1', $both, null, [], '', 12, -1.0));
check('the same question unscoped retrieves both subjects', $unscoped['ok'] && count(array_intersect(['Zorblat Pump', 'Quillon Valve'], $unscoped['data']['subjects_tried'] ?? [])) === 2, $unscoped['data']['subjects_tried'] ?? null);
$byName = r(kn_engine_answer('kb:1', $both, 'kb:1/Valves', [], '', 12, -1.0));
check('a project can be named instead of numbered', $byName['ok'] && ($byName['data']['subjects_tried'] ?? null) === ['Quillon Valve'], $byName);
$pnf = r(kn_engine_answer('kb:1', 'Quillon Valve?', 999999, [], '', 12, -1.0));
check('an unknown project: project_not_found', !$pnf['ok'] && $pnf['code'] === 'project_not_found' && $pnf['status'] === 404, $pnf);
$pdel = r(kn_engine_project_delete($pid));
check('the project is deleted after unlinking its subject', $pdel['ok'] && ($pdel['data']['unlinked'] ?? 0) === 1, $pdel);
$gone = r(kn_engine_project($pid));
check('the deleted project is gone', !$gone['ok'] && $gone['code'] === 'not_found', $gone);
$orphans = scratch_sql("SELECT count(*) FROM maludb_svpor_relationship WHERE source_kind = 'subject' AND source_id = $pid");
check('no orphaned subject links remain', $orphans === '0', $orphans);

// The id-reuse defect, reproduced: a project deleted with the raw route leaves its links; a new project given the same
// id must not inherit them.
$px = r(kn_engine_project_create('kb:1', 'Filters', 'Filters.'));
$pxid = (int) ($px['data']['project_id'] ?? 0);
r(kn_engine_project_link_subject($pxid, $ids['Merrow Filter'] ?? 0));
$raw = r(kn_engine_request('DELETE', '/v1/projects/' . $pxid));
$left = scratch_sql("SELECT count(*) FROM maludb_svpor_relationship WHERE source_kind = 'subject' AND source_id = $pxid");
check('(defect reproduced) the raw DELETE /v1/projects/{id} leaves the link behind', $raw['ok'] && $left === '1', [$raw, $left]);
note("raw DELETE /v1/projects/$pxid left $left subject link(s) whose source no longer exists.");
$seq = scratch_sql("SELECT setval(pg_get_serial_sequence('maludb_core.\"malu\$svpor_subject\"', 'subject_id'), " . ($pxid - 1) . ', true)', false);
$py = r(kn_engine_project_create('kb:1', 'Filters again', 'Filters, re-made.'));
check('a project created on the reused id', $py['ok'] && (int) ($py['data']['project_id'] ?? 0) === $pxid, [$seq, $py, $pxid]);
check('the reused id\'s stale link was cleared', ($py['data']['cleared'] ?? 0) === 1, $py);
$pyd = r(kn_engine_project($pxid));
check('the re-made project carries no stale link', $pyd['ok'] && ($pyd['data']['project']['subjects'] ?? null) === [], $pyd);
r(kn_engine_project_delete($pxid));

// =====================================================================================================================
echo "-- the evidence fallback (an API without the answer route)\n";
use_engine($OLD, $TOKEN);
$fb = r(kn_engine_answer('kb:1', 'How often does the Zorblat Pump need calibration?', null, [], '', 12, -1.0));
check('API 0.3.1: answer_unavailable, not a crash', !$fb['ok'] && $fb['code'] === 'answer_unavailable' && $fb['status'] === 404, $fb);
$se = r(kn_engine_search('How often does the Zorblat Pump need calibration?', 'kb:1'));
check('the fallback search answers evidence only, from kb:1', $se['ok'] && ($se['data']['status'] ?? '') === 'evidence_only' && str_contains(texts($se['data']['evidence'] ?? []), 'quarterly calibration'), $se);
$chunks = array_column($se['data']['evidence'] ?? [], 'chunk_id');
$sims = array_column($se['data']['evidence'] ?? [], 'similarity');
check('evidence is deduplicated by chunk and ranked', $chunks !== [] && count($chunks) === count(array_unique($chunks)) && $sims === sorted_desc($sims), $se['data']['evidence'] ?? null);
check('evidence names its document (to map to a section)', ($se['data']['evidence'][0]['document_id'] ?? 0) > 0 && is_string($se['data']['evidence'][0]['document']['title'] ?? null), $se['data']['evidence'][0] ?? null);
$se2 = r(kn_engine_search('How often does the Zorblat Pump need calibration?', 'kb:2'));
check('the fallback search is namespace-isolated too', $se2['ok'] && !str_contains(texts($se2['data']['evidence'] ?? []), 'Zorblat'), $se2);
use_engine($API, $TOKEN);
$se3 = r(kn_engine_search('Is QV (Quillon Valve) forced past its stop?', 'kb:1', 3));
check('search caps its evidence', $se3['ok'] && count($se3['data']['evidence'] ?? []) <= 3 && count($se3['data']['evidence'] ?? []) > 0, $se3);

// =====================================================================================================================
echo "-- forgetting\n";
$fg = r(kn_engine_forget_document($docIds[101]));
check('a section is forgotten (document, chunks, statements)', $fg['ok'] && ($fg['data']['forgotten']['chunks'] ?? 0) > 0, $fg);
$nf = r(kn_engine_document($docIds[101]));
check('the forgotten document is not found', !$nf['ok'] && $nf['code'] === 'not_found' && $nf['status'] === 404, $nf);
$af = r(kn_engine_search('How often does the Zorblat Pump need calibration?', 'kb:1'));
check('its text is no longer retrievable by search', $af['ok'] && !str_contains(texts($af['data']['evidence'] ?? []), 'quarterly calibration'), $af);
$aa = r(kn_engine_answer('kb:1', 'How often does the Zorblat Pump need calibration?', null, [], '', 12, -1.0));
check('nor by the answer route', $aa['ok'] && !str_contains(texts($aa['data']['evidence'] ?? []) . json_encode($aa['data']['citations'] ?? []), 'quarterly calibration'), $aa);
$cnt = r(kn_engine_documents('kb:1'));
check('the base now holds three documents', ($cnt['data']['_total'] ?? -1) === 3, $cnt);
$fg2 = r(kn_engine_forget_document($docIds[101]));
check('forgetting twice: not_found', !$fg2['ok'] && $fg2['code'] === 'not_found', $fg2);

// =====================================================================================================================
echo "-- maintenance sweeps\n";
$ri = r(kn_engine_reindex(5));
check('reindex runs one batch', $ri['ok'] && isset($ri['data']['claimed']), $ri);
$skips = array_values(array_unique(array_column($ri['data']['skipped'] ?? [], 'reason')));
note('reindex claimed ' . ($ri['data']['claimed'] ?? 0) . ' document(s); skipped: ' . implode(', ', $skips ?: ['none']) . ' — it uses the tenant extract task, not the base prompt.');
$em = r(kn_engine_embeddings(5));
check('embeddings runs (a no-op without a real embedder)', $em['ok'] && array_key_exists('embedded', $em['data']), $em);

// =====================================================================================================================
echo "-- error mapping\n";
$bad = 'malu_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
use_engine($API, $bad);
$e401 = r(kn_engine_whoami());
check('a bad token: engine_auth 401', !$e401['ok'] && $e401['code'] === 'engine_auth' && $e401['status'] === 401, $e401);
check('the bad token is not echoed', !str_contains((string) json_encode($e401), $bad), $e401);
$av = r(kn_engine_available());
check('available with a bad token: engine_auth', !$av['ok'] && $av['code'] === 'engine_auth', $av);
use_engine($API, $TOKEN);
$e422 = r(kn_engine_ingest_section('kb:1', 'Lower case', 'nothing here starts with a capital letter. nor does this.', ['base' => 1]));
check('nothing extractable: 422 no_edges, passed through', !$e422['ok'] && $e422['status'] === 422 && $e422['code'] === 'no_edges' && ($e422['data']['engine_code'] ?? '') === 'no_edges', $e422);
$e502 = r(kn_engine_ingest_section('kb:1', 'Failing', 'Zorblat Pump FAILPROVIDER sentence.', ['base' => 1]));
check('a failing provider: 502 model_failed', !$e502['ok'] && $e502['status'] === 502 && $e502['code'] === 'model_failed', $e502);
$ebj = r(kn_engine_ingest_section('kb:1', 'Prose', 'Zorblat Pump BADJSON sentence.', ['base' => 1]));
check('a provider answering prose: 502 model_failed', !$ebj['ok'] && $ebj['status'] === 502 && $ebj['code'] === 'model_failed', $ebj);
$ea502 = r(kn_engine_answer('kb:1', 'Does the Quillon Valve open at forty kilopascals? FAILPROVIDER', null, [], '', 12, -1.0));
check('a failing provider at answer time: model_failed', !$ea502['ok'] && $ea502['code'] === 'model_failed', $ea502);
$enm = r(kn_engine_answer('kb:3', 'Zorblat Pump?', null, [], '', 12, -1.0));
check('an unconfigured namespace with no evidence: no_evidence, no model needed', $enm['ok'] && ($enm['data']['status'] ?? '') === 'no_evidence', $enm);
use_engine('http://127.0.0.1:8509', $TOKEN);
$eun = r(kn_engine_stats());
check('an unreachable API: engine_unavailable', !$eun['ok'] && $eun['code'] === 'engine_unavailable' && $eun['status'] === 0, $eun);
use_engine($FAKE . '/hang', $TOKEN, '2');
$t0 = microtime(true);
$eto = r(kn_engine_whoami());
$took = microtime(true) - $t0;
check('a hanging API: engine_timeout within the timeout', !$eto['ok'] && $eto['code'] === 'engine_timeout' && $took < 5, [$eto, $took]);
use_engine($FAKE, $TOKEN);
$e404 = r(kn_engine_available());
check('a URL that is not a MaluDB API: engine_unavailable', !$e404['ok'] && $e404['code'] === 'engine_unavailable', $e404);
use_engine($API, $TOKEN);
check('every failure has user-safe words', kn_engine_error_message('model_failed') !== '' && !str_contains(kn_engine_error_message('engine_auth'), 'malu_'));

// =====================================================================================================================
echo "-- the token never leaves the module\n";
$blob = (string) json_encode($ALL, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$rawToken = substr($TOKEN, 5);
check('no returned structure carries the MaluDB token (' . count($ALL) . ' results scanned)', !str_contains($blob, $TOKEN) && !str_contains($blob, $rawToken), 'token found');
check('no returned structure carries the provider credential', !str_contains($blob, $PROVIDER_SECRET), 'credential found');
check('no returned structure carries a token-shaped string', !preg_match('/malu_[A-Za-z0-9_\-]{20,}/', $blob), 'token-shaped string found');
$src = (string) file_get_contents(__DIR__ . '/../../../app/knowledge/engine.php');
check('engine.php writes no log line', !preg_match('/\b(error_log|syslog|file_put_contents|fwrite|print_r|var_dump)\s*\(/', $src));
$apps = trim((string) shell_exec('grep -rl "MALUDB_KB_API_TOKEN" ' . escapeshellarg(realpath(__DIR__ . '/../../../app')) . ' 2>/dev/null'));
check('engine.php is the only file in app/ that reads MALUDB_KB_API_TOKEN', $apps === realpath(__DIR__ . '/../../../app/knowledge/engine.php'), $apps);

echo "\n";
foreach ($NOTES as $nline) {
    echo "  note: $nline\n";
}
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
