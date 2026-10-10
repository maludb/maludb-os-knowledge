<?php
declare(strict_types=1);

/**
 * Asking — THE ONE FUNCTION BEHIND EVERY DOOR (slice 5, docs/build-specs/ask.md "The flow of kn_ask()"). The window, the token API
 * (slice 7), the records MCP `ask` tool and the K7 share (through the bridge), the expert's turns: all call kn_ask(); the evidence door
 * and the fallback call kn_search(). The gate, the history, the engine call, the mapping to sections, the rows and the log are here, once.
 *   kn_ask(PDO, $req)     $req: base_id, question, focus_area_id (?int|?string slug), conversation_id, door (web|api|mcp|agent|share),
 *                         token_id (api), application_key (share), is_eval. The caller is whoever app.member_id names (none for a share).
 *   kn_search(PDO, $req)  $req: base_id, query, focus_area_id, limit, door, token_id, application_key — os.knowledge-evidence/1; no row.
 * Both answer the document (app/features/ask/present.php) plus 'ok' and 'http'; a gate refusal is ['ok' => false, 'http', 'error' =>
 * [code, message], 'fields' => […]] and writes nothing. No engine text, quote or answer ever reaches a log row (ask_log()).
 */
require_once APP_ROOT . '/app/knowledge/engine.php';
require_once APP_ROOT . '/app/knowledge/compile.php';
require_once APP_ROOT . '/app/features/ask/present.php';
require_once APP_ROOT . '/app/features/ask/write.php';

const KN_ASK_DOORS = ['web', 'api', 'mcp', 'agent', 'share'];
const KN_ASK_EVIDENCE_MAX = 12;
const KN_ASK_EVIDENCE_CHARS = 1500;
const KN_ASK_QUOTE_CHARS = 1000;
const KN_ASK_NO_ANSWER_TURN = 'The knowledge base held nothing on this.';
/** Engine codes that fall back to the evidence search (divergence 7b). */
const KN_ASK_FALLBACK = ['answer_unavailable', 'model_failed', 'model_not_configured', 'model_api_key_missing'];

function kn_ask_settings(PDO $pdo): array
{
    static $s = null;
    return $s ??= $pdo->query('SELECT max_question_chars, conversation_turns, evidence_threshold, max_evidence FROM kn_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** The refusal shape of the gate. */
function kn_ask_refuse(int $http, string $code, string $message, array $fields = []): array
{
    return ['ok' => false, 'http' => $http, 'error' => ['code' => $code, 'message' => $message], 'fields' => $fields];
}

/**
 * The gate (step 1), shared by kn_ask() and kn_search(): the base reached by the door, the words' length, the area. Answers
 * ['ok' => true, 'base' => row, 'area' => ?row, 'text' => trimmed] or the refusal.
 */
function kn_ask_gate(PDO $pdo, array $req, string $textKey): array
{
    $door = (string) ($req['door'] ?? '');
    if (!in_array($door, KN_ASK_DOORS, true)) {
        return kn_ask_refuse(500, 'invalid_door', 'Unknown door.');
    }
    $baseId = (int) ($req['base_id'] ?? 0);
    $st = $pdo->prepare('SELECT id, key, name, namespace, archived_at, guideline_version FROM knowledge_bases WHERE id = :b');
    $st->execute(['b' => $baseId]);
    $base = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $reach = false;
    if ($base !== null && $base['archived_at'] === null) {
        $reach = match ($door) {
            'web', 'mcp', 'agent' => db_bool($pdo, 'SELECT kn_can_read_base(:b)', ['b' => $baseId]),
            'api' => isset($req['token_id']) && db_bool($pdo, 'SELECT kn_key_reaches_base(:t, :b) AND kn_can_read_base(:b)', ['t' => (int) $req['token_id'], 'b' => $baseId]),
            'share' => isset($req['application_key']) && db_bool($pdo, 'SELECT :b IN (SELECT kn_application_base_ids(:a))', ['b' => $baseId, 'a' => (string) $req['application_key']]),
        };
    }
    if (!$reach) {
        return kn_ask_refuse(404, 'not_found', 'Knowledge base not found.');
    }
    $limit = min((int) (kn_ask_settings($pdo)['max_question_chars'] ?? 2000), 2000);
    $text = trim((string) ($req[$textKey] ?? ''));
    if ($text === '' || mb_strlen($text) > $limit) {
        return kn_ask_refuse(422, 'invalid', 'A question is 1 to ' . number_format($limit) . ' characters.', [$textKey === 'query' ? 'query' : 'question' => 'A question is 1 to ' . number_format($limit) . ' characters.']);
    }
    $named = $req['focus_area_id'] ?? null;
    $named = ($named === '' ? null : $named);
    $keyArea = null;
    if ($door === 'api' && isset($req['token_id'])) {
        $keyArea = one_value($pdo, 'SELECT focus_area_id FROM mcp_access_tokens WHERE id = :t', ['t' => (int) $req['token_id']]);
        $keyArea = $keyArea === null ? null : (int) $keyArea;
    }
    $area = null;
    if ($named !== null) {
        $st = $pdo->prepare("SELECT id, name, slug, maludb_project_id FROM focus_areas WHERE base_id = :b AND status = 'active' AND (" . (ctype_digit((string) $named) ? 'id = :a' : 'slug = :a') . ')');
        $st->execute(['b' => $baseId, 'a' => ctype_digit((string) $named) ? (int) $named : (string) $named]);
        $area = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($area === null) {
            return kn_ask_refuse(422, 'invalid', 'That focus area is not active in this base.', ['focus_area' => 'That focus area is not active in this base.']);
        }
        if ($keyArea !== null && (int) $area['id'] !== $keyArea) {
            return kn_ask_refuse(403, 'area_not_allowed', 'This key asks one focus area only.');
        }
    } elseif ($keyArea !== null) {
        $st = $pdo->prepare('SELECT id, name, slug, maludb_project_id FROM focus_areas WHERE id = :a');
        $st->execute(['a' => $keyArea]);
        $area = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return ['ok' => true, 'base' => $base, 'area' => $area, 'text' => $text];
}

/** A new conversation id: c_ and 24 hex characters. */
function kn_ask_new_conversation_id(): string
{
    return 'c_' . bin2hex(random_bytes(12));
}

/** The SQL condition and arguments that say "asked by this same identity" (a person or agent; a key or the key it was rotated from; a sibling). */
function kn_ask_identity(PDO $pdo, array $req): array
{
    return match ((string) $req['door']) {
        'api' => ['q.token_id IN (:t, (SELECT rotated_from FROM mcp_access_tokens WHERE id = :t))', ['t' => (int) $req['token_id']]],
        'share' => ['q.application_key = :app AND q.door = \'share\'', ['app' => (string) $req['application_key']]],
        default => ['q.asked_by_member_id = :me AND q.door IN (\'web\', \'mcp\', \'agent\')', ['me' => (int) current_member_id()]],
    };
}

/** The conversation to continue, or a new one: [id, turn_no]. Anyone else's id silently starts a new conversation. */
function kn_ask_conversation(PDO $pdo, array $req, int $baseId): array
{
    $c = trim((string) ($req['conversation_id'] ?? ''));
    if ($c !== '' && mb_strlen($c) <= 120) {
        [$who, $args] = kn_ask_identity($pdo, $req);
        $st = $pdo->prepare("SELECT max(q.turn_no) FROM questions q WHERE q.conversation_id = :c AND q.base_id = :b AND $who");
        $st->execute(['c' => $c, 'b' => $baseId] + $args);
        $max = $st->fetchColumn();
        if ($max !== null && $max !== false) {
            return [$c, (int) $max + 1];
        }
    }
    return [kn_ask_new_conversation_id(), 1];
}

/**
 * The last turns of a conversation (step 3), for the engine: [{role: user, content: question}, {role: assistant, content: answer_md or the
 * fixed sentence}] in turn order, at most $turns turns (six entries). Only this identity's turns are read.
 */
function kn_ask_history(PDO $pdo, string $conversationId, array $identity, int $turns): array
{
    if ($turns <= 0) {
        return [];
    }
    [$who, $args] = kn_ask_identity($pdo, $identity);
    $st = $pdo->prepare("SELECT q.question, q.answer_md, q.status FROM questions q WHERE q.conversation_id = :c AND q.base_id = :b AND $who ORDER BY q.turn_no DESC LIMIT " . min($turns, 3));
    $st->execute(['c' => $conversationId, 'b' => (int) $identity['base_id']] + $args);
    $out = [];
    foreach (array_reverse($st->fetchAll(PDO::FETCH_ASSOC)) as $r) {
        $out[] = ['role' => 'user', 'content' => (string) $r['question']];
        $out[] = ['role' => 'assistant', 'content' => $r['status'] === 'answered' && trim((string) $r['answer_md']) !== '' ? (string) $r['answer_md'] : KN_ASK_NO_ANSWER_TURN];
    }
    return array_slice($out, -6);
}

/**
 * Evidence → sections (step 8). Each item kept carries its section; an item of another base, of no section, or of a forgotten one is
 * dropped (its text goes nowhere). Answers ['items' => [...], 'dropped' => n], at most 12 items of at most 1,500 characters.
 */
function kn_ask_map_evidence(PDO $pdo, int $baseId, array $evidence): array
{
    $byId = $pdo->prepare("SELECT x.id, x.source_id, x.heading_path, x.page_no, x.status, s.title AS source_title, s.kind AS source_kind FROM sections x JOIN sources s ON s.id = x.source_id WHERE x.id = :s AND x.base_id = :b");
    $byDoc = $pdo->prepare("SELECT x.id, x.source_id, x.heading_path, x.page_no, x.status, s.title AS source_title, s.kind AS source_kind FROM sections x JOIN sources s ON s.id = x.source_id
                             WHERE x.base_id = :b AND x.maludb_document_id = :d AND x.status <> 'forgotten' ORDER BY x.id DESC LIMIT 1");
    $items = [];
    $dropped = 0;
    foreach ($evidence as $e) {
        if (!is_array($e)) {
            $dropped++;
            continue;
        }
        $meta = is_array($e['document']['metadata'] ?? null) ? $e['document']['metadata'] : [];
        if (isset($meta['base']) && (int) $meta['base'] !== $baseId) {
            $dropped++;                                        // another base's passage: never shown, stored or logged
            continue;
        }
        $sec = null;
        if (isset($meta['section'])) {
            $byId->execute(['s' => (int) $meta['section'], 'b' => $baseId]);
            $sec = $byId->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($sec === null && isset($e['document_id'])) {
            $byDoc->execute(['b' => $baseId, 'd' => (string) (int) $e['document_id']]);
            $sec = $byDoc->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($sec === null || $sec['status'] === 'forgotten') {
            $dropped++;
            continue;
        }
        if (count($items) >= KN_ASK_EVIDENCE_MAX) {
            continue;
        }
        $items[] = [
            'n' => (int) ($e['n'] ?? count($items) + 1), 'text' => mb_substr((string) ($e['text'] ?? ''), 0, KN_ASK_EVIDENCE_CHARS),
            'similarity' => isset($e['similarity']) ? round((float) $e['similarity'], 4) : null, 'chunk_id' => isset($e['chunk_id']) ? (string) $e['chunk_id'] : null,
            'document_id' => isset($e['document_id']) ? (int) $e['document_id'] : null, 'section_id' => (int) $sec['id'], 'source_id' => (int) $sec['source_id'],
            'source_title' => (string) $sec['source_title'], 'source_kind' => (string) $sec['source_kind'], 'heading_path' => (string) $sec['heading_path'],
            'page_no' => isset($meta['page']) && $meta['page'] !== null ? (int) $meta['page'] : ($sec['page_no'] !== null ? (int) $sec['page_no'] : null),
            'subject' => $e['subject'] ?? null, 'verb' => $e['verb'] ?? null,
        ];
    }
    return ['items' => $items, 'dropped' => $dropped];
}

/**
 * The engine's citations → rows (step 8): each rests on the mapped item with its n, else the item with its document id; one resting on a
 * dropped item is not written and counted. Ranks are 1..k in the engine's order. Answers ['rows' => [rank, section_id, source_id, page_no,
 * quote, maludb_chunk_id, verified, n (the evidence number it rests on), cn (the citation's own n — the answer's marker)], 'dropped' => n].
 */
function kn_ask_map_citations(PDO $pdo, int $baseId, array $citations, array $mapped): array
{
    $byN = [];
    $byDoc = [];
    foreach ($mapped as $m) {
        $byN[$m['n']] ??= $m;
        if ($m['document_id'] !== null) {
            $byDoc[$m['document_id']] ??= $m;
        }
    }
    $rows = [];
    $dropped = 0;
    foreach ($citations as $c) {
        if (!is_array($c)) {
            $dropped++;
            continue;
        }
        $item = $byN[(int) ($c['n'] ?? 0)] ?? (isset($c['document_id']) ? ($byDoc[(int) $c['document_id']] ?? null) : null);
        if ($item === null || count($rows) >= KN_ASK_EVIDENCE_MAX) {
            $dropped += $item === null ? 1 : 0;
            continue;
        }
        $rows[] = ['rank' => count($rows) + 1, 'n' => $item['n'], 'cn' => (int) ($c['n'] ?? 0), 'section_id' => $item['section_id'], 'source_id' => $item['source_id'], 'page_no' => $item['page_no'],
                   'quote' => mb_substr((string) ($c['quote'] ?? ''), 0, KN_ASK_QUOTE_CHARS), 'maludb_chunk_id' => isset($c['chunk_id']) ? (string) $c['chunk_id'] : $item['chunk_id'],
                   'verified' => (bool) ($c['verified'] ?? false)];
    }
    return ['rows' => $rows, 'dropped' => $dropped];
}

/**
 * The answer's [n] markers renumbered to the citations' ranks (so a marker, a chip and the API's citation `n` agree); a marker whose
 * evidence was not cited or was dropped is removed. Words are never changed.
 */
function kn_ask_renumber(string $md, array $citationRows): string
{
    $map = [];
    foreach ($citationRows as $c) {
        $map[$c['cn'] ?? $c['n']] ??= $c['rank'];             // the marker is the citation's own n
    }
    return (string) preg_replace_callback('/\s?\[(\d{1,2})\]/', static fn ($m) => isset($map[(int) $m[1]]) ? str_replace('[' . $m[1] . ']', '[' . $map[(int) $m[1]] . ']', $m[0]) : '', $md);
}

/** Every mapped passage as a citation of an evidence-only answer: rank = n order, the passage's first 500 characters, not verified. */
function kn_ask_evidence_citations(array $items): array
{
    $rows = [];
    foreach ($items as $i => $m) {
        $rows[] = ['rank' => $i + 1, 'n' => $m['n'], 'section_id' => $m['section_id'], 'source_id' => $m['source_id'], 'page_no' => $m['page_no'],
                   'quote' => mb_substr($m['text'], 0, 500), 'maludb_chunk_id' => $m['chunk_id'], 'verified' => false];
    }
    return $rows;
}

// ---------------------------------------------------------------------------------------------------------------------

function kn_ask(PDO $pdo, array $req): array
{
    $t0 = microtime(true);
    $gate = kn_ask_gate($pdo, $req, 'question');
    if (!$gate['ok']) {
        return $gate;
    }
    $base = $gate['base'];
    $area = $gate['area'];
    $baseId = (int) $base['id'];
    $question = $gate['text'];
    $door = (string) $req['door'];
    $eval = !empty($req['is_eval']);
    $set = kn_ask_settings($pdo);
    $req['base_id'] = $baseId;
    [$conversationId, $turnNo] = kn_ask_conversation($pdo, $req, $baseId);
    $status = null;
    $errorCode = null;
    $answer = null;
    $confidence = null;
    $evidence = ['items' => [], 'dropped' => 0];
    $cites = ['rows' => [], 'dropped' => 0];
    $model = null;
    $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'calls' => 0];
    $truncated = [];
    // step 2: can the base answer at all?
    if (!db_bool($pdo, "SELECT EXISTS (SELECT 1 FROM sections WHERE base_id = :b AND status IN ('extracted', 'stale'))", ['b' => $baseId])) {
        [$status, $errorCode] = ['refused', 'base_not_built'];
    } elseif ($area !== null && ($area['maludb_project_id'] ?? null) === null) {
        [$status, $errorCode] = ['refused', 'area_not_built'];
    }
    if ($status === null) {
        // steps 3–6
        $history = kn_ask_history($pdo, $conversationId, $req, (int) ($set['conversation_turns'] ?? 3));
        $expanded = kn_engine_expand_aliases($question, kn_compile_vocabulary($pdo, $baseId)['aliases']);
        $compiled = kn_compile_answer_system($pdo, $baseId, $area !== null ? (int) $area['id'] : null);
        $truncated = $compiled['truncated'];
        $project = $area === null ? null : (ctype_digit((string) $area['maludb_project_id']) ? (int) $area['maludb_project_id'] : (string) $area['maludb_project_id']);
        $r = kn_engine_answer((string) $base['namespace'], $expanded, $project, $history, $compiled['system'], (int) ($set['max_evidence'] ?? 8), (float) ($set['evidence_threshold'] ?? 0.6));
        // step 7
        if ($r['ok']) {
            $d = $r['data'];
            $model = $d['model'] ?? null;
            $usage = ['input_tokens' => (int) ($d['usage']['input_tokens'] ?? 0), 'output_tokens' => (int) ($d['usage']['output_tokens'] ?? 0), 'calls' => (int) ($d['usage']['calls'] ?? 0)];
            $evidence = kn_ask_map_evidence($pdo, $baseId, (array) ($d['evidence'] ?? []));
            if ($d['status'] === 'answered') {
                $cites = kn_ask_map_citations($pdo, $baseId, (array) ($d['citations'] ?? []), $evidence['items']);
                if ($cites['rows'] === []) {
                    [$status, $errorCode] = ['unverified', 'citations_unmapped'];
                } else {
                    $status = 'answered';
                    $answer = mb_substr(kn_ask_renumber((string) ($d['answer_md'] ?? ''), $cites['rows']), 0, 16000);
                    $confidence = isset($d['confidence']) ? max(0.0, min(1.0, (float) $d['confidence'])) : null;
                }
            } else {
                $status = (string) $d['status'];             // no_evidence | unverified: the answer is never kept
            }
        } elseif (in_array($r['code'], KN_ASK_FALLBACK, true)) {
            $errorCode = (string) $r['code'];
            $s = kn_engine_search($expanded, (string) $base['namespace'], KN_ASK_EVIDENCE_MAX);
            if ($s['ok']) {
                $evidence = kn_ask_map_evidence($pdo, $baseId, (array) ($s['data']['evidence'] ?? []));
                $status = $evidence['items'] === [] ? 'no_evidence' : 'evidence_only';
                $cites = ['rows' => kn_ask_evidence_citations($evidence['items']), 'dropped' => 0];
            } else {
                [$status, $errorCode] = ['failed', (string) $s['code']];
            }
        } elseif ($r['code'] === 'project_not_found') {
            [$status, $errorCode] = ['refused', 'area_not_built'];
        } elseif (in_array($r['code'], ['invalid_argument', 'validation_failed'], true)) {
            [$status, $errorCode] = ['refused', (string) $r['code']];
        } else {
            [$status, $errorCode] = ['failed', (string) ($r['code'] ?: 'engine_error')];
        }
    }
    $dropped = $evidence['dropped'] + $cites['dropped'];
    $latency = (int) round((microtime(true) - $t0) * 1000);
    $fullUsage = $usage + ['fallback' => in_array($errorCode, KN_ASK_FALLBACK, true) ? $errorCode : null, 'dropped' => $dropped, 'system_truncated' => $truncated ?: null];
    $questionId = null;
    if (!$eval) {
        // step 9: the rows, one transaction
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('INSERT INTO questions (base_id, focus_area_id, asked_by_member_id, token_id, application_key, conversation_id, turn_no, door, question, answer_md,
                                                        status, confidence, evidence, model, latency_ms, usage, error)
                                 VALUES (:b, :a, :m, :t, :app, :c, :turn, :door, :q, :ans, :st, :conf, :ev, :model, :lat, :usage, :err) RETURNING id');
            $st->execute(['b' => $baseId, 'a' => $area !== null ? (int) $area['id'] : null, 'm' => in_array($door, ['web', 'mcp', 'agent'], true) ? current_member_id() : null,
                't' => $door === 'api' ? (int) $req['token_id'] : null, 'app' => $door === 'share' ? (string) $req['application_key'] : null, 'c' => $conversationId,
                'turn' => $turnNo, 'door' => $door, 'q' => $question, 'ans' => $answer, 'st' => $status, 'conf' => $confidence,
                'ev' => json_encode($evidence['items'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'model' => $model, 'lat' => $latency,
                'usage' => json_encode($fullUsage, JSON_UNESCAPED_UNICODE), 'err' => $errorCode]);
            $questionId = (int) $st->fetchColumn();
            $ins = $pdo->prepare('INSERT INTO citations (question_id, rank, section_id, source_id, page_no, quote, maludb_chunk_id, verified) VALUES (:q, :r, :s, :src, :p, :quote, :chunk, :v)');
            foreach ($cites['rows'] as $c) {
                $ins->execute(['q' => $questionId, 'r' => $c['rank'], 's' => $c['section_id'], 'src' => $c['source_id'], 'p' => $c['page_no'], 'quote' => $c['quote'],
                               'chunk' => $c['maludb_chunk_id'], 'v' => $c['verified'] ? 'true' : 'false']);
            }
            if ($usage['calls'] > 0) {
                $pdo->prepare("SELECT kn_usage_add(:b, 'answer', :c, :i, :o, :n)")->execute(['b' => $baseId, 'c' => $usage['calls'], 'i' => $usage['input_tokens'], 'o' => $usage['output_tokens'], 'n' => mb_strlen($question)]);
            }
            ask_log($pdo, $req, $baseId, $questionId, $area, $conversationId, $turnNo, $status, $errorCode, $cites['rows'], $latency, $question, $model, $usage);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    } else {
        ask_log($pdo, $req, $baseId, null, $area, $conversationId, $turnNo, $status, $errorCode, $cites['rows'], $latency, $question, $model, $usage);
    }
    $doc = ask_present_turn($base, $area, [
        'question_id' => $questionId, 'conversation_id' => $conversationId, 'turn_no' => $turnNo, 'status' => $status, 'answer_md' => $answer,
        'confidence' => $confidence, 'guideline_version' => (int) $base['guideline_version'], 'error' => $errorCode, 'dropped' => $dropped,
    ], $cites['rows'], $evidence['items']);
    return ['ok' => true, 'http' => 200, 'error' => $errorCode !== null && in_array($status, ['failed', 'refused'], true) ? ['code' => $errorCode, 'message' => $doc['message']] : null] + $doc;
}

/** The evidence door (slice 7's /api/v1/search, the K7 search share) and nothing stored: os.knowledge-evidence/1 plus ok and http. */
function kn_search(PDO $pdo, array $req): array
{
    $gate = kn_ask_gate($pdo, $req, 'query');
    if (!$gate['ok']) {
        return $gate;
    }
    $base = $gate['base'];
    $baseId = (int) $base['id'];
    $limit = max(1, min(20, (int) ($req['limit'] ?? 8)));
    $expanded = kn_engine_expand_aliases($gate['text'], kn_compile_vocabulary($pdo, $baseId)['aliases']);
    $s = kn_engine_search($expanded, (string) $base['namespace'], $limit);
    $items = [];
    $tried = [];
    $status = 'failed';
    $code = null;
    if ($s['ok']) {
        $mapped = kn_ask_map_evidence($pdo, $baseId, (array) ($s['data']['evidence'] ?? []));
        $items = $mapped['items'];
        $tried = (array) ($s['data']['subjects_tried'] ?? []);
        $status = $items === [] ? 'no_evidence' : 'evidence_only';
    } else {
        $code = (string) $s['code'];
    }
    if (($req['door'] ?? '') === 'api') {
        log_activity($pdo, 'question.search', 'base', $baseId, ['base_id' => $baseId, 'token_id' => (int) $req['token_id'], 'source' => 'api', 'actor_member_id' => current_member_id(),
            'after' => ['result_count' => count($items), 'query_excerpt' => mb_substr($gate['text'], 0, 120), 'status' => $status]]);
    }
    return ['ok' => true, 'http' => 200] + ask_present_evidence($base, $gate['area'], $gate['text'], $status, $items, $tried, $code);
}
