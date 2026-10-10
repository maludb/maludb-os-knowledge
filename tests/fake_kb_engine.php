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
        $seeds = [];
        foreach ($state['recall'] ?? [] as $r) {
            if (!empty($r['subject_name'])) { $seeds[$r['subject_name']] = ['subject_id' => count($seeds) + 1, 'canonical_name' => $r['subject_name']]; }
        }
        $out(['seeds' => array_values($seeds), 'nodes' => [], 'edges' => []]);
    case '/v1/documents':
        $docs = [];
        $titles = $state['titles'] ?? [];
        if (isset($_GET['document_type']) && preg_match('/^eq\.(.+)$/', (string) $_GET['document_type'], $m)) {
            foreach ($state['documents'][$m[1]] ?? [] as $id) { $docs[] = ['id' => (int) $id, 'title' => $titles[(string) $id] ?? ('Document ' . $id), 'created_at' => gmdate('c')]; }
        } elseif (isset($_GET['id']) && preg_match('/^in\.\(([\d,]+)\)$/', (string) $_GET['id'], $m)) {
            foreach (explode(',', $m[1]) as $id) { $docs[] = ['id' => (int) $id, 'title' => $titles[$id] ?? ('Document ' . $id)]; }
        }
        $out(['documents' => $docs], 200, ['Content-Range: 0-' . max(0, count($docs) - 1) . '/' . count($docs)]);
}
$out(['detail' => 'Not Found'], 404);
