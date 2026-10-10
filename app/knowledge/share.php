<?php
declare(strict_types=1);

/**
 * The K7 shares — Knowledge as a provider (slice 7, docs/build-specs/api-keys-k7.md "K7"). A sibling application reads the bases shared with it through the
 * kernel's token: the records server's three share tools reach these functions through the engine bridge (ops share_ask, share_search, share_bases, caller
 * kind `application`). Who is asking comes from the kernel's header X-OS-Consumer (K26) — never an argument — and arrives here as $consumer.
 *   kn_share_bases_index(PDO, ?string $consumer)        os.knowledge-bases/1 — the bases in kn_application_base_ids($consumer), not archived
 *   kn_share_ask(PDO, ?string $consumer, array $args)   os.knowledge-answer/1 — kn_ask() with door `share`, application_key = $consumer, no member
 *   kn_share_search(PDO, ?string $consumer, array $args) os.knowledge-evidence/1 — kn_search() with door `share`
 *   share_log(PDO, ...)                                  `share.read` — every call, answered or refused: the consumer, the tool, the outcome, the milliseconds
 * The gate is the application principal alone (a base_members row with principal_kind application): an internal base is NOT shared with a sibling unless it
 * is named, and a guest's or an agent's share counts for nothing here. A refusal is a normal result — {schema, application, error: {code, message}} — never an
 * MCP error, so the kernel passes it back. The same words answer a base that does not exist and one that is not shared. Nobody's identity crosses; a sibling is
 * not rate-limited here (the kernel meters K7 calls). Nothing here ever logs a question, a query or an answer.
 */
require_once APP_ROOT . '/app/knowledge/ask.php';
require_once APP_ROOT . '/app/features/keys/present.php';

/** The seconds the engine may take for a share call: the kernel waits 60 for ask and search (K26), so the engine gets 50 — an override of the environment may only lower it. */
const KN_SHARE_ENGINE_BUDGET = 50;
const KN_SHARE_NOT_SHARED = 'No such base is shared with this application.';

/** share.read: the consumer, the tool, the outcome and the milliseconds — source `application`, no actor; base_id when a base was named and exists. */
function share_log(PDO $pdo, ?string $consumer, string $tool, ?int $baseId, string $outcome, int $ms): void
{
    log_activity($pdo, 'share.read', null, null, ['actor_member_id' => null, 'source' => 'application', 'base_id' => $baseId,
        'after' => ['application' => $consumer, 'tool' => $tool, 'outcome' => $outcome, 'ms' => $ms]]);
}

/** The consumer's own refusal, or null when it may ask: [outcome, error doc fields]. */
function kn_share_consumer_check(?string $consumer): ?array
{
    if ($consumer === null || trim($consumer) === '') {
        return ['consumer_unknown', 'consumer_unknown', 'The kernel did not say which application is asking.'];
    }
    if (preg_match('/^[a-z0-9_]{1,60}$/', $consumer) !== 1) {
        return ['invalid', 'invalid', 'The consumer is a catalog key: lower-case letters, digits and underscores, up to 60.'];
    }
    return null;
}

function kn_share_error(string $schema, string $code, string $message, array $fields = []): array
{
    return ['schema' => $schema, 'application' => 'knowledge', 'error' => ['code' => $code, 'message' => $message] + ($fields !== [] ? ['fields' => $fields] : [])];
}

/** A share's base named by key or id → its id, only inside the consumer's set (null otherwise: the same words for missing and not shared). */
function kn_share_base_id(PDO $pdo, string $consumer, mixed $base): ?int
{
    if (!is_scalar($base) || trim((string) $base) === '') {
        return null;
    }
    $b = trim((string) $base);
    $st = $pdo->prepare('SELECT b.id FROM knowledge_bases b WHERE b.id IN (SELECT kn_application_base_ids(:app)) AND (b.key = :k OR (CAST(:i AS bigint) IS NOT NULL AND b.id = CAST(:i AS bigint)))');
    $st->execute(['app' => $consumer, 'k' => $b, 'i' => ctype_digit($b) && strlen($b) < 18 ? $b : null]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int) $id;
}

/** Run $call with the engine's timeout held to the share's budget (restored after): a slower answer comes back failed / engine_timeout. */
function kn_share_budget(callable $call): mixed
{
    $was = getenv('MALUDB_KB_API_TIMEOUT');
    $set = ($was !== false && ctype_digit($was) && (int) $was < KN_SHARE_ENGINE_BUDGET) ? (int) $was : KN_SHARE_ENGINE_BUDGET;
    putenv('MALUDB_KB_API_TIMEOUT=' . $set);
    try {
        return $call();
    } finally {
        putenv($was === false ? 'MALUDB_KB_API_TIMEOUT' : 'MALUDB_KB_API_TIMEOUT=' . $was);
    }
}

function kn_share_ms(float $t0): int
{
    return (int) round((microtime(true) - $t0) * 1000);
}

function kn_share_bases_index(PDO $pdo, ?string $consumer): array
{
    $t0 = microtime(true);
    if (($bad = kn_share_consumer_check($consumer)) !== null) {
        share_log($pdo, $consumer, 'bases_index', null, $bad[0], kn_share_ms($t0));
        return kn_share_error('os.knowledge-bases/1', $bad[1], $bad[2]);
    }
    $st = $pdo->prepare('SELECT * FROM kn_application_bases(:app)');
    $st->execute(['app' => $consumer]);
    $doc = present_bases_document($st->fetchAll(PDO::FETCH_ASSOC));
    share_log($pdo, $consumer, 'bases_index', null, 'ok', kn_share_ms($t0));
    return $doc;
}

/** The outcome word of a finished ask or search: ok, or the engine's failure. */
function kn_share_outcome(array $doc): string
{
    return ($doc['status'] ?? '') === 'failed' && in_array($doc['error_code'] ?? '', ['engine_unavailable', 'engine_timeout'], true) ? (string) $doc['error_code'] : (($doc['status'] ?? '') === 'failed' ? 'engine_unavailable' : 'ok');
}

function kn_share_ask(PDO $pdo, ?string $consumer, array $args): array
{
    $t0 = microtime(true);
    $schema = 'os.knowledge-answer/1';
    if (($bad = kn_share_consumer_check($consumer)) !== null) {
        share_log($pdo, $consumer, 'ask', null, $bad[0], kn_share_ms($t0));
        return kn_share_error($schema, $bad[1], $bad[2]);
    }
    $baseId = kn_share_base_id($pdo, $consumer, $args['base'] ?? null);
    if ($baseId === null) {
        share_log($pdo, $consumer, 'ask', null, 'not_shared', kn_share_ms($t0));
        return kn_share_error($schema, 'not_shared', KN_SHARE_NOT_SHARED);
    }
    $area = $args['focus_area'] ?? null;
    $conv = $args['conversation_id'] ?? null;
    $r = kn_share_budget(static fn (): array => kn_ask($pdo, ['base_id' => $baseId, 'question' => is_string($args['question'] ?? null) ? $args['question'] : '',
        'focus_area_id' => is_scalar($area) ? (string) $area : null, 'conversation_id' => is_string($conv) ? $conv : null, 'door' => 'share', 'application_key' => $consumer]));
    if (!$r['ok']) {
        $invalid = $r['http'] === 422;
        share_log($pdo, $consumer, 'ask', $baseId, $invalid ? 'invalid' : 'not_shared', kn_share_ms($t0));
        return $invalid ? kn_share_error($schema, 'invalid', $r['error']['message'], (array) ($r['fields'] ?? [])) : kn_share_error($schema, 'not_shared', KN_SHARE_NOT_SHARED);
    }
    unset($r['ok'], $r['http'], $r['fields'], $r['error']);
    if ($r['status'] === 'failed' && $r['error_code'] === 'engine_timeout') {
        $r['message'] = 'The knowledge base could not be asked just now — the engine took too long to answer; call search for the passages.';
    }
    share_log($pdo, $consumer, 'ask', $baseId, kn_share_outcome($r), kn_share_ms($t0));
    return $r;
}

function kn_share_search(PDO $pdo, ?string $consumer, array $args): array
{
    $t0 = microtime(true);
    $schema = 'os.knowledge-evidence/1';
    if (($bad = kn_share_consumer_check($consumer)) !== null) {
        share_log($pdo, $consumer, 'search', null, $bad[0], kn_share_ms($t0));
        return kn_share_error($schema, $bad[1], $bad[2]);
    }
    $baseId = kn_share_base_id($pdo, $consumer, $args['base'] ?? null);
    if ($baseId === null) {
        share_log($pdo, $consumer, 'search', null, 'not_shared', kn_share_ms($t0));
        return kn_share_error($schema, 'not_shared', KN_SHARE_NOT_SHARED);
    }
    $area = $args['focus_area'] ?? null;
    $r = kn_share_budget(static fn (): array => kn_search($pdo, ['base_id' => $baseId, 'query' => is_string($args['query'] ?? null) ? $args['query'] : '',
        'focus_area_id' => is_scalar($area) ? (string) $area : null, 'limit' => is_numeric($args['limit'] ?? null) ? (int) $args['limit'] : 8, 'door' => 'share', 'application_key' => $consumer]));
    if (!$r['ok']) {
        $invalid = $r['http'] === 422;
        share_log($pdo, $consumer, 'search', $baseId, $invalid ? 'invalid' : 'not_shared', kn_share_ms($t0));
        return $invalid ? kn_share_error($schema, 'invalid', $r['error']['message'], (array) ($r['fields'] ?? [])) : kn_share_error($schema, 'not_shared', KN_SHARE_NOT_SHARED);
    }
    unset($r['ok'], $r['http']);
    share_log($pdo, $consumer, 'search', $baseId, kn_share_outcome($r), kn_share_ms($t0));
    return $r;
}
