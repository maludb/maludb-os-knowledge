<?php
declare(strict_types=1);

/** Focus areas — what a screen shows (slice 3). */

const AREA_STATUSES = ['active' => ['active', 'success'], 'proposed' => ['proposed', 'primary'], 'archived' => ['archived', 'dark']];

function area_status_chip(string $status): array
{
    return AREA_STATUSES[$status] ?? [$status, 'secondary'];
}

function area_link_chip(array $a): array
{
    return $a['maludb_project_id'] !== null ? ['linked', 'success'] : ['not linked in the engine', 'warning'];
}

function present_area(array $a, bool $curate): array
{
    $out = [
        'focus_area_id' => (int) $a['focus_area_id'], 'base_id' => (int) $a['base_id'], 'name' => $a['name'], 'slug' => $a['slug'], 'description' => $a['description'],
        'seed_terms' => $a['seed_terms_list'], 'example_questions' => $a['example_questions_list'], 'status' => $a['status'], 'position' => (int) $a['position'],
        'source_count' => (int) $a['source_count'], 'url' => '/areas/' . (int) $a['focus_area_id'],
    ];
    if ($curate) {
        $out += ['linked' => $a['maludb_project_id'] !== null, 'project_id' => $a['maludb_project_id'] === null ? null : (int) $a['maludb_project_id'],
                 'proposed_by' => $a['proposed_by_name'], 'proposal_note' => $a['proposal_note']];
    }
    return $out;
}

/** The banners the area screens land with. */
function area_notices(): array
{
    return [
        'linked'   => ['success', 'Saved — linked in the engine.'],
        'later'    => ['warning', 'Saved — the engine will link it before the next build.'],
        'saved'    => ['success', 'Saved.'],
        'proposed' => ['success', 'Proposed. The curators are told.'],
        'accepted' => ['success', 'Accepted.'],
        'dismissed' => ['success', 'Dismissed.'],
        'deleted'  => ['success', 'Deleted the area.'],
        'assigned' => ['success', 'Saved the areas.'],
        'asked'    => ['success', 'The Knowledge Expert has been asked — its proposals appear here.'],
        'already'  => ['info', 'The expert is already on it.'],
    ];
}

/** Lines of a textarea: trimmed, de-duplicated, empty ones dropped. */
function area_lines(?string $text): array
{
    $out = [];
    foreach (preg_split('/\R/u', (string) $text) ?: [] as $l) {
        $l = trim($l);
        if ($l !== '' && !in_array($l, $out, true)) {
            $out[] = $l;
        }
    }
    return $out;
}
