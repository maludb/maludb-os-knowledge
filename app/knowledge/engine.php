<?php
declare(strict_types=1);

/**
 * The engine module — the ONE file of the Knowledge application that talks to MaluDB (design §0, §6.2, §6.3, §8).
 *
 * It alone reads MALUDB_KB_API_URL and MALUDB_KB_API_TOKEN (the knowledge memory database's own token). Every function
 * returns the same shape and never throws:
 *
 *     ['ok' => bool, 'status' => int, 'data' => array, 'error' => ?string, 'code' => ?string]
 *
 * status is the HTTP status MaluDB answered (0 when no call was made or the call never completed); error is a short
 * text that never carries the token; code is one of
 *     engine_unavailable  no URL/token configured, or the API cannot be reached
 *     engine_timeout      the API did not answer in time
 *     engine_auth         the API refused the token (401)
 *     engine_forbidden    the memory database refused the role (403)
 *     invalid_argument    an argument failed validation — no call was made
 *     answer_unavailable  the API predates POST /v1/memory/answer (0.4.0, M1): fall back to kn_engine_search()
 *     project_not_found   an answer was scoped to a project the engine does not know
 *     not_found           the record is not in the engine
 *     model_failed        the model provider failed or answered nonsense (MaluDB's 502 upstream_error)
 *     model_not_configured, model_api_key_missing, validation_failed, conflict, ... — MaluDB's own code, passed through
 *     route_not_found     the API has no such route (an older API)
 *     engine_error        anything else
 * and, on failure, data['engine_code'] is MaluDB's own error code when it gave one.
 *
 * Facts about MaluDB this module works around (proven by tests/phase0/engine.sh, reported to the owner):
 *  - POST /v1/subjects and POST /v1/projects number a new subject MAX(subject_id)+1 outside the table's sequence, so the
 *    next extraction that creates a subject collides (409 duplicate key on malu$svpor_subject_pkey). This module never
 *    calls them: vocabulary terms and focus areas are created through the extraction path (a document of caller-supplied
 *    edges, no model call) and that seed document is forgotten at once — the subjects remain, numbered by the sequence.
 *  - No API route stores subject aliases. kn_engine_seed_subjects() keeps them in the subject's description and returns
 *    them; the compiled prompt carries "ALIAS: x => y" lines (compile.php) and kn_engine_expand_aliases() names the
 *    canonical term in a question.
 *  - DELETE /v1/projects/{id} leaves the project's subject links (docs/memory-answer.md "A defect found"): delete unlinks
 *    every subject first, and a created project has any stale links cleared.
 *  - GET /v1/graph/query's `namespace` is a canonical-name prefix (graphify imports), not the memory namespace, and
 *    /v1/graph/{stats,god-nodes} are tenant-wide: graph reads are confined to a base here by the document_type every
 *    section is stamped with (= its namespace).
 *  - /v1/memory/documents takes no hints: hints become the document's subject tags.
 *  - /v1/memory/ingest {preview: true} calls no model and ignores the namespace's prompt_template: it answers the prompt
 *    the generic ingest contract would send. kn_engine_preview() returns that and the base's own documents prompt.
 */

const KN_ENGINE_TIMEOUT = 30;          // seconds, a read or a write that calls no model
const KN_ENGINE_MODEL_TIMEOUT = 60;    // seconds, a route that calls the model
const KN_ENGINE_SEED_NAMESPACE = 'kb-seed';
const KN_ENGINE_ANSWER_STATUSES = ['answered', 'no_evidence', 'unverified'];

// ---------------------------------------------------------------------------------------------------------------------
// Configuration and the result shape
// ---------------------------------------------------------------------------------------------------------------------

function kn_engine_env(string $key): string
{
    $value = function_exists('env') ? env($key) : null;
    if ($value === null || $value === '') {
        $raw = getenv($key);
        $value = $raw === false ? ($_ENV[$key] ?? $_SERVER[$key] ?? '') : $raw;
    }
    return trim((string) $value);
}

/** @return array{url: string, token: string}|null */
function kn_engine_config(): ?array
{
    $url = rtrim(kn_engine_env('MALUDB_KB_API_URL'), '/');
    $token = kn_engine_env('MALUDB_KB_API_TOKEN');
    if ($url === '' || $token === '' || !preg_match('#^https?://#i', $url)) {
        return null;
    }
    return ['url' => $url, 'token' => $token];
}

function kn_engine_result(bool $ok, int $status = 0, array $data = [], ?string $error = null, ?string $code = null): array
{
    return [
        'ok' => $ok,
        'status' => $status,
        'data' => kn_engine_scrub($data),
        'error' => $error === null ? null : kn_engine_scrub_text($error),
        'code' => $code,
    ];
}

function kn_engine_invalid(string $why): array
{
    return kn_engine_result(false, 0, [], $why, 'invalid_argument');
}

/** Secrets this request handed the engine (a provider credential): redacted from everything returned, like the token. */
function kn_engine_secrets(?string $add = null): array
{
    static $secrets = [];
    if ($add !== null && strlen($add) >= 6) {
        $secrets[$add] = true;
    }
    return array_keys($secrets);
}

function kn_engine_scrub_text(string $text): string
{
    $cfgToken = kn_engine_env('MALUDB_KB_API_TOKEN');
    foreach (array_merge([$cfgToken], kn_engine_secrets()) as $secret) {
        if ($secret !== '' && strlen($secret) >= 6) {
            $text = str_replace($secret, '[redacted]', $text);
        }
    }
    // Any bearer value, and anything shaped like a MaluDB token, whoever's it is.
    $text = (string) preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $text);
    return (string) preg_replace('/malu_[A-Za-z0-9_\-]{8,}/', 'malu_[redacted]', $text);
}

/** Drops credential-shaped keys and redacts secret values, recursively. */
function kn_engine_scrub(mixed $value): mixed
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && preg_match('/^(token|api_key|apikey|password|pg_password|authorization|secret|secret_value)$/i', $k)) {
                continue;
            }
            $out[$k] = kn_engine_scrub($v);
        }
        return $out;
    }
    return is_string($value) ? kn_engine_scrub_text($value) : $value;
}

/** User-safe words for a result's code (never MaluDB's own message, which may name tables or SQL). */
function kn_engine_error_message(?string $code): string
{
    return match ($code) {
        null => '',
        'engine_unavailable' => 'The knowledge engine is not available right now.',
        'engine_timeout' => 'The knowledge engine took too long to answer. Try again in a moment.',
        'engine_auth', 'engine_forbidden' => 'The knowledge engine refused this installation\'s credentials. An administrator must check them.',
        'invalid_argument' => 'The request was not valid.',
        'answer_unavailable' => 'Composed answers are not available on this engine yet; the matching passages are shown instead.',
        'project_not_found' => 'That focus area is not in the knowledge graph yet. Rebuild the base.',
        'not_found' => 'That item is no longer in the knowledge graph.',
        'model_failed' => 'The language model failed to answer. Try again later.',
        'model_not_configured', 'model_api_key_missing' => 'No language model is configured for this knowledge base. An administrator must set one.',
        'no_edges' => 'Nothing could be extracted from this section.',
        'conflict' => 'The knowledge graph refused a duplicate.',
        'validation_failed' => 'The knowledge engine did not accept the request.',
        default => 'The knowledge engine could not complete the request.',
    };
}

// ---------------------------------------------------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------------------------------------------------

/** A base's namespace in the application's use: kb:<base id>. */
function kn_engine_valid_namespace(mixed $ns): bool
{
    return is_string($ns) && preg_match('/^kb:[0-9]{1,18}$/', $ns) === 1;
}

function kn_engine_valid_id(mixed $id): bool
{
    return is_int($id) && $id > 0;
}

function kn_engine_valid_kind(mixed $kind): bool
{
    return is_string($kind) && preg_match('/^[a-z_]{1,40}$/', $kind) === 1;
}

function kn_engine_clean_text(mixed $text, int $max): ?string
{
    if (!is_string($text)) {
        return null;
    }
    $text = trim($text);
    return ($text === '' || mb_strlen($text) > $max) ? null : $text;
}

function kn_engine_clamp(int $v, int $lo, int $hi): int
{
    return max($lo, min($hi, $v));
}

// ---------------------------------------------------------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------------------------------------------------------

/**
 * One call. $body null = no body; an array is sent as JSON. Answers the module's result with data = the decoded JSON
 * body (and data['_total'] when the API sent a Content-Range total).
 */
function kn_engine_request(string $method, string $path, array $query = [], ?array $body = null, bool $model = false, array $headers = []): array
{
    $cfg = kn_engine_config();
    if ($cfg === null) {
        return kn_engine_result(false, 0, [], 'MALUDB_KB_API_URL or MALUDB_KB_API_TOKEN is not set.', 'engine_unavailable');
    }
    try {
        $timeout = $model ? KN_ENGINE_MODEL_TIMEOUT : KN_ENGINE_TIMEOUT;
        $override = kn_engine_env('MALUDB_KB_API_TIMEOUT');
        if ($override !== '' && ctype_digit($override)) {
            $timeout = kn_engine_clamp((int) $override, 1, 120);
        }
        $url = $cfg['url'] . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $send = ['Authorization: Bearer ' . $cfg['token'], 'Accept: application/json'];
        foreach ($headers as $h) {
            $send[] = $h;
        }
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $send[] = 'Content-Type: application/json';
        }
        $respHeaders = [];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $send,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$respHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } catch (Throwable $e) {
        return kn_engine_result(false, 0, [], 'The request could not be prepared.', 'engine_error');
    }

    if ($errno !== 0 || $raw === false) {
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            return kn_engine_result(false, 0, [], "The engine did not answer within {$timeout} s.", 'engine_timeout');
        }
        return kn_engine_result(false, 0, [], 'The engine could not be reached (curl error ' . $errno . ').', 'engine_unavailable');
    }
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        $data = [];
    }
    if (isset($respHeaders['content-range']) && preg_match('#/(\d+)$#', $respHeaders['content-range'], $m)) {
        $data['_total'] = (int) $m[1];
    }
    if ($status >= 200 && $status < 300) {
        return kn_engine_result(true, $status, $data);
    }
    return kn_engine_map_error($status, $data);
}

function kn_engine_map_error(int $status, array $data): array
{
    $engineCode = is_array($data['error'] ?? null) ? (string) ($data['error']['code'] ?? '') : '';
    $message = is_array($data['error'] ?? null) ? (string) ($data['error']['message'] ?? '') : '';
    if ($message === '' && isset($data['detail'])) {
        $message = is_string($data['detail']) ? $data['detail'] : 'The request was not accepted.';
    }
    $code = match (true) {
        $status === 401 => 'engine_auth',
        $status === 403 => 'engine_forbidden',
        $status === 404 && $engineCode === '' => 'route_not_found',
        $status === 404 => 'not_found',
        $status === 502 => 'model_failed',
        $status === 503 && $engineCode === '' => 'engine_unavailable',
        $engineCode !== '' => $engineCode,
        $status === 422 => 'validation_failed',
        default => 'engine_error',
    };
    $extra = $engineCode !== '' ? ['engine_code' => $engineCode] : [];
    return kn_engine_result(false, $status, $extra, $message !== '' ? mb_substr($message, 0, 300) : "HTTP $status", $code);
}

// ---------------------------------------------------------------------------------------------------------------------
// Health and identity
// ---------------------------------------------------------------------------------------------------------------------

/** Is the engine configured and up? (GET /health, then the token proven by GET /v1/whoami.) */
function kn_engine_available(): array
{
    $cfg = kn_engine_config();
    if ($cfg === null) {
        return kn_engine_result(false, 0, [], 'MALUDB_KB_API_URL or MALUDB_KB_API_TOKEN is not set.', 'engine_unavailable');
    }
    $health = kn_engine_request('GET', '/health');
    if (!$health['ok']) {
        return $health['code'] === 'route_not_found'
            ? kn_engine_result(false, $health['status'], [], 'The URL does not answer as a MaluDB API.', 'engine_unavailable')
            : $health;
    }
    $who = kn_engine_whoami();
    if (!$who['ok']) {
        return $who['code'] === 'route_not_found'
            ? kn_engine_result(false, $who['status'], [], 'The URL does not answer as a MaluDB API.', 'engine_unavailable')
            : $who;
    }
    return kn_engine_result(true, 200, ['health' => $health['data']['status'] ?? 'ok', 'whoami' => $who['data']]);
}

function kn_engine_whoami(): array
{
    return kn_engine_request('GET', '/v1/whoami');
}

// ---------------------------------------------------------------------------------------------------------------------
// A base's namespace configuration (its compiled guidelines)
// ---------------------------------------------------------------------------------------------------------------------

/**
 * POST /v1/memory/config for a base. $cfg keys:
 *   provider_name, provider_kind (cloud_api|local_http|…), model_alias, model_identifier, base_url (required),
 *   embedding_model (required), prompt_template (must contain {{chunk}}), secret_name + token (the provider credential —
 *   for K24 the application's model credential; stored by MaluDB encrypted, never returned), context_length,
 *   generation_params (array), default_subject_type.
 */
function kn_engine_config_namespace(string $namespace, array $cfg): array
{
    if (!kn_engine_valid_namespace($namespace)) {
        return kn_engine_invalid('The namespace must be kb:<base id>.');
    }
    $need = ['provider_name' => 80, 'provider_kind' => 40, 'model_alias' => 120, 'model_identifier' => 200, 'base_url' => 500, 'embedding_model' => 200];
    $clean = [];
    foreach ($need as $key => $max) {
        $clean[$key] = kn_engine_clean_text($cfg[$key] ?? null, $max);
        if ($clean[$key] === null) {
            return kn_engine_invalid("Configuration field \"$key\" is required (at most $max characters).");
        }
    }
    if (!preg_match('#^https?://#i', $clean['base_url'])) {
        return kn_engine_invalid('base_url must be an http(s) URL.');
    }
    $body = [
        'namespace' => $namespace,
        'provider' => ['name' => $clean['provider_name'], 'kind' => $clean['provider_kind']],
        'alias' => ['name' => $clean['model_alias'], 'model_identifier' => $clean['model_identifier'], 'base_url' => $clean['base_url']],
        'embedding_model' => $clean['embedding_model'],
    ];
    if (isset($cfg['context_length'])) {
        if (!is_int($cfg['context_length']) || $cfg['context_length'] <= 0) {
            return kn_engine_invalid('context_length must be a positive integer.');
        }
        $body['alias']['context_length'] = $cfg['context_length'];
    }
    if (array_key_exists('prompt_template', $cfg) && $cfg['prompt_template'] !== null) {
        $tpl = $cfg['prompt_template'];
        if (!is_string($tpl) || !str_contains($tpl, '{{chunk}}') || strlen($tpl) > 60000) {
            return kn_engine_invalid('prompt_template must be text containing {{chunk}}.');
        }
        $body['prompt_template'] = $tpl;
    }
    if (isset($cfg['token'])) {
        $secret = kn_engine_clean_text($cfg['secret_name'] ?? null, 120);
        if (!is_string($cfg['token']) || $cfg['token'] === '' || $secret === null || !preg_match('/^[A-Za-z0-9_.:-]+$/', $secret)) {
            return kn_engine_invalid('A provider token needs a secret_name of letters, digits and _.:-');
        }
        kn_engine_secrets($cfg['token']);
        $body['secret_name'] = $secret;
        $body['token'] = $cfg['token'];
    } elseif (isset($cfg['secret_name'])) {
        $secret = kn_engine_clean_text($cfg['secret_name'], 120);
        if ($secret === null) {
            return kn_engine_invalid('secret_name must be text.');
        }
        $body['secret_name'] = $secret;
    }
    if (isset($cfg['generation_params'])) {
        if (!is_array($cfg['generation_params'])) {
            return kn_engine_invalid('generation_params must be an object.');
        }
        $body['generation_params'] = (object) $cfg['generation_params'];
    }
    if (isset($cfg['default_subject_type'])) {
        $t = kn_engine_clean_text($cfg['default_subject_type'], 60);
        if ($t === null) {
            return kn_engine_invalid('default_subject_type must be text.');
        }
        $body['default_subject_type'] = $t;
    }
    return kn_engine_request('POST', '/v1/memory/config', [], $body);
}

function kn_engine_get_namespace_config(string $namespace): array
{
    if (!kn_engine_valid_namespace($namespace)) {
        return kn_engine_invalid('The namespace must be kb:<base id>.');
    }
    return kn_engine_request('GET', '/v1/memory/config', ['namespace' => $namespace]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Subjects created through the extraction path (see the header: never POST /v1/subjects or /v1/projects)
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Creates (or finds) subjects by canonical name through one document of caller-supplied edges — no model call — and
 * forgets that document at once. @param list<array{name: string, type: string}> $subjects
 */
function kn_engine_seed_through_extraction(array $subjects): array
{
    $edges = [];
    $lines = [];
    foreach ($subjects as $s) {
        $edges[] = ['subject_text' => $s['name'], 'subject_type' => $s['type'], 'verb_text' => 'seeded', 'source_span' => $s['name'], 'confidence' => 1.0, 'provenance' => 'accepted'];
        $lines[] = $s['name'];
    }
    $made = kn_engine_request('POST', '/v1/memory/documents', [], [
        'title' => 'Knowledge vocabulary seed',
        'text' => implode("\n", $lines),
        'namespace' => KN_ENGINE_SEED_NAMESPACE,
        'source_type' => 'note',
        'document_type' => KN_ENGINE_SEED_NAMESPACE,
        'edges' => $edges,
    ]);
    if (!$made['ok']) {
        return $made;
    }
    $docId = (int) ($made['data']['document_id'] ?? 0);
    if ($docId > 0) {
        $gone = kn_engine_request('DELETE', '/v1/documents/' . $docId);
        if (!$gone['ok']) {
            return kn_engine_result(false, $gone['status'], [], 'The seed document could not be forgotten.', $gone['code']);
        }
    }
    return kn_engine_result(true, $made['status'], ['statements' => count($made['data']['edges'] ?? [])]);
}

/** The subject whose canonical name is exactly $name, or null. */
function kn_engine_find_subject(string $name): array
{
    $r = kn_engine_request('GET', '/v1/subjects', ['label' => 'eq.' . $name, 'limit' => 2]);
    if (!$r['ok']) {
        return $r;
    }
    foreach ($r['data']['subjects'] ?? [] as $row) {
        if (($row['label'] ?? null) === $name) {
            return kn_engine_result(true, 200, ['subject' => $row]);
        }
    }
    return kn_engine_result(true, 200, ['subject' => null]);
}

/**
 * Vocabulary terms → subjects, before a build. Idempotent: a term that exists is updated (type, description), never
 * duplicated. Aliases cannot be stored by the API (no route); they are kept in the description and returned in
 * data['aliases'] (alias => canonical) for the compiled prompt and kn_engine_expand_aliases().
 * @param list<array{canonical_name: string, aliases?: list<string>, subject_type?: string, description?: string}> $terms
 */
function kn_engine_seed_subjects(array $terms): array
{
    if ($terms === [] || count($terms) > 500) {
        return kn_engine_invalid('Between 1 and 500 terms.');
    }
    $clean = [];
    foreach ($terms as $i => $t) {
        $name = is_array($t) ? kn_engine_clean_text($t['canonical_name'] ?? null, 200) : null;
        $type = is_array($t) ? (kn_engine_clean_text($t['subject_type'] ?? 'other', 60) ?? 'other') : 'other';
        if ($name === null || !preg_match('/^[a-z_]{1,60}$/', $type)) {
            return kn_engine_invalid("Term $i needs a canonical_name (at most 200 characters) and a subject_type of a-z_.");
        }
        $aliases = [];
        foreach (is_array($t['aliases'] ?? null) ? $t['aliases'] : [] as $a) {
            $a = kn_engine_clean_text($a, 200);
            if ($a !== null && $a !== $name) {
                $aliases[] = $a;
            }
        }
        $desc = isset($t['description']) ? kn_engine_clean_text($t['description'], 2000) : null;
        $clean[$name] = ['name' => $name, 'type' => $type, 'aliases' => array_values(array_unique($aliases)), 'description' => $desc];
    }
    $seed = kn_engine_seed_through_extraction(array_values(array_map(fn ($c) => ['name' => $c['name'], 'type' => $c['type']], $clean)));
    if (!$seed['ok']) {
        return $seed;
    }
    $out = [];
    $aliasMap = [];
    foreach ($clean as $c) {
        $found = kn_engine_find_subject($c['name']);
        if (!$found['ok']) {
            return $found;
        }
        $subject = $found['data']['subject'];
        if ($subject === null) {
            return kn_engine_result(false, 0, [], 'A seeded term is missing from the engine.', 'engine_error');
        }
        $description = trim(($c['description'] ?? '') . ($c['aliases'] ? "\nAlso called: " . implode('; ', $c['aliases']) : ''));
        $patch = ['type' => $c['type']];
        if ($description !== '') {
            $patch['description'] = $description;
        }
        $upd = kn_engine_request('PATCH', '/v1/subjects/' . (int) $subject['id'], [], $patch);
        if (!$upd['ok']) {
            return $upd;
        }
        foreach ($c['aliases'] as $a) {
            $aliasMap[$a] = $c['name'];
        }
        $out[] = ['canonical_name' => $c['name'], 'subject_id' => (int) $subject['id'], 'subject_type' => $c['type'], 'aliases' => $c['aliases']];
    }
    return kn_engine_result(true, 200, ['subjects' => $out, 'aliases' => $aliasMap, 'aliases_stored_in_engine' => false]);
}

/**
 * Names the canonical term after each alias a question uses ("Is ZP-9 covered?" → "Is ZP-9 (Zorblat Pump) covered?"),
 * because the engine finds a question's subjects by canonical name only. @param array<string,string> $aliases
 */
function kn_engine_expand_aliases(string $question, array $aliases): string
{
    uksort($aliases, fn ($a, $b) => mb_strlen((string) $b) <=> mb_strlen((string) $a));
    $added = [];
    foreach ($aliases as $alias => $canonical) {
        $alias = (string) $alias;
        if ($alias === '' || !is_string($canonical) || $canonical === '' || isset($added[$canonical])) {
            continue;
        }
        $re = '/(?<![\p{L}\p{N}])' . preg_quote($alias, '/') . '(?![\p{L}\p{N}])/iu';
        if (preg_match($re, $question) && mb_stripos($question, $canonical) === false) {
            $question = (string) preg_replace($re, '$0 (' . str_replace(['\\', '$'], ['\\\\', '\\$'], $canonical) . ')', $question, 1);
            $added[$canonical] = true;
        }
    }
    return $question;
}

// ---------------------------------------------------------------------------------------------------------------------
// Sections as documents
// ---------------------------------------------------------------------------------------------------------------------

/**
 * One section → one MaluDB document in the base's namespace (POST /v1/memory/documents: chunk, extract with the
 * namespace's compiled prompt, embed, ingest). The document is stamped document_type = the namespace so graph reads can
 * be confined to the base. $projects are focus-area project names (kn_engine_project_create()'s data['name']); $hints
 * ([{subject-type, subject-name}] or names) become the document's subject tags — the documents route takes no hints.
 * data: document_id, namespace, edges[], chunk_count, extractor, embedding_model (no usage: M2 is not on this route).
 */
function kn_engine_ingest_section(string $namespace, string $title, string $text, array $metadata = [], array $projects = [], array $hints = []): array
{
    if (!kn_engine_valid_namespace($namespace)) {
        return kn_engine_invalid('The namespace must be kb:<base id>.');
    }
    $title = kn_engine_clean_text($title, 500);
    if ($title === null) {
        return kn_engine_invalid('A section needs a title of at most 500 characters.');
    }
    if (trim($text) === '' || strlen($text) > 200000) {
        return kn_engine_invalid('A section needs text (at most 200,000 bytes).');
    }
    if (array_is_list($metadata) && $metadata !== []) {
        return kn_engine_invalid('metadata must be an object.');
    }
    $projectNames = [];
    foreach ($projects as $p) {
        $p = kn_engine_clean_text($p, 200);
        if ($p === null) {
            return kn_engine_invalid('Each project is a project name.');
        }
        $projectNames[] = $p;
    }
    $subjects = [];
    foreach ($hints as $h) {
        $name = is_array($h) ? kn_engine_clean_text($h['subject-name'] ?? ($h['name'] ?? null), 200) : kn_engine_clean_text($h, 200);
        if ($name === null) {
            return kn_engine_invalid('Each hint names a subject.');
        }
        $subjects[] = $name;
    }
    return kn_engine_request('POST', '/v1/memory/documents', [], [
        'title' => $title,
        'text' => $text,
        'namespace' => $namespace,
        'document_type' => $namespace,
        'metadata' => (object) $metadata,
        'projects' => array_values(array_unique($projectNames)),
        'subjects' => array_values(array_unique($subjects)),
    ], true);
}

/** GET /v1/documents/{id} — the document as MaluDB keeps it (title, tags, hash; not its metadata). */
function kn_engine_document(int $id): array
{
    if (!kn_engine_valid_id($id)) {
        return kn_engine_invalid('A document id is a positive integer.');
    }
    return kn_engine_request('GET', '/v1/documents/' . $id);
}

/** The base's documents (id, title, created_at), newest first; data['_total'] counts them all. */
function kn_engine_documents(string $namespace, int $limit = 50, int $offset = 0): array
{
    if (!kn_engine_valid_namespace($namespace)) {
        return kn_engine_invalid('The namespace must be kb:<base id>.');
    }
    return kn_engine_request('GET', '/v1/documents', [
        'document_type' => 'eq.' . $namespace, 'select' => 'id,title,created_at',
        'limit' => kn_engine_clamp($limit, 1, 200), 'offset' => max(0, $offset),
    ], null, false, ['Prefer: count=exact']);
}

/**
 * "Try my guidelines on this section": POST /v1/memory/ingest {preview: true}. MaluDB's preview calls no model and
 * writes nothing; it answers the generic ingest-contract prompt (data['system_prompt'], data['user_message'], counts).
 * The base's own extraction prompt — what a build sends — is added as data['documents_prompt'].
 */
function kn_engine_preview(string $namespace, string $text, array $hints = []): array
{
    if (!kn_engine_valid_namespace($namespace)) {
        return kn_engine_invalid('The namespace must be kb:<base id>.');
    }
    if (trim($text) === '' || strlen($text) > 200000) {
        return kn_engine_invalid('Preview needs text (at most 200,000 bytes).');
    }
    $h = [];
    foreach ($hints as $hint) {
        if (is_array($hint) && kn_engine_clean_text($hint['subject-name'] ?? null, 200) !== null) {
            $h[] = ['subject-type' => (string) ($hint['subject-type'] ?? 'other'), 'subject-name' => trim((string) $hint['subject-name'])];
        } elseif (is_string($hint) && trim($hint) !== '') {
            $h[] = ['subject-type' => 'other', 'subject-name' => trim($hint)];
        } else {
            return kn_engine_invalid('Each hint names a subject.');
        }
    }
    $r = kn_engine_request('POST', '/v1/memory/ingest', [], ['namespace' => $namespace, 'text' => $text, 'hints' => $h, 'preview' => true]);
    if (!$r['ok']) {
        return $r;
    }
    $cfg = kn_engine_get_namespace_config($namespace);
    $tpl = $cfg['ok'] ? ($cfg['data']['config']['prompt_template'] ?? null) : null;
    $r['data']['documents_prompt'] = is_string($tpl) ? str_replace(['{{chunk}}', '{{text}}'], $text, $tpl) : null;
    $r['data']['writes'] = false;
    return $r;
}

/** Removing a source: DELETE /v1/documents/{id} forgets the document, its chunks, its statements and its package. */
function kn_engine_forget_document(int $id): array
{
    if (!kn_engine_valid_id($id)) {
        return kn_engine_invalid('A document id is a positive integer.');
    }
    return kn_engine_request('DELETE', '/v1/documents/' . $id);
}

/**
 * POST /v1/memory/reindex/run — one batch of the engine's document reindex. CAUTION (reported): it re-extracts with the
 * tenant's `extract` task model and the generic ingest prompt, not a base's compiled prompt; with no `extract` model it
 * only lists the documents as skipped.
 */
function kn_engine_reindex(int $limit = 32): array
{
    return kn_engine_request('POST', '/v1/memory/reindex/run', ['limit' => kn_engine_clamp($limit, 1, 200)], null, true);
}

/** POST /v1/memory/embeddings/run — the entity-card queue; a no-op (with a note) when no real embedder is configured. */
function kn_engine_embeddings(int $limit = 64): array
{
    return kn_engine_request('POST', '/v1/memory/embeddings/run', ['limit' => kn_engine_clamp($limit, 1, 512)], null, true);
}

// ---------------------------------------------------------------------------------------------------------------------
// The graph
// ---------------------------------------------------------------------------------------------------------------------

/** Of these document ids, the ones in the namespace. @param list<int> $ids @return array<int,true>|null */
function kn_engine_documents_in(string $namespace, array $ids): ?array
{
    $ids = array_values(array_unique(array_filter($ids, fn ($i) => is_int($i) && $i > 0)));
    $in = [];
    foreach (array_chunk($ids, 150) as $chunk) {
        $r = kn_engine_request('GET', '/v1/documents', ['id' => 'in.(' . implode(',', $chunk) . ')', 'document_type' => 'eq.' . $namespace, 'select' => 'id', 'limit' => 200]);
        if (!$r['ok']) {
            return null;
        }
        foreach ($r['data']['documents'] ?? [] as $d) {
            $in[(int) $d['id']] = true;
        }
    }
    return $in;
}

/** Graph stats (tenant-wide, as the engine counts them) plus, with a namespace, the base's own document count. */
function kn_engine_stats(?string $namespace = null): array
{
    if ($namespace !== null && !kn_engine_valid_namespace($namespace)) {
        return kn_engine_invalid('The namespace must be kb:<base id>.');
    }
    $r = kn_engine_request('GET', '/v1/graph/stats');
    if (!$r['ok'] || $namespace === null) {
        return $r;
    }
    $docs = kn_engine_request('GET', '/v1/documents', ['document_type' => 'eq.' . $namespace, 'select' => 'id', 'limit' => 1], null, false, ['Prefer: count=exact']);
    if (!$docs['ok']) {
        return $docs;
    }
    $r['data']['namespace'] = $namespace;
    $r['data']['documents'] = (int) ($docs['data']['_total'] ?? count($docs['data']['documents'] ?? []));
    return $r;
}

/**
 * The question's neighbourhood (GET /v1/graph/query, asked tenant-wide because its `namespace` is a name prefix), then
 * confined to the base: a document node stays only if it is the namespace's, a subject node only if an edge joins it to
 * one of those documents.
 */
function kn_engine_graph_query(string $question, string $namespace, int $depth = 2, int $maxNodes = 50): array
{
    if (!kn_engine_valid_namespace($namespace)) {
        return kn_engine_invalid('The namespace must be kb:<base id>.');
    }
    $q = kn_engine_clean_text($question, 400);
    if ($q === null || !preg_match('/[a-z0-9_]{2}/i', $q)) {
        return kn_engine_invalid('A graph question is 2 to 400 characters with a searchable word.');
    }
    $r = kn_engine_request('GET', '/v1/graph/query', ['q' => $q, 'depth' => kn_engine_clamp($depth, 1, 6), 'max_nodes' => kn_engine_clamp($maxNodes * 4, 1, 500)]);
    if (!$r['ok']) {
        return $r;
    }
    $nodes = $r['data']['nodes'] ?? [];
    $edges = $r['data']['edges'] ?? [];
    $docIds = [];
    foreach ($nodes as $n) {
        if (($n['object_kind'] ?? '') === 'document') {
            $docIds[] = (int) $n['object_id'];
        }
    }
    $inNs = kn_engine_documents_in($namespace, $docIds);
    if ($inNs === null) {
        return kn_engine_result(false, 0, [], 'The base\'s documents could not be read.', 'engine_error');
    }
    $keep = [];
    foreach ($edges as $e) {
        foreach ([['source', 'target'], ['target', 'source']] as [$a, $b]) {
            if (($e[$a . '_kind'] ?? '') === 'document' && isset($inNs[(int) $e[$a . '_id']])) {
                $keep[$e[$b . '_kind'] . ':' . (int) $e[$b . '_id']] = true;
                $keep['document:' . (int) $e[$a . '_id']] = true;
            }
        }
    }
    $nodes = array_values(array_filter($nodes, fn ($n) => isset($keep[$n['object_kind'] . ':' . (int) $n['object_id']])));
    $nodes = array_slice($nodes, 0, kn_engine_clamp($maxNodes, 1, 500));
    $kept = [];
    foreach ($nodes as $n) {
        $kept[$n['object_kind'] . ':' . (int) $n['object_id']] = true;
    }
    $edges = array_values(array_filter($edges, fn ($e) => isset($kept[$e['source_kind'] . ':' . (int) $e['source_id']], $kept[$e['target_kind'] . ':' . (int) $e['target_id']])));
    $seeds = array_values(array_filter($r['data']['seeds'] ?? [], fn ($s) => isset($kept['subject:' . (int) $s['subject_id']])));
    return kn_engine_result(true, 200, ['query' => $q, 'namespace' => $namespace, 'seeds' => $seeds, 'nodes' => $nodes, 'edges' => $edges]);
}

function kn_engine_communities(string $namespace): array
{
    if (!kn_engine_valid_namespace($namespace)) {
        return kn_engine_invalid('The namespace must be kb:<base id>.');
    }
    return kn_engine_request('GET', '/v1/communities', ['namespace' => $namespace]);
}

function kn_engine_community_members(int $id, int $limit = 200): array
{
    if (!kn_engine_valid_id($id)) {
        return kn_engine_invalid('A community id is a positive integer.');
    }
    return kn_engine_request('GET', '/v1/communities/' . $id . '/members', ['limit' => kn_engine_clamp($limit, 1, 2000)]);
}

function kn_engine_neighbors(string $kind, int $id): array
{
    if (!kn_engine_valid_kind($kind) || !kn_engine_valid_id($id)) {
        return kn_engine_invalid('A node is a kind (a-z_) and a positive id.');
    }
    return kn_engine_request('GET', '/v1/graph/neighbors', ['kind' => $kind, 'id' => $id]);
}

/** @param array{0: string, 1: int} $a @param array{0: string, 1: int} $b  ([kind, id] each) */
function kn_engine_path(array $a, array $b, int $maxDepth = 6): array
{
    [$ak, $ai] = array_values($a) + [null, null];
    [$bk, $bi] = array_values($b) + [null, null];
    if (!kn_engine_valid_kind($ak) || !kn_engine_valid_id($ai) || !kn_engine_valid_kind($bk) || !kn_engine_valid_id($bi)) {
        return kn_engine_invalid('Each end of a path is [kind, positive id].');
    }
    return kn_engine_request('GET', '/v1/graph/path', [
        'source_kind' => $ak, 'source_id' => $ai, 'target_kind' => $bk, 'target_id' => $bi, 'max_depth' => kn_engine_clamp($maxDepth, 1, 32),
    ]);
}

/**
 * The most connected nodes. With a namespace (the explorer), confined to the base: its documents, and subjects one of
 * its documents touches (checked by neighbours, at most 40 candidates).
 */
function kn_engine_god_nodes(?string $namespace = null, int $limit = 10): array
{
    if ($namespace !== null && !kn_engine_valid_namespace($namespace)) {
        return kn_engine_invalid('The namespace must be kb:<base id>.');
    }
    $limit = kn_engine_clamp($limit, 1, 100);
    $r = kn_engine_request('GET', '/v1/graph/god-nodes', ['limit' => $namespace === null ? $limit : min(1000, $limit * 20)]);
    if (!$r['ok'] || $namespace === null) {
        return $r;
    }
    $rows = $r['data']['god_nodes'] ?? [];
    $docIds = array_map(fn ($n) => (int) $n['object_id'], array_filter($rows, fn ($n) => ($n['object_kind'] ?? '') === 'document'));
    $inNs = kn_engine_documents_in($namespace, $docIds);
    if ($inNs === null) {
        return kn_engine_result(false, 0, [], 'The base\'s documents could not be read.', 'engine_error');
    }
    $out = [];
    $checked = 0;
    foreach ($rows as $n) {
        if (count($out) >= $limit) {
            break;
        }
        $kind = (string) ($n['object_kind'] ?? '');
        if ($kind === 'document') {
            if (isset($inNs[(int) $n['object_id']])) {
                $out[] = $n;
            }
            continue;
        }
        if ($checked++ >= 40) {
            continue;
        }
        $nb = kn_engine_request('GET', '/v1/graph/neighbors', ['kind' => $kind, 'id' => (int) $n['object_id']]);
        if (!$nb['ok']) {
            continue;
        }
        $docs = array_map(fn ($x) => (int) $x['neighbor_id'], array_filter($nb['data']['neighbors'] ?? [], fn ($x) => ($x['neighbor_kind'] ?? '') === 'document'));
        $mine = $docs ? kn_engine_documents_in($namespace, $docs) : [];
        if ($mine) {
            $out[] = $n;
        }
    }
    return kn_engine_result(true, 200, ['namespace' => $namespace, 'limit' => $limit, 'god_nodes' => $out]);
}

function kn_engine_surprises(string $namespace, int $limit = 25): array
{
    if (!kn_engine_valid_namespace($namespace)) {
        return kn_engine_invalid('The namespace must be kb:<base id>.');
    }
    return kn_engine_request('GET', '/v1/graph/surprises', ['namespace' => $namespace, 'limit' => kn_engine_clamp($limit, 1, 200)]);
}

/** POST /v1/memory/recall over the given namespaces (the engine proposes the question's subjects when none are named). */
function kn_engine_recall(string $question, array $namespaces, int $limit = 8, array $subjects = []): array
{
    $q = kn_engine_clean_text($question, 2000);
    if ($q === null) {
        return kn_engine_invalid('A question is 1 to 2,000 characters.');
    }
    if ($namespaces === [] || count($namespaces) > 16) {
        return kn_engine_invalid('Between 1 and 16 namespaces.');
    }
    foreach ($namespaces as $ns) {
        if (!kn_engine_valid_namespace($ns)) {
            return kn_engine_invalid('Each namespace must be kb:<base id>.');
        }
    }
    $body = ['query' => $q, 'namespaces' => array_values(array_unique($namespaces)), 'limit' => kn_engine_clamp($limit, 1, 50)];
    $subjects = array_values(array_unique(array_filter(array_map(fn ($s) => kn_engine_clean_text($s, 200), $subjects))));
    if ($subjects) {
        $body['subjects'] = array_slice($subjects, 0, 8);
    }
    return kn_engine_request('POST', '/v1/memory/recall', [], $body);
}

/**
 * Evidence only — the fallback while the answer route is absent: the subjects the graph query seeds (names and the
 * engine's own aliases) and those the engine proposes, recalled in the namespace, ranked by similarity, one entry per
 * chunk, capped. data['evidence'][] = {n, chunk_id, document_id, statement_id, text, similarity, subject, verb,
 * document{title}}.
 */
function kn_engine_search(string $question, string $namespace, int $limit = 12): array
{
    if (!kn_engine_valid_namespace($namespace)) {
        return kn_engine_invalid('The namespace must be kb:<base id>.');
    }
    $q = kn_engine_clean_text($question, 2000);
    if ($q === null) {
        return kn_engine_invalid('A question is 1 to 2,000 characters.');
    }
    $limit = kn_engine_clamp($limit, 1, 30);
    $seeds = [];
    if (preg_match('/[a-z0-9_]{2}/i', $q)) {
        $g = kn_engine_request('GET', '/v1/graph/query', ['q' => mb_substr($q, 0, 400), 'depth' => 1, 'seeds' => 8, 'max_nodes' => 20]);
        if ($g['ok']) {
            foreach ($g['data']['seeds'] ?? [] as $s) {
                $seeds[] = (string) $s['canonical_name'];
            }
        } elseif (in_array($g['code'], ['engine_unavailable', 'engine_auth', 'engine_timeout', 'engine_forbidden'], true)) {
            return $g;
        }
    }
    $passes = [kn_engine_recall($q, [$namespace], $limit)];
    if ($seeds) {
        $passes[] = kn_engine_recall($q, [$namespace], $limit, $seeds);
    }
    $best = [];
    $tried = [];
    foreach ($passes as $p) {
        if (!$p['ok']) {
            return $p;
        }
        $tried = array_merge($tried, $p['data']['subjects_tried'] ?? []);
        foreach ($p['data']['results'] ?? [] as $row) {
            $chunk = (int) ($row['chunk_id'] ?? 0);
            if ($chunk <= 0 || trim((string) ($row['source_text'] ?? '')) === '') {
                continue;
            }
            if (!isset($best[$chunk]) || (float) $row['similarity'] > (float) $best[$chunk]['similarity']) {
                $best[$chunk] = $row;
            }
        }
    }
    usort($best, fn ($a, $b) => ((float) $b['similarity']) <=> ((float) $a['similarity']));
    $best = array_slice($best, 0, $limit);
    $titles = [];
    $docIds = array_values(array_unique(array_filter(array_map(fn ($r) => (int) ($r['document_id'] ?? 0), $best))));
    if ($docIds) {
        $t = kn_engine_request('GET', '/v1/documents', ['id' => 'in.(' . implode(',', $docIds) . ')', 'select' => 'id,title', 'limit' => 200]);
        foreach ($t['ok'] ? ($t['data']['documents'] ?? []) : [] as $d) {
            $titles[(int) $d['id']] = $d['title'];
        }
    }
    $evidence = [];
    foreach ($best as $i => $r) {
        $doc = (int) ($r['document_id'] ?? 0);
        $evidence[] = [
            'n' => $i + 1,
            'chunk_id' => (int) $r['chunk_id'],
            'document_id' => $doc ?: null,
            'statement_id' => isset($r['statement_id']) ? (int) $r['statement_id'] : null,
            'text' => (string) $r['source_text'],
            'similarity' => round((float) $r['similarity'], 4),
            'subject' => $r['subject_name'] ?? null,
            'verb' => $r['verb_name'] ?? null,
            'document' => $doc && isset($titles[$doc]) ? ['title' => $titles[$doc]] : null,
        ];
    }
    return kn_engine_result(true, 200, [
        'namespace' => $namespace, 'question' => $q, 'status' => $evidence ? 'evidence_only' : 'no_evidence',
        'subjects_tried' => array_values(array_unique($tried)), 'evidence' => $evidence,
    ]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Focus areas = MaluDB projects
// ---------------------------------------------------------------------------------------------------------------------

/** A focus area's engine name: the base's namespace, a slash, the area's name (projects are tenant-wide). */
function kn_engine_project_name(string $namespace, string $name): string
{
    return $namespace . '/' . $name;
}

/**
 * Creates the focus area's project (through the extraction path — see the header), sets its description and
 * classifier_md, and clears any links a reused id would otherwise inherit. data: project_id, name, cleared.
 */
function kn_engine_project_create(string $namespace, string $name, ?string $description = null, ?string $classifierMd = null): array
{
    if (!kn_engine_valid_namespace($namespace)) {
        return kn_engine_invalid('The namespace must be kb:<base id>.');
    }
    $name = kn_engine_clean_text($name, 150);
    if ($name === null) {
        return kn_engine_invalid('A focus area needs a name of at most 150 characters.');
    }
    if (($description !== null && mb_strlen($description) > 4000) || ($classifierMd !== null && mb_strlen($classifierMd) > 20000)) {
        return kn_engine_invalid('The description or classifier is too long.');
    }
    $full = kn_engine_project_name($namespace, $name);
    $existing = kn_engine_find_subject($full);
    if (!$existing['ok']) {
        return $existing;
    }
    if ($existing['data']['subject'] !== null && ($existing['data']['subject']['type'] ?? '') !== 'project') {
        return kn_engine_result(false, 409, [], 'A subject that is not a project already has that name.', 'conflict');
    }
    if ($existing['data']['subject'] === null) {
        $seed = kn_engine_seed_through_extraction([['name' => $full, 'type' => 'project']]);
        if (!$seed['ok']) {
            return $seed;
        }
    }
    $found = kn_engine_request('GET', '/v1/projects', ['name' => 'eq.' . $full, 'limit' => 2]);
    if (!$found['ok']) {
        return $found;
    }
    $pid = 0;
    foreach ($found['data']['projects'] ?? [] as $p) {
        if (($p['name'] ?? '') === $full) {
            $pid = (int) $p['id'];
        }
    }
    if ($pid <= 0) {
        return kn_engine_result(false, 0, [], 'The project was not created.', 'engine_error');
    }
    $patch = [];
    if ($description !== null) {
        $patch['description'] = $description;
    }
    if ($classifierMd !== null) {
        $patch['classifier_md'] = $classifierMd;
    }
    if ($patch) {
        $upd = kn_engine_request('PATCH', '/v1/projects/' . $pid, [], $patch);
        if (!$upd['ok']) {
            return $upd;
        }
    }
    $cleared = 0;
    if ($existing['data']['subject'] === null) {
        $detail = kn_engine_request('GET', '/v1/projects/' . $pid);
        if (!$detail['ok']) {
            return $detail;
        }
        foreach ($detail['data']['project']['subjects'] ?? [] as $stale) {
            $un = kn_engine_request('DELETE', '/v1/projects/' . $pid . '/subjects/' . (int) $stale['id']);
            if (!$un['ok'] && $un['code'] !== 'not_found') {
                return $un;
            }
            $cleared++;
        }
    }
    return kn_engine_result(true, 201, ['project_id' => $pid, 'name' => $full, 'created' => $existing['data']['subject'] === null, 'cleared' => $cleared]);
}

function kn_engine_project(int $projectId): array
{
    if (!kn_engine_valid_id($projectId)) {
        return kn_engine_invalid('A project id is a positive integer.');
    }
    return kn_engine_request('GET', '/v1/projects/' . $projectId);
}

/** Links a subject (a seed term) to the area's project; linking twice is not an error (data['already'] = true). */
function kn_engine_project_link_subject(int $projectId, int $subjectId): array
{
    if (!kn_engine_valid_id($projectId) || !kn_engine_valid_id($subjectId) || $projectId === $subjectId) {
        return kn_engine_invalid('A project id and a different subject id, both positive integers.');
    }
    $r = kn_engine_request('POST', '/v1/projects/' . $projectId . '/subjects', [], ['subject_id' => $subjectId]);
    if (!$r['ok'] && $r['code'] === 'conflict') {
        return kn_engine_result(true, 200, ['already' => true, 'project_id' => $projectId, 'subject_id' => $subjectId]);
    }
    return $r;
}

function kn_engine_project_unlink_subject(int $projectId, int $subjectId): array
{
    if (!kn_engine_valid_id($projectId) || !kn_engine_valid_id($subjectId)) {
        return kn_engine_invalid('Ids are positive integers.');
    }
    return kn_engine_request('DELETE', '/v1/projects/' . $projectId . '/subjects/' . $subjectId);
}

/** Deletes the project after unlinking every subject (DELETE alone would leave the links behind). data: unlinked. */
function kn_engine_project_delete(int $projectId): array
{
    if (!kn_engine_valid_id($projectId)) {
        return kn_engine_invalid('A project id is a positive integer.');
    }
    $detail = kn_engine_request('GET', '/v1/projects/' . $projectId);
    if (!$detail['ok']) {
        return $detail;
    }
    $unlinked = 0;
    foreach ($detail['data']['project']['subjects'] ?? [] as $s) {
        $un = kn_engine_request('DELETE', '/v1/projects/' . $projectId . '/subjects/' . (int) $s['id']);
        if (!$un['ok'] && $un['code'] !== 'not_found') {
            return $un;
        }
        $unlinked++;
    }
    $del = kn_engine_request('DELETE', '/v1/projects/' . $projectId);
    if (!$del['ok']) {
        return $del;
    }
    return kn_engine_result(true, 200, ['deleted' => true, 'project_id' => $projectId, 'unlinked' => $unlinked]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Answering (M1)
// ---------------------------------------------------------------------------------------------------------------------

/**
 * POST /v1/memory/answer (API 0.4.0). data = MaluDB's reply: status answered|no_evidence|unverified, answer_md,
 * confidence, citations[{n, quote, chunk_id, document_id, verified}], evidence[{n, chunk_id, document_id, text,
 * similarity, subject, verb, document{title, metadata}}], subjects_tried, model, usage{input_tokens, output_tokens,
 * calls}. An API without the route answers ok=false code 'answer_unavailable' — the caller falls back to
 * kn_engine_search(). $project is the area's project id (or its engine name); $history the last turns
 * [{role: user|assistant, content}]; $system the compiled answering + audience guidelines.
 */
function kn_engine_answer(string $namespace, string $question, int|string|null $project = null, array $history = [], string $system = '', int $maxEvidence = 12, float $minSimilarity = 0.2): array
{
    if (!kn_engine_valid_namespace($namespace)) {
        return kn_engine_invalid('The namespace must be kb:<base id>.');
    }
    $q = kn_engine_clean_text($question, 2000);
    if ($q === null) {
        return kn_engine_invalid('A question is 1 to 2,000 characters.');
    }
    if (mb_strlen($system) > 8000) {
        return kn_engine_invalid('The guidelines sent with a question are at most 8,000 characters.');
    }
    if ($minSimilarity < -1.0 || $minSimilarity > 1.0 || is_nan($minSimilarity)) {
        return kn_engine_invalid('min_similarity is a cosine from -1 to 1.');
    }
    $turns = [];
    foreach (array_slice($history, -6) as $h) {
        if (!is_array($h) || !in_array($h['role'] ?? null, ['user', 'assistant'], true) || !is_string($h['content'] ?? null) || trim($h['content']) === '') {
            return kn_engine_invalid('Each turn of history is {role: user|assistant, content}.');
        }
        $turns[] = ['role' => $h['role'], 'content' => mb_substr(trim($h['content']), 0, 2000)];
    }
    $body = [
        'namespace' => $namespace, 'question' => $q, 'history' => $turns,
        'max_evidence' => kn_engine_clamp($maxEvidence, 1, 30), 'min_similarity' => $minSimilarity,
    ];
    if (trim($system) !== '') {
        $body['system'] = trim($system);
    }
    if ($project !== null) {
        if (is_int($project) ? !kn_engine_valid_id($project) : kn_engine_clean_text($project, 200) === null) {
            return kn_engine_invalid('A project is a positive id or a project name.');
        }
        $body['project'] = $project;
    }
    $r = kn_engine_request('POST', '/v1/memory/answer', [], $body, true);
    if (!$r['ok']) {
        if ($r['code'] === 'route_not_found') {
            return kn_engine_result(false, $r['status'], [], 'This MaluDB API has no answer route (it needs 0.4.0).', 'answer_unavailable');
        }
        if ($r['code'] === 'not_found' && $project !== null) {
            return kn_engine_result(false, $r['status'], $r['data'], $r['error'], 'project_not_found');
        }
        return $r;
    }
    if (!in_array($r['data']['status'] ?? null, KN_ENGINE_ANSWER_STATUSES, true)) {
        return kn_engine_result(false, $r['status'], [], 'The engine answered an unknown status.', 'engine_error');
    }
    return $r;
}
