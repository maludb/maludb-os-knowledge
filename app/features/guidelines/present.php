<?php
declare(strict_types=1);

/**
 * Guidelines — what a screen shows (slice 3, docs/build-specs/guidelines-areas.md). GUIDELINE_KINDS: each kind's one line, in the order
 * of kn_guideline_kinds(); GUIDELINE_EXAMPLES: three synthetic examples per kind, shown beside the form.
 */
const GUIDELINE_KINDS = [
    'extraction' => ['Extraction', 'What the graph should hold. Changes the graph — the base needs a rebuild.'],
    'vocabulary' => ['Vocabulary', 'What the words mean. Changes the graph.'],
    'exclusion'  => ['Exclusion', 'What never to extract — or say. Changes the graph and the answers.'],
    'answering'  => ['Answering', 'How to answer. Applies to the next question — no rebuild.'],
    'audience'   => ['Audience', 'Who is asking. Applies to the next question — no rebuild.'],
];
const GUIDELINE_SHAPING = ['extraction', 'vocabulary', 'exclusion'];
const GUIDELINE_EXAMPLES = [
    'extraction' => ['Extract every product, model number, part, procedure step and service interval. A model number is a product; an interval is a statement about the part it applies to.',
                     'Extract every policy, who it applies to, who owns it, what it requires and its effective date.',
                     'Extract the parties, every obligation with its owner and deadline, the payment terms and the renewal and termination conditions.'],
    'vocabulary' => ['Treat PTO, paid time off and annual leave as one term.',
                     'Model codes are written with a hyphen (ZP-9); use the full product name as the subject.',
                     'A department name is a subject of type organisation.'],
    'exclusion'  => ['Ignore page headers and footers, page numbers and the table of contents.',
                     'Never extract personal data about employees: home addresses, phone numbers, salaries.',
                     'Nothing about pricing or discounts.'],
    'answering'  => ['Two paragraphs at most, in plain English; say when the sources disagree.',
                     'Start with yes or no when the question allows it, then the reason with its quote.',
                     'Name the document and the section you quote.'],
    'audience'   => ['Field technicians reading on a phone.', 'New employees in their first month.', 'Auditors who need the exact wording.'],
];

/** The origin chip: template · edited · person · agent. */
function guideline_origin_chip(array $g): array
{
    return match ($g['origin']) {
        'template' => (int) $g['version_no'] > 1 ? ['edited', 'info'] : ['template', 'light'],
        'agent' => ['agent', 'info'],
        default => ['person', 'secondary'],
    };
}

/** The first two lines of a body, for a card. */
function guideline_excerpt(string $body): string
{
    $lines = array_slice(array_values(array_filter(array_map('trim', explode("\n", $body)), static fn ($l) => $l !== '')), 0, 2);
    return mb_substr(implode(' ', $lines), 0, 280);
}

function present_guideline(array $g): array
{
    return [
        'guideline_id' => (int) $g['guideline_id'], 'base_id' => (int) $g['base_id'], 'kind' => $g['kind'], 'shapes_graph' => (bool) $g['shapes_graph'],
        'title' => $g['title'], 'body' => $g['body'], 'position' => (int) $g['position'], 'is_active' => (bool) $g['is_active'], 'origin' => $g['origin'],
        'version_no' => (int) $g['version_no'], 'updated_at' => json_ts($g['updated_at']), 'url' => '/guidelines/' . (int) $g['guideline_id'],
    ];
}

/** The did of a save: what it changed, in words. $r: save_guideline()'s result; $sent: kn_compile_send()'s. */
function guideline_did(array $r, ?array $sent): string
{
    if ($r['base_version_after'] === $r['base_version_before']) {
        return 'Saved — applies to the next question';
    }
    return 'Saved — version ' . $r['base_version_after'] . ': ' . $r['stale_total'] . ' section' . ($r['stale_total'] === 1 ? '' : 's') . ' need a rebuild; '
        . (($sent['ok'] ?? false) ? 'the engine has the new guidelines' : 'the engine will get them before the next build');
}

/** The banners a save lands with — the words built from the base as it is now (never from the request). $built: built_under(). */
function guideline_notices(array $built): array
{
    $v = 'Saved — version ' . $built['version'] . ': ' . $built['stale'] . ' section' . ($built['stale'] === 1 ? '' : 's') . ' need a rebuild; ';
    return [
        'answers'     => ['success', 'Saved — applies to the next question.'],
        'graph_sent'  => ['warning', $v . 'the engine has the new guidelines.'],
        'graph_later' => ['warning', $v . 'the engine will get them before the next build.'],
        'deleted'     => ['success', 'Deleted the guideline.'],
        'term'        => ['success', 'Saved the term.'],
        'imported'    => ['success', 'Imported the vocabulary.'],
        'asked'       => ['success', 'The Knowledge Expert has been asked — its drafts appear here.'],
        'already'     => ['info', 'The expert is already on it.'],
    ];
}

/** The notice key of a save: answers, or graph with the engine told now or later. */
function guideline_notice_key(array $r, ?array $sent): string
{
    if ($r['base_version_after'] === $r['base_version_before']) {
        return 'answers';
    }
    return ($sent['ok'] ?? false) ? 'graph_sent' : 'graph_later';
}

