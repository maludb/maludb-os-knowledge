<?php
declare(strict_types=1);

/**
 * The prelude of the key screens and handlers (slice 7), and the token API's shared helpers.
 *   key_from_request()   the key a request names (`api_key` or `key`, through mcp_keys — a base the caller does not curate is "not found") and its base
 *   key_fields_from_request()   label, area and limits, with the rule that raising a limit above the default is the admin's (limits.manage)
 *   key_log()            a row of the trail about a key, base_id on every one; the raw key and its hash are never in a payload
 *   api_body() · api_answer() · api_gate_error() · api_failed()   the token API (html/api/v1/*): the JSON body, a kn_ask()/kn_search() result as the one document, the refusals
 */
require_once dirname(__DIR__) . '/bases/handler.php';
require_once APP_ROOT . '/app/knowledge/ask.php';
require_once APP_ROOT . '/app/features/ask/queries.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

/** The key the request names, and its base row: [key, base]. A key outside the caller's curated bases does not exist to them (404). */
function key_from_request(PDO $pdo, ?string $screen = null): array
{
    $id = request_integer('api_key') ?? request_integer('key') ?? request_integer('id');
    if ($id === null) {
        refuse(422, 'Say which key.');
    }
    $k = find_key($pdo, $id) ?? refuse(404, 'Key not found.');
    $b = find_base($pdo, (int) $k['base_id']) ?? refuse(404, 'Key not found.');
    base_admin_view_log($pdo, $b, $screen ?? request_route());
    return [$k, $b];
}

function key_log(PDO $pdo, string $action, int $baseId, int $keyId, array $after = [], array $before = []): void
{
    log_activity($pdo, $action, 'key', $keyId, ['base_id' => $baseId, 'after' => $after] + ($before !== [] ? ['before' => $before] : []));
}

/**
 * A key's fields from the request: [fields, errors]. $cur: the key's current row (mcp_keys) on an edit — a field left out keeps its value, and
 * a limit above the settings' default is refused only when it is a CHANGE (an admin's higher limit survives a curator's rename). The area is
 * an id or a slug; an area of ANOTHER base is passed to the database, which says so in its own words.
 */
function key_fields_from_request(PDO $pdo, int $baseId, ?array $cur): array
{
    $errors = [];
    $f = [];
    $label = req_has('label') ? (string) req_val('label') : (string) ($cur['label'] ?? '');
    if ($label === '' || mb_strlen($label) > 80) {
        $errors['label'] = 'Give the key a label of up to 80 characters — what will use it.';
    }
    $f['label'] = $label;
    // the area: `focus_area` (empty = the whole base); the partial-update prefill supplies the column's own name, `focus_area_id`
    $areaField = req_has('focus_area') ? 'focus_area' : (req_has('focus_area_id') ? 'focus_area_id' : null);
    $f['focus_area_id'] = $cur === null ? null : ($cur['focus_area_id'] === null ? null : (int) $cur['focus_area_id']);
    if ($areaField !== null) {
        $v = (string) req_val($areaField);
        if ($v === '' || $v === '0') {
            $f['focus_area_id'] = null;
        } elseif (ctype_digit($v)) {
            $row = $pdo->prepare('SELECT base_id, status FROM focus_areas WHERE id = :id');
            $row->execute(['id' => (int) $v]);
            $r = $row->fetch(PDO::FETCH_ASSOC);
            if ($r !== false && (int) $r['base_id'] === $baseId && $r['status'] !== 'active') {
                $errors['focus_area'] = 'That focus area is not active in this base.';
            }
            $f['focus_area_id'] = (int) $v;                         // another base's area: the trigger's words
        } else {
            $row = $pdo->prepare("SELECT id FROM focus_areas WHERE base_id = :b AND slug = :s AND status = 'active'");
            $row->execute(['b' => $baseId, 's' => $v]);
            $id = $row->fetchColumn();
            if ($id === false) {
                $errors['focus_area'] = 'That focus area is not active in this base.';
            } else {
                $f['focus_area_id'] = (int) $id;
            }
        }
    }
    $def = key_defaults($pdo);
    $f['rate_per_minute'] = kn_int('rate_per_minute', isset($cur['rate_per_minute']) ? (int) $cur['rate_per_minute'] : null, 1, 100000, 'Calls per minute', $errors, true);
    $f['rate_per_day'] = kn_int('rate_per_day', isset($cur['rate_per_day']) ? (int) $cur['rate_per_day'] : null, 1, 10000000, 'Calls per day', $errors, true);
    if ($cur !== null) {
        foreach (['rate_per_minute', 'rate_per_day'] as $k) {              // blank on an edit = the default again
            if (req_has($k) && (string) req_val($k) === '') {
                $f[$k] = $def[$k === 'rate_per_minute' ? 'per_minute' : 'per_day'];
            }
        }
    }
    foreach (['rate_per_minute' => ['per_minute', 'a minute'], 'rate_per_day' => ['per_day', 'a day']] as $k => [$d, $words]) {
        $was = $cur[$k] ?? null;
        if (!isset($errors[$k]) && $f[$k] !== null && $f[$k] > $def[$d] && ($was === null || (int) $was !== $f[$k]) && !has_right('limits.manage')) {
            $errors[$k] = 'A key\'s limit is at most the default (' . number_format($def[$d]) . ' ' . $words . ') unless an admin raises it.';
        }
    }
    return [$f, $errors];
}

/** The database's refusals of a key's own fields, named on the field. */
function key_field_refusals(): array
{
    return [
        '/area is an area of its base/' => ['focus_area', null],
        '/bound to one base for life/' => ['api_key', null],
        '/Only a live key is rotated/' => ['api_key', null],
        'mcp_access_tokens_rate_per_minute_check' => ['rate_per_minute', 'Calls per minute is a whole number from 1 to 100000.'],
        'mcp_access_tokens_rate_per_day_check' => ['rate_per_day', 'Calls per day is a whole number from 1 to 10000000.'],
    ];
}

// ---------------------------------------------------------------------------------------------------------------------
// The token API
// ---------------------------------------------------------------------------------------------------------------------

/** The request's JSON object (a form post is accepted too); an unparseable body is a 422. */
function api_body(): array
{
    static $body = null;
    if ($body !== null) {
        return $body;
    }
    $raw = trim((string) file_get_contents('php://input'));
    $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if ($raw !== '' && (str_contains($type, 'json') || $raw[0] === '{')) {
        $d = json_decode($raw, true);
        if (!is_array($d) || array_is_list($d) && $d !== []) {
            api_error('invalid', 'The body is one JSON object.', 422);
        }
        return $body = $d;
    }
    return $body = $_POST;
}

/** A session caller's POST (the application's own pages) is held to the CSRF token; a key's call has none to forge. */
function api_session_csrf(): void
{
    if (api_key() !== null || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? (api_body()['csrf_token'] ?? ''));
    if (!hash_equals(csrf_token(), $sent)) {
        api_error('csrf_failed', 'CSRF validation failed.', 403);
    }
}

/** A refusal of the gate of kn_ask()/kn_search() ([ok => false, http, error, fields]) as the API's error. */
function api_gate_error(array $r): never
{
    $extra = $r['http'] === 422 ? ['fields' => (object) ($r['fields'] ?? [])] : [];
    api_error($r['http'] === 422 ? 'invalid' : $r['error']['code'], $r['error']['message'], $r['http'], $extra);
}

/** A `failed` answer or search is a 503: engine_unavailable or engine_timeout (anything else is engine_error), with the question's id when one was written. */
function api_failed(string $code, string $message, ?int $questionId = null): never
{
    $c = in_array($code, ['engine_unavailable', 'engine_timeout'], true) ? $code : 'engine_error';
    api_error($c, $message, 503, $questionId !== null ? ['question_id' => $questionId] : []);
}

/** A kn_ask()/kn_search() result as the document it carries (os.knowledge-answer/1 or -evidence/1): the PHP callers' keys removed. */
function api_document(array $r): array
{
    unset($r['ok'], $r['http'], $r['fields'], $r['error']);
    return $r;
}

/** The base the call asks: a key's own (the body naming another is ignored); a session caller names `base` (an id or a key). Answers the id, after api_require_base(). */
function api_base_id(PDO $pdo, array $in): int
{
    $key = api_key();
    if ($key !== null) {
        $id = $key['base_id'];
    } else {
        $b = $in['base'] ?? null;
        if ($b === null || $b === '' || is_array($b)) {
            api_error('invalid', 'Say which knowledge base.', 422, ['fields' => (object) ['base' => 'Say which knowledge base (its key or id).']]);
        }
        $id = ctype_digit((string) $b) ? (int) $b : (int) (one_value($pdo, 'SELECT id FROM knowledge_bases WHERE key = :k', ['k' => (string) $b]) ?? 0);
    }
    api_require_base($id);
    return $id;
}

/**
 * The question the call names (the route's {id}), as the caller may read it: a key reads questions asked with THIS key or the key it was rotated from
 * (api_find_question() through mcp_questions, then key_token_ids()) on its own base; a session caller whatever mcp_questions shows them. Anything else is
 * the one 404 — the same words as a question that does not exist.
 */
function api_question_for_caller(PDO $pdo, int $id): array
{
    $q = $id > 0 ? api_find_question($pdo, $id) : null;
    $key = api_key();
    if ($q !== null && $key !== null && ($q['token_id'] === null || !in_array((int) $q['token_id'], key_token_ids($pdo, $key['token_id']), true) || (int) $q['base_id'] !== $key['base_id'])) {
        $q = null;
    }
    if ($q === null) {
        api_error('not_found', 'Question not found.', 404);
    }
    api_require_base((int) $q['base_id']);
    return $q;
}
