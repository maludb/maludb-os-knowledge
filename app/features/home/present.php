<?php
declare(strict_types=1);

/**
 * The home for JSON (slice 9): the same regions as the page, by whitelist. A region the caller has no right to is null; an empty one is a list.
 * No section, source or answer text, no notification body — the recent questions carry their first line cut to 120 characters, the Archivist's note its title and first lines.
 */
require_once dirname(__DIR__) . '/bases/present.php';
require_once dirname(__DIR__) . '/admin/present.php';
require_once dirname(__DIR__) . '/ask/present.php';              // question_chip()
require_once dirname(__DIR__) . '/agents/present.php';           // DISPATCH_KIND_WORDS
require_once dirname(__DIR__) . '/builds/present.php';           // build_duration(), build_status_chip()

/** The first line of a question for a card: one line, at most 120 characters. */
function home_question_line(string $q): string
{
    $line = trim((string) (preg_split('/\R/', trim($q), 2)[0] ?? ''));
    return mb_strlen($line) > 120 ? mb_substr($line, 0, 119) . '…' : $line;
}

function present_home(array $s): array
{
    return [
        'first_run' => (bool) $s['first_run'],
        'bases' => array_map('present_base', $s['bases']),
        'builds' => $s['builds'] === null ? null : array_map(static fn (array $b): array => ['build_id' => (int) $b['build_id'], 'base_id' => (int) $b['base_id'], 'base' => $b['base_name'], 'kind' => $b['kind'],
            'status' => $b['status'], 'percent' => (int) $b['percent'], 'eta_seconds' => $b['eta_seconds'], 'items_done' => (int) $b['items_done'], 'items_total' => (int) $b['items_total']], $s['builds']),
        'recent_questions' => array_map(static fn (array $q): array => ['question_id' => (int) $q['question_id'], 'base_id' => (int) $q['base_id'], 'base' => $q['base_name'], 'question' => home_question_line((string) $q['question']),
            'status' => $q['status'], 'asked_at' => json_ts($q['created_at'])], $s['recent_questions']),
        'pins_due' => array_map(static fn (array $p): array => ['pinned_answer_id' => (int) $p['pinned_answer_id'], 'base_id' => (int) $p['base_id'], 'base' => $p['base_name'], 'title' => $p['title'],
            'review_until' => $p['review_until'], 'owner' => $p['owner_name']], $s['pins_due']),
        'archivist_note' => $s['archivist_note'] === null ? null : ['notification_id' => (int) $s['archivist_note']['notification_id'], 'title' => $s['archivist_note']['title'], 'lines' => $s['archivist_note']['lines'],
            'base_id' => $s['archivist_note']['record_type'] === 'base' && $s['archivist_note']['record_id'] !== null ? (int) $s['archivist_note']['record_id'] : null, 'read' => $s['archivist_note']['read_at'] !== null, 'at' => json_ts($s['archivist_note']['created_at'])],
        'gaps' => $s['gaps'] === null ? null : array_map(static fn (array $g): array => ['base_id' => (int) $g['base_id'], 'base' => $g['name'], 'thumbs_down' => (int) $g['thumbs_down'],
            'clusters' => array_map(static fn (array $c): array => ['cluster' => $c['cluster_key'], 'question_count' => (int) $c['question_count'], 'sample' => home_question_line((string) $c['sample_question'])], $g['clusters'])], $s['gaps']),
        'admin' => $s['admin'] === null ? null : present_admin_home($s['admin']),
        'may' => $s['may'],
    ];
}
