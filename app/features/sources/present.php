<?php
declare(strict_types=1);

/**
 * Sources and sections — what a screen shows and what JSON answers (slice 2, THE FIRST EXEMPLAR; docs/build-specs/sources-sections.md).
 * SOURCE_ERRORS: every refusal code the extractor, the fetcher and the worker write into sources.error, in the words a person reads.
 */

const SOURCE_ERRORS = [
    'unreadable'         => 'The file could not be read.',
    'too_large'          => 'The file is over the size limit.',
    'executable'         => 'That file is a program — it was refused.',
    'svg'                => 'SVG images can carry scripts — refused.',
    'unsupported'        => 'That kind of file is not read.',
    'legacy_office'      => 'Old Office formats (.doc, .xls, .ppt) are not read — save it as .docx, .xlsx or .pptx.',
    'zip_bomb'           => 'The file\'s inside is unsafe — refused.',
    'unsafe_xml'         => 'The file\'s inside is unsafe — refused.',
    'pdf_unreadable'     => 'The PDF is damaged.',
    'pdf_timeout'        => 'The PDF took too long to read.',
    'pdftotext_missing'  => 'The server\'s PDF reader is not installed (an administrator\'s job).',
    'file_missing'       => 'The stored file is gone.',
    'worker_interrupted' => 'Reading stopped part-way — try again.',
    'extract_error'      => 'Reading failed — try again.',
    'fetch_disabled'     => 'Fetching pages is turned off.',
    'fetch_refused'      => 'That address is not public.',
    'fetch_failed'       => 'The page could not be reached.',
    'fetch_timeout'      => 'The page took too long to answer.',
    'fetch_status'       => 'The page answered {status}.',
    'fetch_too_large'    => 'The page is over the size limit.',
    'fetch_redirects'    => 'Too many redirects.',
    'fetch_unsupported'  => 'The page is not a document we read.',
];
const SOURCE_NO_TEXT = 'No text could be read — it may be a scan. Scans are not read in this version.';

/** [words, colour] per source status (the slice's status vocabulary). */
const SOURCE_STATUSES = [
    'uploaded' => ['waiting', 'secondary'], 'extracting_text' => ['reading', 'info'], 'sectioning' => ['cutting', 'info'], 'ready' => ['ready', 'success'],
    'no_text' => ['no text', 'warning'], 'failed' => ['failed', 'danger'], 'forgotten' => ['removed', 'dark'],
];
const SECTION_STATUSES = [
    'queued' => ['not built yet', 'secondary'], 'extracted' => ['in the graph', 'success'], 'stale' => ['guidelines changed', 'warning'],
    'failed' => ['failed', 'danger'], 'forgotten' => ['removed', 'dark'],
];
const SOURCE_KINDS = ['file' => ['File', 'feather-file-text'], 'text' => ['Text', 'feather-type'], 'url' => ['Address', 'feather-globe'],
                      'note' => ['Note', 'feather-bookmark'], 'feed' => ['Feed', 'feather-rss']];
/** What the Files tab admits (the extractor reads these; the kit stores them). */
const SOURCE_FILE_ACCEPT = '.pdf,.docx,.pptx,.xlsx,.html,.htm,.md,.txt,.csv,.json';
const SOURCE_TEXT_MAX_BYTES = 2097152;           // 2 MB of pasted text
const SOURCE_FILES_PER_REQUEST = 20;

/** The error of a source in words: the code's sentence (fetch_status with its status), else the code itself. */
function source_error_words(?string $error, string $status = 'failed'): string
{
    if ($status === 'no_text') {
        return SOURCE_NO_TEXT;
    }
    if ($error === null || $error === '') {
        return '';
    }
    if (preg_match('/^fetch_status:?(\d{3})$/', $error, $m)) {
        return str_replace('{status}', $m[1], SOURCE_ERRORS['fetch_status']);
    }
    return SOURCE_ERRORS[$error] ?? $error;
}

function source_status_chip(string $status): array
{
    return SOURCE_STATUSES[$status] ?? [$status, 'secondary'];
}

function section_status_chip(string $status): array
{
    return SECTION_STATUSES[$status] ?? [$status, 'secondary'];
}

/** A URL as the log may keep it: the origin up to its query string. */
function source_origin_for_log(?string $origin): ?string
{
    if ($origin === null) {
        return null;
    }
    $cut = strcspn($origin, '?#');
    return mb_substr(substr($origin, 0, $cut), 0, 500);
}

/** A source for JSON: the card's fields (mcp_sources), never its text. */
function present_source(array $s): array
{
    return [
        'source_id' => (int) $s['source_id'], 'base_id' => (int) $s['base_id'], 'kind' => $s['kind'], 'title' => $s['title'],
        'origin' => $s['kind'] === 'file' || $s['kind'] === 'url' ? $s['origin'] : null, 'attachment_id' => $s['attachment_id'] === null ? null : (int) $s['attachment_id'],
        'mime_type' => $s['mime_type'], 'status' => $s['status'], 'error' => $s['error'], 'error_words' => source_error_words($s['error'], (string) $s['status']),
        'char_count' => (int) $s['char_count'], 'page_count' => (int) $s['page_count'], 'sections_count' => (int) $s['sections_count'], 'language' => $s['language'],
        'is_stale' => (bool) $s['is_stale'], 'built_under' => $s['built_under'] === null ? null : (int) $s['built_under'],
        'focus_area_ids' => pg_int_array($s['focus_area_ids'] ?? null), 'added_by' => $s['added_by'] === null ? null : (int) $s['added_by'],
        'added_by_name' => $s['added_by_name'], 'added_at' => json_ts($s['added_at']), 'superseded_by' => $s['superseded_by'] === null ? null : (int) $s['superseded_by'],
        'url_checked_at' => json_ts($s['url_checked_at']), 'forgotten_at' => json_ts($s['forgotten_at']), 'url' => '/sources/' . (int) $s['source_id'],
    ];
}

/** A section for JSON: its place and its words (a Reader's — that is how an answer is trusted); a forgotten one has none. */
function present_section(array $x, bool $curate): array
{
    $out = [
        'section_id' => (int) $x['section_id'], 'base_id' => (int) $x['base_id'], 'source_id' => (int) $x['source_id'], 'source_title' => $x['source_title'],
        'heading_path' => $x['heading_path'], 'heading' => $x['heading'], 'level' => (int) $x['level'], 'kind' => $x['kind'], 'position' => (int) $x['position'],
        'page_no' => $x['page_no'] === null ? null : (int) $x['page_no'], 'text' => $x['text'], 'char_count' => (int) $x['char_count'], 'status' => $x['status'],
        'url' => '/sections/' . (int) $x['section_id'],
    ];
    if ($curate) {
        $out += ['extracted_version' => $x['extracted_version'] === null ? null : (int) $x['extracted_version'], 'error' => $x['error']];
    }
    return $out;
}

/** The notice banners the source screens land with. */
function source_notices(?array $s = null): array
{
    $t = $s['title'] ?? 'the source';
    return [
        'added'    => ['success', 'Added. It is read and cut into sections within the minute.'],
        'replaced' => ['success', 'Replaced. The old version is forgotten; this one is read and cut within the minute.'],
        'removed'  => ['warning', 'Removed ' . $t . '. The engine forgets what it taught within the minute.'],
        'retry'    => ['success', $t . ' goes back in the queue.'],
        'saved'    => ['success', 'Saved ' . $t . '.'],
    ];
}
