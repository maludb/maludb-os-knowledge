<?php
declare(strict_types=1);

/**
 * Asking — what is shown and sent (slice 5). ask_present_turn() builds THE os.knowledge-answer/1 document every door answers (the window,
 * the API, the MCP `ask` tool, the K7 share); ask_present_evidence() the os.knowledge-evidence/1 one. One citation shape, one evidence
 * shape (docs/knowledge-mcp-tool-surface.md "The answer, the evidence and the bases"). No usage, model, asker or key in either.
 */

const QUESTION_STATUSES = ['answered' => ['answered', 'success'], 'evidence_only' => ['passages', 'info'], 'no_evidence' => ['nothing on this', 'secondary'],
    'unverified' => ['withheld', 'warning'], 'refused' => ['refused', 'dark'], 'failed' => ['failed', 'danger']];
const DISPATCH_STATUSES = ['sent' => ['working', 'secondary'], 'answered' => ['answered', 'success'], 'awaiting_approval' => ['waiting for approval', 'warning'],
    'refused' => ['refused', 'dark'], 'failed' => ['failed', 'danger']];

function question_chip(string $status): array { return QUESTION_STATUSES[$status] ?? [$status, 'secondary']; }
function dispatch_chip(string $status): array { return DISPATCH_STATUSES[$status] ?? [$status, 'secondary']; }

/** The plain words of a status (the card's and the document's `message`); null for an answer. */
function ask_status_words(string $status, ?string $error): ?string
{
    return match ($status) {
        'answered' => null,
        'evidence_only' => $error === 'answer_unavailable'
            ? 'This installation cannot compose answers yet, so here are the passages the base holds on your question.'
            : 'The model could not be reached, so here are the passages the base holds.',
        'no_evidence' => 'This knowledge base holds nothing on this. It has been noted so a curator can add a source.',
        'unverified' => 'An answer was drafted, but its quotes could not be checked against the sources, so it is withheld. Here is what the base holds.',
        'refused' => match ($error) {
            'base_not_built' => 'This base has no built documents yet.',
            'area_not_built' => 'This focus area is not in the graph yet — it is added at the next build.',
            default => 'The knowledge base could not take this question as it was asked.',
        },
        'failed' => 'The knowledge base could not be asked just now — ' . kn_engine_error_message($error),
        default => null,
    };
}

/** A section as the documents carry it. */
function ask_section_ref(array $x): array
{
    return ['id' => (int) $x['section_id'], 'heading_path' => (string) ($x['heading_path'] ?? ''),
            'source' => ['id' => (int) ($x['source_id'] ?? 0), 'title' => (string) ($x['source_title'] ?? ''), 'kind' => (string) ($x['source_kind'] ?? '')],
            'page' => isset($x['page_no']) && $x['page_no'] !== null ? (int) $x['page_no'] : null];
}

/** The section view at a quote: APP_URL/sections/{id}?quote=<the first 200 characters> (slice 2 marks it). */
function ask_cite_url(int $sectionId, string $quote, bool $absolute = true): string
{
    return ($absolute ? rtrim((string) env('APP_URL', ''), '/') : '') . '/sections/' . $sectionId . '?quote=' . rawurlencode(mb_substr($quote, 0, 200));
}

/**
 * The os.knowledge-answer/1 document. $q: question_id, conversation_id, turn_no, status, answer_md, confidence, guideline_version, error,
 * dropped. $citations: rows (rank, section_id, quote, verified …) joined with their section facts by the caller, or the kn_ask rows (the
 * section facts are then found in $evidence by section id). $evidence: the stored items.
 */
function ask_present_turn(array $base, ?array $area, array $q, array $citations, array $evidence): array
{
    $secs = [];
    foreach ($evidence as $e) {
        $secs[(int) $e['section_id']] ??= $e;
    }
    $cites = [];
    foreach ($citations as $c) {
        $s = isset($c['heading_path']) ? $c : (($secs[(int) $c['section_id']] ?? []) + $c);
        $cites[] = ['n' => (int) ($c['rank'] ?? 0), 'quote' => (string) $c['quote'], 'verified' => (bool) $c['verified'],
                    'section' => ask_section_ref($s + ['section_id' => $c['section_id']]), 'url' => ask_cite_url((int) $c['section_id'], (string) $c['quote'])];
    }
    $ev = array_map(static fn (array $e): array => ['n' => (int) $e['n'], 'text' => (string) $e['text'], 'similarity' => $e['similarity'] ?? null,
        'subject' => $e['subject'] ?? null, 'verb' => $e['verb'] ?? null, 'section' => ask_section_ref($e)], $evidence);
    $status = (string) $q['status'];
    return [
        'schema' => 'os.knowledge-answer/1', 'generated_at' => gmdate('c'), 'application' => 'knowledge',
        'base' => ['key' => (string) $base['key'], 'name' => (string) $base['name']],
        'focus_area' => $area === null ? null : ['slug' => (string) $area['slug'], 'name' => (string) $area['name']],
        'question_id' => $q['question_id'] === null ? null : (int) $q['question_id'], 'conversation_id' => (string) $q['conversation_id'], 'turn_no' => (int) $q['turn_no'],
        'status' => $status, 'answer_md' => $status === 'answered' ? $q['answer_md'] : null,
        'confidence' => $status === 'answered' && $q['confidence'] !== null ? round((float) $q['confidence'], 3) : null,
        'citations' => $cites, 'evidence' => $ev, 'dropped' => (int) ($q['dropped'] ?? 0), 'guideline_version' => (int) ($q['guideline_version'] ?? 0),
        'message' => ask_status_words($status, $q['error'] ?? null), 'error_code' => $q['error'] ?? null,
    ];
}

/** The os.knowledge-evidence/1 document. */
function ask_present_evidence(array $base, ?array $area, string $query, string $status, array $items, array $tried, ?string $code): array
{
    return [
        'schema' => 'os.knowledge-evidence/1', 'generated_at' => gmdate('c'), 'application' => 'knowledge',
        'base' => ['key' => (string) $base['key'], 'name' => (string) $base['name']],
        'focus_area' => $area === null ? null : ['slug' => (string) $area['slug'], 'name' => (string) $area['name']],
        'query' => $query, 'status' => $status,
        'evidence' => array_map(static fn (array $e): array => ['n' => (int) $e['n'], 'text' => (string) $e['text'], 'similarity' => $e['similarity'] ?? null,
            'subject' => $e['subject'] ?? null, 'verb' => $e['verb'] ?? null, 'section' => ask_section_ref($e)], $items),
        'subjects_tried' => array_values($tried), 'error_code' => $code,
    ];
}

/** A stored question (an mcp_questions row) rebuilt as the document, with its citations (mcp_citations rows). */
function ask_present_question(array $q, array $base, ?array $area, array $citations): array
{
    $evidence = json_decode((string) $q['evidence'], true) ?: [];
    $usage = json_decode((string) ($q['usage'] ?? '{}'), true) ?: [];
    $cites = array_map(static fn (array $c): array => $c + ['source_kind' => ''], $citations);
    $kinds = [];
    foreach ($evidence as $e) {
        $kinds[(int) $e['source_id']] = $e['source_kind'] ?? '';
    }
    foreach ($cites as &$c) {
        $c['source_kind'] = $kinds[(int) $c['source_id']] ?? '';
    }
    unset($c);
    return ask_present_turn($base, $area, ['question_id' => $q['question_id'], 'conversation_id' => (string) $q['conversation_id'], 'turn_no' => $q['turn_no'],
        'status' => $q['status'], 'answer_md' => $q['answer_md'], 'confidence' => $q['confidence'], 'guideline_version' => $q['guideline_version'],
        'error' => $q['error'], 'dropped' => (int) ($usage['dropped'] ?? 0)], $cites, $evidence);
}

/** A citation chip's words: "1 · Leave › Carry-over · p. 3" (the heading path's last two parts). */
function cite_chip_words(int $n, string $headingPath, ?int $page): string
{
    $parts = array_values(array_filter(array_map('trim', explode('›', $headingPath)), 'strlen'));
    $short = implode(' › ', array_slice($parts, -2)) ?: 'Section';
    return $n . ' · ' . $short . ($page !== null ? ' · p. ' . $page : '');
}

function ask_notices(): array
{
    return ['pinned' => ['success', 'Pinned — it becomes a source at the next build.'], 'unpinned' => ['warning', 'Unpinned — its note source is forgotten at the next pass.'],
            'reviewed' => ['success', 'Verified again.'], 'rated' => ['success', 'Thank you — your rating is kept.']];
}
