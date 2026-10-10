<?php
declare(strict_types=1);
/**
 * POST /api/v1/ask {question, focus_area?, conversation_id?} — the token API's ask (slice 7, docs/build-specs/api-keys-k7.md). Bearer = a key bound to ONE
 * base (the base is the key's; a body naming another is ignored; a key bound to an area forces it — naming another is 403 area_not_allowed). A session
 * caller (the application's own pages) names `base`, is held to the ordinary reach and CSRF, and is never charged. The key's call is counted ONCE, before
 * anything else is read (api_rate_limit()). The work is kn_ask() unchanged, door `api`: the answer is the os.knowledge-answer/1 document the window gets;
 * HTTP 200 for answered, evidence_only, no_evidence, unverified and refused (the status says which); 503 engine_unavailable / engine_timeout for failed,
 * with the question_id in the error; a gate refusal is 404 / 403 / 422.
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
$conv = $in['conversation_id'] ?? null;
$r = kn_ask($pdo, ['base_id' => $baseId, 'question' => is_string($in['question'] ?? null) ? $in['question'] : '', 'focus_area_id' => is_scalar($area) ? (string) $area : null,
    'conversation_id' => is_string($conv) ? $conv : null, 'door' => $key !== null ? 'api' : 'web', 'token_id' => $key['token_id'] ?? null]);
if (!$r['ok']) {
    api_gate_error($r);
}
if ($r['status'] === 'failed') {
    api_failed((string) $r['error_code'], (string) $r['message'], $r['question_id']);
}
api_json(api_document($r));
