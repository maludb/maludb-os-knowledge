<?php
declare(strict_types=1);

/**
 * The token API (mcp-and-api.md §6): /api/v1/*.php, a session or a Bearer KEY (scope 'api', bound to one base). One 401 body for every failure. CORS from an allow-list, never with credentials.
 */
require_once dirname(__DIR__) . '/bootstrap.php';

set_exception_handler(static function (Throwable $e): void {
    error_log('api error: ' . $e->getMessage());
    if (!headers_sent()) {
        api_error('server_error', 'Something went wrong.', 500);
    }
    exit;
});

function api_json(array $payload, int $status = 200): never
{
    json_response($payload, $status);
}

function api_error(string $code, string $message, int $status, array $extra = []): never
{
    json_error($code, $message, $status, $extra);
}

function api_cors(): void
{
    header('Vary: Origin');
    $configured = array_filter(array_map('trim', explode(',', (string) env('API_CORS_ORIGINS', ''))));
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && in_array($origin, $configured, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function api_require_get(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        api_error('method_not_allowed', 'Only GET is supported.', 405);
    }
}

function api_require_method(string ...$methods): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        api_error('method_not_allowed', 'Only ' . implode(' or ', $methods) . ' is supported.', 405);
    }
}

function api_bearer_token(): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }
    return preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) ? $m[1] : '';
}

/**
 * The caller as a member row, or 401 — a session first, then a Bearer token. Two kinds of bearer, never confused:
 *   - an `api` KEY (db/011) is bound to ONE base: it resolves through kn_resolve_key(), the key's facts land in $GLOBALS['__api_key']
 *     (token_id, base_id, focus_area_id, label, rate_per_minute, rate_per_day), $GLOBALS['__api_token_id'] stamps every log row, and the
 *     caller acts as the key's minter — but ONLY inside the key's base: a handler asks api_require_base() before it reads a base;
 *   - an `mcp` token is a person's read-only token for the MCP servers and is NOT a door to the token API (401 here).
 * Session callers (the page itself) are not keys and are held to the ordinary reach (can_read_base()).
 */
function api_authenticate(): array
{
    $pdo = db();
    if (is_logged_in() && empty($GLOBALS['__action_authed'])) {
        $member = current_member();
        if ($member !== null && $member['status'] === 'active' && $member['capability'] !== null) {
            return $member;
        }
    }
    $token = api_bearer_token();
    if ($token === '') {
        api_error('unauthorized', 'A valid API key is required.', 401);
    }
    $st = $pdo->prepare('SELECT token_id, member_id, base_id, focus_area_id, label, rate_per_minute, rate_per_day FROM kn_resolve_key(:h)');
    $st->execute(['h' => hash('sha256', $token)]);
    $key = $st->fetch();
    $member = $key === false ? null : find_member_by_id($pdo, (int) $key['member_id']);
    if ($key === false || $member === null) {
        api_error('unauthorized', 'A valid API key is required.', 401);
    }
    $_SESSION['member_id'] = (int) $member['id'];
    $GLOBALS['__api_token_authed'] = true;
    $GLOBALS['__api_token_id'] = (int) $key['token_id'];
    $GLOBALS['__api_key'] = ['token_id' => (int) $key['token_id'], 'base_id' => (int) $key['base_id'],
        'focus_area_id' => $key['focus_area_id'] === null ? null : (int) $key['focus_area_id'], 'label' => (string) $key['label']];
    $GLOBALS['__public_door'] = 'api';
    header_remove('Set-Cookie');
    db_apply_context($pdo);
    return $member;
}

/** Is this request a key's? (a session caller is not). */
function api_key(): ?array
{
    return $GLOBALS['__api_key'] ?? null;
}

/**
 * The base a call may touch: a key reaches ITS base and no other (kn_key_reaches_base — a 404 for any other, the same sentence as a
 * missing base: a key never learns that another base exists); a session caller reaches what can_read_base() says.
 */
function api_require_base(int $baseId): void
{
    $key = api_key();
    if ($key !== null) {
        if (!db_bool(db(), 'SELECT kn_key_reaches_base(:t, :b)', ['t' => $key['token_id'], 'b' => $baseId]) || !can_read_base($baseId)) {
            api_error('not_found', 'Knowledge base not found.', 404);
        }
        return;
    }
    if (!can_read_base($baseId)) {
        api_error('not_found', 'Knowledge base not found.', 404);
    }
}

/**
 * Count one call of a key into its minute and day buckets (kn_rate_ok); the 31st in a minute is a 429 with Retry-After and a body naming the bucket
 * (`limit`: minute | day). A session caller is not limited. The first refusal of a bucket is logged once (`key.rate_limited`: limit_hit, key_label,
 * retry_after) — when the bucket's `refused` has just become 1 — so a flood writes one row a minute, not thousands. A key revoked between its
 * resolution and its count is a 401 like any other.
 */
function api_rate_limit(): void
{
    $key = api_key();
    if ($key === null) {
        return;
    }
    $st = db()->prepare('SELECT ok, limit_hit, retry_after FROM kn_rate_ok(:t)');
    $st->execute(['t' => $key['token_id']]);
    $r = $st->fetch();
    if ($r !== false && $r['ok']) {
        return;
    }
    $hit = (string) ($r['limit_hit'] ?? 'minute');
    if (in_array($hit, ['no_key', 'revoked'], true)) {
        api_error('unauthorized', 'A valid API key is required.', 401);
    }
    $retry = max(1, (int) ($r['retry_after'] ?? 60));
    $first = db()->prepare('SELECT refused FROM key_usage WHERE token_id = :t AND bucket_kind = :k ORDER BY bucket_start DESC LIMIT 1');
    $first->execute(['t' => $key['token_id'], 'k' => $hit === 'day' ? 'day' : 'minute']);
    if ((int) $first->fetchColumn() === 1) {
        log_activity(db(), 'key.rate_limited', 'key', $key['token_id'], ['base_id' => $key['base_id'], 'after' => ['limit_hit' => $hit, 'key_label' => $key['label'], 'retry_after' => $retry]]);
    }
    header('Retry-After: ' . $retry);
    api_error('rate_limited', 'Too many calls (' . $hit . ').', 429, ['limit' => $hit]);
}
