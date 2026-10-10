<?php
/**
 * A FAKE MaluDB API for the slice 5 proofs (docs/build-specs/ask.md "The fake engine seam") — `php -S 127.0.0.1:8503 tests/fake_kb_engine.php`.
 * app/knowledge/engine.php runs UNCHANGED against it (MALUDB_KB_API_URL / MALUDB_KB_API_TOKEN in the proofs' env file). It never calls a
 * model and never touches the live MaluDB. It reads $FAKE_KB_STATE (JSON the proofs rewrite between steps):
 *   answer_route  true → POST /v1/memory/answer answers; false → 404 {"detail":"Not Found"} (the module maps it to answer_unavailable)
 *   answer_mode   ok | model_502 (502, the provider failed) | slow (sleeps 5 s — past MALUDB_KB_API_TIMEOUT=3)
 *   scripts       [{match: <a word in the question>, answer: <the M1 reply>}] — the first match wins; no match = {status: "no_evidence"}
 *   recall        rows for POST /v1/memory/recall ({results: rows})
 *   documents     {namespace: [ids]} for GET /v1/documents?document_type=eq.<ns>; titles from `titles` {id: title}
 * It answers GET /health, GET /v1/whoami, GET /v1/graph/query (seeds from the recall rows' subject names), refuses a wrong bearer with 401,
 * and appends every request (method, path, query, body) as one JSON line to $FAKE_KB_STATE.log so the proofs read what the engine was sent.
 *
 * Slice 6 (docs/build-specs/graph-explorer.md "Proof") adds the graph, driven by $FAKE_KB_STATE.graph:
 *   subjects     {id: {label, type, description}}      GET /v1/subjects?label=eq.<name> | id=eq.<n>  → {subjects: [{id, label, type, description}]}
 *   neighbors    {"kind:id": [{neighbor_kind, neighbor_id, rel, edge_store, confidence, provenance, label}]}   GET /v1/graph/neighbors
 *   paths        [{from: "kind:id", to: "kind:id", depth, path: ["kind:id", …]}]                              GET /v1/graph/path → {paths}
 *   god_nodes    [{object_kind, object_id, label, degree_out, degree_in, degree_total}]                       GET /v1/graph/god-nodes?limit
 *   surprises    {namespace: [{source_kind, …}]}   communities {namespace: [{community_id, …}]}   members {community_id: [{object_kind, object_id, …}]}
 *   labels       {"kind:id": label}  the label a neighbour row (and a graph/query node) carries when it is not a subject's name
 *   stats        {edges, nodes}  (tenant-wide: the proofs look for it on a page and must not find it)
 *   fail         true → every graph route answers 500 (an engine failure that is not a refused connection)
 * GET /v1/graph/query walks the neighbours from the subjects whose label holds a word of q when `graph.subjects` is set (else as before),
 * and GET /v1/documents with both document_type and id=in.(…) answers the ids that are in the namespace.
 */
$stateFile = (string) getenv('FAKE_KB_STATE');
$state = json_decode((string) @file_get_contents($stateFile), true) ?: [];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
file_put_contents($stateFile . '.log', json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'query' => $_GET, 'body' => $body], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
$out = static function (array $b, int $status = 200, array $headers = []): never {
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $h) { header($h); }
    echo json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};
if ($path === '/health') { $out(['status' => 'ok', 'version' => 'fake']); }
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . getenv('MALUDB_KB_API_TOKEN')) {
    $out(['error' => ['code' => 'unauthorized', 'message' => 'A valid token is required.']], 401);
}
$g = $state['graph'] ?? null;
$nodeLabel = static function (string $kind, int $id) use ($g): string {
    if (isset($g['labels'][$kind . ':' . $id])) { return (string) $g['labels'][$kind . ':' . $id]; }
    if ($kind === 'subject') { return (string) ($g['subjects'][(string) $id]['label'] ?? ('Subject ' . $id)); }
    return ucfirst($kind) . ' ' . $id;
};
if ($g !== null && (str_starts_with($path, '/v1/graph/') && $path !== '/v1/graph/query' || str_starts_with($path, '/v1/communities') || $path === '/v1/subjects')) {
    if (!empty($g['fail'])) { $out(['detail' => 'boom'], 500); }
    if ($path === '/v1/subjects') {
        $rows = [];
        foreach ($g['subjects'] ?? [] as $id => $s) {
            $hit = (isset($_GET['label']) && preg_match('/^eq\.(.+)$/', (string) $_GET['label'], $m) && $s['label'] === $m[1])
                || (isset($_GET['id']) && preg_match('/^eq\.(\d+)$/', (string) $_GET['id'], $m) && (string) $id === $m[1]);
            if ($hit) { $rows[] = ['id' => (int) $id, 'label' => $s['label'], 'type' => $s['type'] ?? null, 'description' => $s['description'] ?? null]; }
        }
        $out(['subjects' => $rows]);
    }
    if ($path === '/v1/graph/neighbors') {
        $key = (string) ($_GET['kind'] ?? '') . ':' . (int) ($_GET['id'] ?? 0);
        $rows = [];
        foreach ($g['neighbors'][$key] ?? [] as $n) {
            $rows[] = $n + ['rel' => null, 'edge_store' => 'fake', 'confidence' => 0.9, 'provenance' => 'fake', 'label' => $nodeLabel((string) $n['neighbor_kind'], (int) $n['neighbor_id'])];
        }
        $out(['kind' => $_GET['kind'] ?? '', 'id' => (int) ($_GET['id'] ?? 0), 'direction' => 'both', 'neighbors' => $rows]);
    }
    if ($path === '/v1/graph/path') {
        $from = (string) ($_GET['source_kind'] ?? '') . ':' . (int) ($_GET['source_id'] ?? 0);
        $to = (string) ($_GET['target_kind'] ?? '') . ':' . (int) ($_GET['target_id'] ?? 0);
        $paths = [];
        foreach ($g['paths'] ?? [] as $p) {
            if ($p['from'] === $from && $p['to'] === $to && (int) $p['depth'] <= (int) ($_GET['max_depth'] ?? 6)) { $paths[] = ['depth' => (int) $p['depth'], 'path' => $p['path']]; }
        }
        $out(['paths' => $paths]);
    }
    if ($path === '/v1/graph/god-nodes') { $out(['limit' => (int) ($_GET['limit'] ?? 10), 'god_nodes' => array_slice($g['god_nodes'] ?? [], 0, (int) ($_GET['limit'] ?? 10))]); }
    if ($path === '/v1/graph/surprises') { $ns = (string) ($_GET['namespace'] ?? ''); $out(['namespace' => $ns, 'surprises' => array_slice($g['surprises'][$ns] ?? [], 0, (int) ($_GET['limit'] ?? 25))]); }
    if ($path === '/v1/graph/stats') { $out(['stats' => $g['stats'] ?? ['edges' => 0, 'nodes' => 0, 'by_store' => [], 'top_rels' => []]]); }
    if ($path === '/v1/communities') { $ns = (string) ($_GET['namespace'] ?? ''); $out(['communities' => $g['communities'][$ns] ?? []]); }
    if (preg_match('#^/v1/communities/(\d+)/members$#', $path, $m)) {
        $known = false;
        foreach ($g['communities'] ?? [] as $list) { foreach ($list as $c) { $known = $known || (int) $c['community_id'] === (int) $m[1]; } }
        if (!$known) { $out(['error' => ['code' => 'not_found', 'message' => 'Community ' . $m[1] . ' not found.']], 404); }
        $out(['community_id' => (int) $m[1], 'members' => array_slice($g['members'][$m[1]] ?? [], 0, (int) ($_GET['limit'] ?? 200))]);
    }
}
switch ($path) {
    case '/v1/whoami':
        $out(['role' => 'kn_fake', 'database' => 'fake']);
    case '/v1/memory/answer':
        if (empty($state['answer_route'])) { $out(['detail' => 'Not Found'], 404); }
        $mode = $state['answer_mode'] ?? 'ok';
        if ($mode === 'slow') { sleep(5); }
        if ($mode === 'model_502') { $out(['error' => ['code' => 'upstream_error', 'message' => 'Model endpoint returned HTTP 500.']], 502); }
        $q = (string) ($body['question'] ?? '');
        foreach ($state['scripts'] ?? [] as $s) {
            if (stripos($q, (string) $s['match']) !== false) {
                $out($s['answer'] + ['namespace' => $body['namespace'] ?? null, 'question' => $q]);
            }
        }
        $out(['namespace' => $body['namespace'] ?? null, 'question' => $q, 'status' => 'no_evidence', 'answer_md' => null, 'confidence' => 0.0, 'citations' => [], 'evidence' => [],
              'subjects_tried' => [], 'model' => null, 'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'calls' => 0]]);
    case '/v1/memory/recall':
        $out(['results' => $state['recall'] ?? [], 'subjects_tried' => []]);
    case '/v1/graph/query':
        if (isset($g['subjects'])) {
            if (!empty($g['fail'])) { $out(['detail' => 'boom'], 500); }
            $terms = array_values(array_filter(preg_split('/[^a-z0-9_]+/', strtolower((string) ($_GET['q'] ?? ''))) ?: [], static fn ($t) => strlen($t) >= 2));
            $scored = [];
            foreach ($g['subjects'] as $id => $s) {
                $sc = 0;
                foreach ($terms as $t) { if (stripos($s['label'], $t) !== false) { $sc++; } }
                if ($sc > 0) { $scored[] = ['subject_id' => (int) $id, 'canonical_name' => $s['label'], 'score' => $sc]; }
            }
            usort($scored, static fn ($a, $b) => [$b['score'], $a['subject_id']] <=> [$a['score'], $b['subject_id']]);
            $seeds = array_slice($scored, 0, (int) ($_GET['seeds'] ?? 3));
            $depthMax = (int) ($_GET['depth'] ?? 2);
            $seen = [];
            $edges = [];
            $frontier = [];
            foreach ($seeds as $s) { $seen['subject:' . $s['subject_id']] = 0; $frontier[] = 'subject:' . $s['subject_id']; }
            for ($d = 1; $d <= $depthMax; $d++) {
                $next = [];
                foreach ($frontier as $k) {
                    foreach ($g['neighbors'][$k] ?? [] as $n) {
                        $nk = $n['neighbor_kind'] . ':' . $n['neighbor_id'];
                        $pair = $k < $nk ? $k . '|' . $nk : $nk . '|' . $k;
                        [$sk, $si] = explode(':', $k);
                        $edges[$pair] ??= ['source_kind' => $sk, 'source_id' => (int) $si, 'rel' => $n['rel'] ?? null, 'target_kind' => $n['neighbor_kind'], 'target_id' => (int) $n['neighbor_id'], 'confidence' => 0.9];
                        if (!isset($seen[$nk])) { $seen[$nk] = $d; $next[] = $nk; }
                    }
                }
                $frontier = $next;
            }
            $nodes = [];
            foreach ($seen as $k => $d) {
                [$nkind, $nid] = explode(':', $k);
                $nodes[] = ['object_kind' => $nkind, 'object_id' => (int) $nid, 'label' => $nodeLabel($nkind, (int) $nid), 'depth' => $d];
            }
            usort($nodes, static fn ($a, $b) => [$a['depth'], $a['object_id']] <=> [$b['depth'], $b['object_id']]);
            $nodes = array_slice($nodes, 0, (int) ($_GET['max_nodes'] ?? 50));
            $kept = [];
            foreach ($nodes as $n) { $kept[$n['object_kind'] . ':' . $n['object_id']] = true; }
            $edges = array_values(array_filter($edges, static fn ($e) => isset($kept[$e['source_kind'] . ':' . $e['source_id']], $kept[$e['target_kind'] . ':' . $e['target_id']])));
            $out(['query' => $_GET['q'] ?? '', 'namespace' => $_GET['namespace'] ?? null, 'depth' => $depthMax, 'seeds' => $seeds, 'nodes' => $nodes, 'edges' => $edges]);
        }
        $seeds = [];
        foreach ($state['recall'] ?? [] as $r) {
            if (!empty($r['subject_name'])) { $seeds[$r['subject_name']] = ['subject_id' => count($seeds) + 1, 'canonical_name' => $r['subject_name']]; }
        }
        $out(['seeds' => array_values($seeds), 'nodes' => [], 'edges' => []]);
    case '/v1/documents':
        $docs = [];
        $titles = $state['titles'] ?? [];
        if (isset($_GET['document_type']) && isset($_GET['id']) && preg_match('/^eq\.(.+)$/', (string) $_GET['document_type'], $m) && preg_match('/^in\.\(([\d,]+)\)$/', (string) $_GET['id'], $m2)) {
            $mine = array_map('intval', $state['documents'][$m[1]] ?? []);
            foreach (explode(',', $m2[1]) as $id) { if (in_array((int) $id, $mine, true)) { $docs[] = ['id' => (int) $id, 'title' => $titles[$id] ?? ('Document ' . $id)]; } }
        } elseif (isset($_GET['document_type']) && preg_match('/^eq\.(.+)$/', (string) $_GET['document_type'], $m)) {
            foreach ($state['documents'][$m[1]] ?? [] as $id) { $docs[] = ['id' => (int) $id, 'title' => $titles[(string) $id] ?? ('Document ' . $id), 'created_at' => gmdate('c')]; }
        } elseif (isset($_GET['id']) && preg_match('/^in\.\(([\d,]+)\)$/', (string) $_GET['id'], $m)) {
            foreach (explode(',', $m[1]) as $id) { $docs[] = ['id' => (int) $id, 'title' => $titles[$id] ?? ('Document ' . $id)]; }
        }
        $out(['documents' => $docs], 200, ['Content-Range: 0-' . max(0, count($docs) - 1) . '/' . count($docs)]);
}
$out(['detail' => 'Not Found'], 404);
