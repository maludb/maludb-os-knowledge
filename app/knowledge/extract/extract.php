<?php
declare(strict_types=1);

/**
 * The text extractor's front door (docs/build-specs/sectioning.md). require_once this file and the whole library
 * is loaded. Every entry returns the same shape:
 *   ['ok' => bool, 'kind' => pdf|docx|pptx|xlsx|html|markdown|text|csv|json|null, 'text' => string,
 *    'pages' => [[page_no, char_from, char_to], …], 'blocks' => [['type' => heading|slide|sheet, 'level' => 1–9,
 *    'heading' => string, 'char_from' => int, 'char_to' => int], …], 'title' => ?string, 'language' => ?string,
 *    'error' => ?string, 'no_text' => bool]
 * Offsets are CHARACTER offsets into 'text'; char_to is exclusive.
 */

require_once __DIR__ . '/text.php';
require_once __DIR__ . '/detect.php';
require_once __DIR__ . '/plain.php';
require_once __DIR__ . '/html.php';
require_once __DIR__ . '/ooxml.php';
require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/url.php';
require_once __DIR__ . '/section.php';

/** The shape every extractor answers, refusals included. */
function kn_extract_result(?string $kind, array $read = [], ?string $error = null): array
{
    $text = (string) ($read['text'] ?? '');
    return [
        'ok' => $error === null,
        'kind' => $kind,
        'text' => $error === null ? $text : '',
        'pages' => $error === null ? ($read['pages'] ?? []) : [],
        'blocks' => $error === null ? ($read['blocks'] ?? []) : [],
        'title' => $read['title'] ?? null,
        'language' => $read['language'] ?? null,
        'error' => $error,
        'no_text' => $error === null && trim($text) === '',
    ];
}

/**
 * A file on disk → its text, page anchors and structure hints. The kind comes from the bytes; $filename and $mime
 * only settle text kinds the bytes leave open. Refusals (ok false): too_large, unreadable, executable, svg,
 * unsupported, legacy_office, zip_bomb, unsafe_xml, pdf_unreadable, pdf_timeout, pdftotext_missing.
 */
function kn_extract_text(string $path, string $filename, ?string $mime = null): array
{
    if (!is_file($path) || !is_readable($path)) {
        return kn_extract_result(null, [], 'unreadable');
    }
    $size = (int) filesize($path);
    if ($size > kn_max_bytes()) {
        return kn_extract_result(null, [], 'too_large');
    }
    $det = kn_detect_kind($path, $filename, $mime);
    if ($det['error'] !== null) {
        return kn_extract_result(null, [], $det['error']);
    }
    $kind = $det['kind'];
    $title = kn_title_from_filename($filename);
    try {
        switch ($kind) {
            case 'pdf':
                $read = kn_read_pdf($path);
                return kn_extract_result('pdf', $read + ['title' => $title], $read['error']);
            case 'docx':
            case 'pptx':
            case 'xlsx':
                [$z, $err] = kn_zip_open($path);
                if ($z === null) {
                    return kn_extract_result($kind, [], $err);
                }
                try {
                    $read = match ($kind) {
                        'docx' => kn_read_docx($z),
                        'pptx' => kn_read_pptx($z),
                        'xlsx' => kn_read_xlsx($z),
                    };
                } finally {
                    $z->close();
                }
                return kn_extract_result($kind, $read + ['title' => $title]);
        }
        $raw = (string) file_get_contents($path);
        $read = match ($kind) {
            'html' => kn_read_html($raw),
            'markdown' => kn_read_markdown($raw),
            'csv' => kn_read_csv($raw, $filename),
            'json' => kn_read_json($raw),
            default => kn_read_plain($raw),
        };
        if ($kind !== 'html' || ($read['title'] ?? null) === null) {
            $read['title'] = $title;
        }
        return kn_extract_result($kind, $read);
    } catch (RuntimeException $e) {
        $code = in_array($e->getMessage(), ['zip_bomb', 'unsafe_xml', 'unreadable'], true) ? $e->getMessage() : 'unreadable';
        return kn_extract_result($kind, [], $code);
    } catch (ValueError | DOMException) {
        return kn_extract_result($kind, [], 'unreadable');
    }
}

/** An already-fetched page (the worker fetched it, after kn_url_is_public()) → the HTML reader pass. */
function kn_extract_url_text(string $html, string $url): array
{
    if (strlen($html) > kn_max_bytes()) {
        return kn_extract_result('html', [], 'too_large');
    }
    $read = kn_read_html($html);
    if (($read['title'] ?? null) === null) {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        $path = trim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/');
        $read['title'] = kn_one_line($host . ($path === '' ? '' : '/' . $path)) ?: null;
    }
    return kn_extract_result('html', $read);
}

/** Pasted text, verbatim: Markdown when it carries Markdown headings, else plain text. */
function kn_extract_pasted_text(string $text, ?string $title = null): array
{
    if (strlen($text) > kn_max_bytes()) {
        return kn_extract_result('text', [], 'too_large');
    }
    $md = preg_match('/^ {0,3}#{1,6}[ \t]+\S/m', $text) || preg_match('/^\S.*\n {0,3}(=+|-{3,})[ \t]*$/m', $text);
    $read = $md ? kn_read_markdown($text) : kn_read_plain($text);
    $read['title'] = $title === null ? null : (kn_one_line($title) ?: null);
    return kn_extract_result($md ? 'markdown' : 'text', $read);
}

/** "Staff_Handbook-2026.docx" → "Staff Handbook 2026". */
function kn_title_from_filename(string $filename): ?string
{
    $base = pathinfo(basename(str_replace('\\', '/', $filename)), PATHINFO_FILENAME);
    $t = kn_one_line(str_replace(['_', '-'], ' ', $base));
    return $t === '' ? null : $t;
}
