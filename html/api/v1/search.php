<?php
declare(strict_types=1);
/**
 * POST /api/v1/search {query, limit?, focus_area?} — the evidence a base holds on a query (kn_search(), door `api`): the os.knowledge-evidence/1 document,
 * nothing stored; `question.search` is logged under the key (the count and 120 characters of the query). `limit` 1–20 (default 8); `focus_area` is
 * accepted and echoed. The key's call is counted once first. 503 when the engine cannot be reached.
 */
require_once dirname(__DIR__, 3) . '/app/api/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/keys/handler.php';
api_cors();
api_require_method('POST');
api_authenticate();
api_rate_limit();
api_session_csrf();
$pdo = db();
$in = api_body();
$baseId = api_base_id($pdo, $in);
$key = api_key();
$area = $in['focus_area'] ?? null;
$r = kn_search($pdo, ['base_id' => $baseId, 'query' => is_string($in['query'] ?? null) ? $in['query'] : '', 'focus_area_id' => is_scalar($area) ? (string) $area : null,
    'limit' => is_numeric($in['limit'] ?? null) ? (int) $in['limit'] : 8, 'door' => $key !== null ? 'api' : 'web', 'token_id' => $key['token_id'] ?? null]);
if (!$r['ok']) {
    api_gate_error($r);
}
if ($r['status'] === 'failed') {
    api_failed((string) $r['error_code'], kn_engine_error_message((string) $r['error_code']));
}
api_json(api_document($r));
