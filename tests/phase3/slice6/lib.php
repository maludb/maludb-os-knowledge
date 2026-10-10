<?php
/**
 * Helpers for the slice 6 proofs (docs/build-specs/graph-explorer.md "Proof"). Run through tests/phase3/slice6/run.sh: a fresh SCRATCH database
 * (kn_dev6), the application on :8501, the fake kernel (:8502), THE FAKE ENGINE (:8503, tests/fake_kb_engine.php — $FAKE_KB_STATE drives it, its `graph`
 * key is the graph, and every request it gets is a line of $FAKE_KB_STATE.log), the fake MaluDB + MaluMail (:8506).
 * The cast (the spec's names → the fixture's): Priya (26, Member) reads; Cara → Marco (27, Curator) curates Pumps and owns Valves; Dee → Bea (28,
 * Member, no share to Valves); Gus → Ann (29, a guest, Reader on Pumps only); Reid (32, Reader); the admin (1); Seamus (40, an agent).
 * The world (graph_world()): "SMOKE Pumps" (internal; Marco's) with the source "Pump manual" cut into sections — "Zorblat Pump" (document 501, page 1),
 * "Merrow Filter" (502, p. 2), "Legacy Pumps" (503, p. 3), "Model ZP-9" (504, p. 4) built, the top "Pumps" section not — and the term "Zorblat Pump" ⇐ ZP-9
 * (unseeded); "SMOKE Valves" (private, Marco's) with "Quillon Valve" (document 601). The fake's graph (g_graph()): subjects Zorblat Pump 1 (501, 502),
 * Merrow Filter 2 (502), Quillon Valve 3 (601 only — Valves'), Zorblat Pumps 4 (503), ZP-9 5 (504); statements 9001 (501), 9003 (502), 9002 and 9004 (601).
 */
require dirname(__DIR__) . '/slice5/lib.php';

/** The log of the fake engine, cleared. */
function kb_clear(): void { file_put_contents(need('FAKE_KB_STATE') . '.log', ''); }
/** Requests the fake engine got, optionally only those to paths starting with $prefix. */
function kb_calls(string $prefix = '/v1/'): int
{
    return count(array_filter(kb_log(), static fn ($r) => $r['path'] !== '/health' && str_starts_with($r['path'], $prefix)));
}

function pump_text(): string
{
    $pad = str_repeat(' Check the seals every spring.', 8);
    return "# Pumps\n\nThe pump line of the works.$pad\n\n## Zorblat Pump\n\nThe Zorblat Pump is a centrifugal pump rated at forty bar.$pad\n\n## Merrow Filter\n\nThe Merrow Filter cleans what the pump moves.$pad\n\n"
         . "## Legacy Pumps\n\nOlder Zorblat Pumps were sold before the ZP-9.$pad\n\n## Model ZP-9\n\nThe ZP-9 is the shop name of the Zorblat Pump.$pad\n";
}

/** The world (idempotent): ['pumps', 'valves', 'zorblat' (section ids), 'merrow', 'legacy', 'zp9', 'top', 'quillon', 'source', 'valve_source']. */
function graph_world(): array
{
    $pumps = base_by_key('smoke-pumps');
    if ($pumps === null) {
        $c = as_member(MARCO);
        $pumps = make_base(MARCO, 'SMOKE Pumps', ['key' => 'smoke-pumps']);
        act($c, '/bases/visibility.php', ['base' => $pumps, 'visibility' => 'internal']);
        act($c, '/bases/members/add-guest.php', ['base' => $pumps, 'guest' => ANN]);
        act($c, '/sources/add.php', ['base' => $pumps, 'kind' => 'text', 'title' => 'Pump manual', 'text' => pump_text()]);
        act($c, '/vocabulary/save.php', ['base' => $pumps, 'canonical_name' => 'Zorblat Pump', 'aliases' => 'ZP-9', 'subject_type' => 'product']);
        $valves = make_base(MARCO, 'SMOKE Valves', ['key' => 'smoke-valves']);
        act($c, '/sources/add.php', ['base' => $valves, 'kind' => 'text', 'title' => 'Valve sheet', 'text' => "# Valves\n\nThe valves.\n\n## Quillon Valve\n\nThe Quillon Valve closes at twelve bar." . str_repeat(' Not for the pump people.', 8)]);
        pdo()->exec("UPDATE knowledge_bases SET auto_extract = false WHERE id IN ($pumps, $valves)");
        worker_pass();
        $sec = static fn (string $h, int $b) => (int) one('SELECT id FROM sections WHERE base_id = :b AND heading = :h ORDER BY id LIMIT 1', ['b' => $b, 'h' => $h]);
        $set = static fn (int $id, string $doc, int $page) => pdo()->prepare("UPDATE sections SET status = 'extracted', extracted_version = (SELECT guideline_version FROM knowledge_bases k WHERE k.id = sections.base_id),
            maludb_document_id = :d, page_no = :p WHERE id = :s")->execute(['d' => $doc, 'p' => $page, 's' => $id]);
        $set($sec('Zorblat Pump', $pumps), '501', 1);
        $set($sec('Merrow Filter', $pumps), '502', 2);
        $set($sec('Legacy Pumps', $pumps), '503', 3);
        $set($sec('Model ZP-9', $pumps), '504', 4);
        $set($sec('Quillon Valve', $valves), '601', 1);
        pdo()->exec("UPDATE knowledge_bases SET subjects_count = 4, statements_count = 3, communities_count = 0, documents_count = 4, last_built_at = now() - interval '1 day' WHERE id = $pumps");
        pdo()->exec("UPDATE knowledge_bases SET subjects_count = 1, statements_count = 2, communities_count = 0, documents_count = 1, last_built_at = now() - interval '1 day' WHERE id = $valves");
    }
    $valves = (int) base_by_key('smoke-valves');
    $sec = static fn (string $h, int $b) => (int) one('SELECT id FROM sections WHERE base_id = :b AND heading = :h ORDER BY id LIMIT 1', ['b' => $b, 'h' => $h]);
    $w = ['pumps' => $pumps, 'valves' => $valves, 'zorblat' => $sec('Zorblat Pump', $pumps), 'merrow' => $sec('Merrow Filter', $pumps), 'legacy' => $sec('Legacy Pumps', $pumps), 'zp9' => $sec('Model ZP-9', $pumps),
          'top' => $sec('Pumps', $pumps), 'quillon' => $sec('Quillon Valve', $valves), 'source' => (int) one("SELECT id FROM sources WHERE base_id = :b AND title = 'Pump manual'", ['b' => $pumps]),
          'valve_source' => (int) one("SELECT id FROM sources WHERE base_id = :b AND title = 'Valve sheet'", ['b' => $valves])];
    return $w;
}

/** The fake engine's graph for the world; $over replaces top-level keys of it. */
function g_graph(array $w, array $over = []): array
{
    $nb = [];
    $link = static function (string $ak, int $ai, string $bk, int $bi, ?string $rel = null) use (&$nb): void {
        $nb["$ak:$ai"][] = ['neighbor_kind' => $bk, 'neighbor_id' => $bi, 'rel' => $rel];
        $nb["$bk:$bi"][] = ['neighbor_kind' => $ak, 'neighbor_id' => $ai, 'rel' => $rel];
    };
    $link('subject', 1, 'document', 501, 'described_in');
    $link('subject', 1, 'document', 502, 'used_with');
    $link('subject', 1, 'subject', 2, 'uses');
    $link('subject', 1, 'subject', 3, 'connects_to');
    $link('subject', 1, 'statement', 9001, 'rated');
    $link('subject', 1, 'statement', 9003, 'uses');
    $link('subject', 1, 'statement', 9004, 'fits');
    $link('subject', 2, 'document', 502, 'described_in');
    $link('subject', 3, 'document', 601, 'described_in');
    $link('subject', 3, 'statement', 9002, 'closes');
    $link('subject', 4, 'document', 503, 'described_in');
    $link('subject', 5, 'document', 504, 'described_in');
    $link('statement', 9001, 'document', 501);
    $link('statement', 9003, 'document', 502);
    $link('statement', 9002, 'document', 601);
    $link('statement', 9004, 'document', 601);
    $graph = [
        'subjects' => ['1' => ['label' => 'Zorblat Pump', 'type' => 'product', 'description' => 'A centrifugal pump.'], '2' => ['label' => 'Merrow Filter', 'type' => 'product', 'description' => 'A pump filter.'],
                       '3' => ['label' => 'Quillon Valve', 'type' => 'product', 'description' => 'A valve.'], '4' => ['label' => 'Zorblat Pumps', 'type' => 'product'], '5' => ['label' => 'ZP-9', 'type' => 'product']],
        'labels' => ['statement:9001' => 'Zorblat Pump is rated at forty bar', 'statement:9003' => 'Zorblat Pump uses the Merrow Filter', 'statement:9002' => 'Quillon Valve closes at twelve bar', 'statement:9004' => 'Zorblat Pump fits a Quillon Valve'],
        'neighbors' => $nb,
        'god_nodes' => [
            ['object_kind' => 'subject', 'object_id' => 1, 'label' => 'Zorblat Pump', 'degree_out' => 5, 'degree_in' => 4, 'degree_total' => 9],
            ['object_kind' => 'document', 'object_id' => 601, 'label' => 'Document 601', 'degree_out' => 0, 'degree_in' => 5, 'degree_total' => 5],
            ['object_kind' => 'subject', 'object_id' => 3, 'label' => 'Quillon Valve', 'degree_out' => 2, 'degree_in' => 2, 'degree_total' => 4],
            ['object_kind' => 'subject', 'object_id' => 2, 'label' => 'Merrow Filter', 'degree_out' => 1, 'degree_in' => 2, 'degree_total' => 3],
            ['object_kind' => 'document', 'object_id' => 502, 'label' => 'Document 502', 'degree_out' => 0, 'degree_in' => 3, 'degree_total' => 3],
            ['object_kind' => 'document', 'object_id' => 501, 'label' => 'Document 501', 'degree_out' => 0, 'degree_in' => 2, 'degree_total' => 2],
            ['object_kind' => 'subject', 'object_id' => 4, 'label' => 'Zorblat Pumps', 'degree_out' => 1, 'degree_in' => 0, 'degree_total' => 1],
            ['object_kind' => 'subject', 'object_id' => 5, 'label' => 'ZP-9', 'degree_out' => 1, 'degree_in' => 0, 'degree_total' => 1],
        ],
        'surprises' => [], 'communities' => [], 'members' => [],
        'paths' => [
            ['from' => 'subject:1', 'to' => 'subject:2', 'depth' => 2, 'path' => ['subject:1', 'document:502', 'subject:2']],
            ['from' => 'subject:1', 'to' => 'subject:3', 'depth' => 2, 'path' => ['subject:1', 'document:601', 'subject:3']],
        ],
        'stats' => ['edges' => 98765, 'nodes' => 54321, 'by_store' => [], 'top_rels' => []],
    ];
    return array_replace($graph, $over);
}

/** Put the world's documents and graph into the fake (the graph's keys replaced by $over's). */
function g_set(array $w, array $over = []): void
{
    kb_state(['documents' => ['kb:' . $w['pumps'] => [501, 502, 503, 504], 'kb:' . $w['valves'] => [601]],
              'titles' => ['501' => 'Zorblat Pump', '502' => 'Merrow Filter', '503' => 'Legacy Pumps', '504' => 'Model ZP-9', '601' => 'Quillon Valve'], 'graph' => g_graph($w, $over)]);
}

/** The graph's cache directory emptied for a base (a proof that needs the engine called). */
function g_cache_clear(int $base): void
{
    foreach (glob(APP_ROOT . '/storage/cache/graph/' . $base . '/*') ?: [] as $f) { @unlink($f); }
}
function g_cache_files(int $base): array { return glob(APP_ROOT . '/storage/cache/graph/' . $base . '/*.json') ?: []; }
/** The screen as JSON for a member (data only). */
function gscreen(int $member, string $path): array { return screen(as_member($member), $path); }
/** Every string value and key in a decoded JSON, flattened (to look for a name that must not be there). */
function g_flat($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''; }
/** The base's cached counts back to where the world set them, and last_built_at moved by $days (a build, for the cache's key). */
function g_build_moved(int $base): void { pdo()->exec("UPDATE knowledge_bases SET last_built_at = now() WHERE id = $base"); }
function g_built_reset(int $base): void { pdo()->exec("UPDATE knowledge_bases SET last_built_at = (now() - interval '1 day') WHERE id = $base"); }
/** PHP run in the application's CLI bootstrap as $member with the explorer's functions loaded ($code sees $arg = the decoded JSON argument and $pdo); its stdout. */
function php_g(string $code, int $member = PRIYA, mixed $arg = null): string
{
    $prog = 'require "app/bootstrap.php"; require_once APP_ROOT . "/app/features/bases/handler.php"; require_once APP_ROOT . "/app/knowledge/bridge.php"; $_SESSION["member_id"] = ' . $member
          . '; $pdo = db(); db_apply_context($pdo); $arg = json_decode($argv[1], true); ' . $code;
    return (string) shell_exec('cd ' . escapeshellarg(APP_ROOT) . ' && php -r ' . escapeshellarg($prog) . ' ' . escapeshellarg(json_encode($arg)) . ' 2>&1');
}

/** Communities for the proofs: Pumps' two (11 holds Valves' subject and document too — the engine does not filter members), Valves' one. */
function g_communities(array $w): array
{
    $c = static fn (int $id, string $ns, string $label, int $n): array => ['community_id' => $id, 'namespace' => $ns, 'community_key' => $id % 10, 'label' => $label, 'algorithm' => 'fake', 'computed_at' => gmdate('c'), 'member_count' => $n];
    $m = static fn (string $kind, int $id, ?string $name = null): array => ['object_kind' => $kind, 'object_id' => $id, 'score' => 1.0, 'canonical_name' => $name, 'label' => $name ?? ucfirst($kind) . ' ' . $id];
    return ['communities' => ['kb:' . $w['pumps'] => [$c(11, 'kb:' . $w['pumps'], 'Pump and filter', 2), $c(12, 'kb:' . $w['pumps'], 'Legacy', 1)], 'kb:' . $w['valves'] => [$c(21, 'kb:' . $w['valves'], 'Valves group', 2)]],
            'members' => ['11' => [$m('subject', 1, 'Zorblat Pump'), $m('subject', 2, 'Merrow Filter'), $m('subject', 3, 'Quillon Valve'), $m('document', 601), $m('document', 501)], '12' => [$m('subject', 4, 'Zorblat Pumps')],
                          '21' => [$m('subject', 3, 'Quillon Valve'), $m('document', 601)]]];
}

/** Surprises for kb:Pumps: two that are Pumps' own, one that ends in Quillon Valve, one that starts at Valves' document. */
function g_surprises(array $w): array
{
    $s = static fn (string $sk, int $si, string $sl, string $rel, string $tk, int $ti, string $tl): array => ['source_kind' => $sk, 'source_id' => $si, 'source_label' => $sl, 'source_community' => 1, 'rel' => $rel, 'target_kind' => $tk, 'target_id' => $ti, 'target_label' => $tl, 'target_community' => 2, 'community_pair_edges' => 3];
    return ['surprises' => ['kb:' . $w['pumps'] => [$s('subject', 1, 'Zorblat Pump', 'uses', 'subject', 2, 'Merrow Filter'), $s('subject', 1, 'Zorblat Pump', 'connects_to', 'subject', 3, 'Quillon Valve'),
        $s('document', 601, 'Document 601', 'mentions', 'subject', 1, 'Zorblat Pump'), $s('document', 501, 'Document 501', 'mentions', 'subject', 2, 'Merrow Filter')]]];
}

/** A hub: subject 7 "Hub Pump" linked to document 501 and to $n subjects "Cap NN" (ids 100…), each touching document 503 — except the first $valveOnly, which touch 601 only. */
function g_hub(array $w, int $n = 45, int $valveOnly = 2): array
{
    $g = g_graph($w);
    $g['subjects']['7'] = ['label' => 'Hub Pump', 'type' => 'product', 'description' => 'A hub.'];
    $link = static function (array &$g, string $ak, int $ai, string $bk, int $bi, ?string $rel = null): void {
        $g['neighbors']["$ak:$ai"][] = ['neighbor_kind' => $bk, 'neighbor_id' => $bi, 'rel' => $rel];
        $g['neighbors']["$bk:$bi"][] = ['neighbor_kind' => $ak, 'neighbor_id' => $ai, 'rel' => $rel];
    };
    $link($g, 'subject', 7, 'document', 501, 'described_in');
    for ($i = 0; $i < $n; $i++) {
        $id = 100 + $i;
        $g['subjects'][(string) $id] = ['label' => 'Cap ' . ($i < $valveOnly ? 'V' : 'P') . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'type' => 'thing'];
        $link($g, 'subject', 7, 'subject', $id, 'has');
        $link($g, 'subject', $id, 'document', $i < $valveOnly ? 601 : 503, 'described_in');
    }
    return $g;
}
