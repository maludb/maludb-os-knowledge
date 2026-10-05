<?php
declare(strict_types=1);

/**
 * The sectioner (docs/build-specs/sectioning.md §5): an extracted text → its sections in document order, cut by the
 * document's own structure, deterministically. Each section is one future MaluDB document and the unit of citation.
 *
 * The sections TILE the text: the first starts at 0, each starts where the one before ended, the last ends at the
 * text's length. Inside a section, text[char_from, body_from) is its heading line (empty for a part that continues a
 * split, the preamble and a no-structure part) and 'text' is text[body_from, char_to) trimmed.
 * Work is done in bytes and every offset is turned into characters once, at the end.
 */

/**
 * Sections of an extracted document. $opts: title (else the extraction's title, else "Untitled"), target_chars (4000),
 * max_chars (6000), min_chars (200).
 * Each: position, parent_index, level, kind (heading|part|slide|sheet|rows), heading, heading_path, text, char_from,
 * body_from, char_to, page_no.
 */
function kn_section_document(array $extracted, array $opts = []): array
{
    $text = (string) ($extracted['text'] ?? '');
    if (trim($text) === '') {
        return [];
    }
    $title = kn_one_line((string) ($opts['title'] ?? $extracted['title'] ?? '')) ?: 'Untitled';
    $target = max(200, (int) ($opts['target_chars'] ?? 4000));
    $max = max($target, (int) ($opts['max_chars'] ?? 6000));
    $min = max(0, (int) ($opts['min_chars'] ?? 200));
    $kind = (string) ($extracted['kind'] ?? 'text');
    $len = strlen($text);
    $rowsKind = in_array($kind, ['csv', 'xlsx'], true);

    // the structure hints, in bytes
    $heads = [];
    $charLen = mb_strlen($text, 'UTF-8');
    $blocks = array_values(array_filter($extracted['blocks'] ?? [], static fn($b) => in_array($b['type'] ?? '', ['heading', 'slide', 'sheet'], true)
        && (int) ($b['char_from'] ?? -1) >= 0 && (int) ($b['char_to'] ?? -1) <= $charLen && (int) $b['char_from'] < (int) $b['char_to']));
    if ($blocks !== []) {
        $offs = [];
        foreach ($blocks as $b) {
            $offs[] = (int) $b['char_from'];
            $offs[] = (int) $b['char_to'];
        }
        $map = kn_chars_to_bytes($text, $offs);
        foreach ($blocks as $b) {
            $heads[] = ['kind' => $b['type'], 'level' => (int) $b['level'], 'heading' => kn_one_line((string) $b['heading']), 'from' => $map[(int) $b['char_from']], 'to' => $map[(int) $b['char_to']]];
        }
    } elseif ($kind === 'pdf') {
        $heads = kn_pdf_headings($text);
    }
    usort($heads, static fn($a, $b) => $a['from'] <=> $b['from'] ?: $a['to'] <=> $b['to']);
    $clean = [];
    $end = 0;
    foreach ($heads as $h) {
        if ($h['from'] < $end || $h['to'] <= $h['from'] || $h['heading'] === '' || $h['to'] > $len) {
            continue;
        }
        $h['level'] = max(1, min(9, $h['level']));
        $clean[] = $h;
        $end = $h['to'];
    }
    $heads = $clean;

    $nodes = [];
    if ($heads === []) {
        $ranges = kn_split_ranges($text, 0, $len, $target, $max);
        $n = count($ranges);
        foreach ($ranges as $k => [$a, $b]) {
            $h = $n === 1 ? $title : $title . ' (part ' . ($k + 1) . ' of ' . $n . ')';
            $nodes[] = ['kind' => $rowsKind ? 'rows' : 'part', 'level' => 1, 'heading' => $h, 'base' => null, 'from' => $a, 'body_from' => $a, 'to' => $b, 'parent' => null, 'root' => true];
        }
    } else {
        if (trim(substr($text, 0, $heads[0]['from'])) !== '') {
            $nodes[] = ['kind' => 'part', 'level' => 1, 'heading' => $title, 'base' => null, 'from' => 0, 'body_from' => 0, 'to' => $heads[0]['from'], 'parent' => null, 'root' => true];
        } else {
            $heads[0]['from'] = 0; // leading blank space belongs to the first heading's range
        }
        $stack = [];
        foreach ($heads as $i => $h) {
            while ($stack !== [] && $nodes[end($stack)]['level'] >= $h['level']) {
                array_pop($stack);
            }
            $nodes[] = ['kind' => $h['kind'], 'level' => $h['level'], 'heading' => $h['heading'], 'base' => $h['heading'], 'from' => $h['from'], 'body_from' => $h['to'],
                'to' => $heads[$i + 1]['from'] ?? $len, 'parent' => $stack === [] ? null : end($stack), 'root' => false];
            $stack[] = count($nodes) - 1;
        }
        $nodes = kn_section_merge_small($text, $nodes, $min);
    }
    $nodes = kn_section_split_large($text, $nodes, $target, $max, $rowsKind);

    // paths, texts, pages, and every offset in characters
    $offs = [];
    foreach ($nodes as $nd) {
        $offs[] = $nd['from'];
        $offs[] = $nd['body_from'];
        $offs[] = $nd['to'];
        $offs[] = $nd['to'] - strlen(ltrim(substr($text, $nd['from'], $nd['to'] - $nd['from'])));
    }
    $map = kn_bytes_to_chars($text, $offs);
    $pages = $extracted['pages'] ?? [];
    $out = [];
    $pathBase = [];
    foreach ($nodes as $i => $nd) {
        $parentBase = $nd['parent'] === null ? $title : $pathBase[$nd['parent']];
        $path = $nd['root'] ? $nd['heading'] : $parentBase . ' › ' . $nd['heading'];
        $pathBase[$i] = $nd['base'] === null ? $parentBase : $parentBase . ' › ' . $nd['base'];
        $firstInk = $nd['to'] - strlen(ltrim(substr($text, $nd['from'], $nd['to'] - $nd['from'])));
        $out[] = [
            'position' => $i + 1,
            'parent_index' => $nd['parent'],
            'level' => $nd['level'],
            'kind' => $nd['kind'],
            'heading' => $nd['heading'],
            'heading_path' => $path,
            'text' => trim(substr($text, $nd['body_from'], $nd['to'] - $nd['body_from'])),
            'char_from' => $map[$nd['from']],
            'body_from' => $map[$nd['body_from']],
            'char_to' => $map[$nd['to']],
            'page_no' => kn_page_at($pages, $map[$firstInk]),
        ];
    }
    return $out;
}

/** The page holding a character offset (or the first page after it); null when the source has no pages. */
function kn_page_at(array $pages, int $char): ?int
{
    if ($pages === []) {
        return null;
    }
    $lo = 0;
    $hi = count($pages) - 1;
    $ans = null;
    while ($lo <= $hi) {
        $mid = intdiv($lo + $hi, 2);
        if ($pages[$mid][1] <= $char) {
            $ans = $mid;
            $lo = $mid + 1;
        } else {
            $hi = $mid - 1;
        }
    }
    if ($ans === null) {
        return (int) $pages[0][0];
    }
    if ($char >= $pages[$ans][2] && isset($pages[$ans + 1])) {
        return (int) $pages[$ans + 1][0];
    }
    return (int) $pages[$ans][0];
}

/**
 * A section under $min characters with no children is merged into its following sibling: the merged section starts
 * where the small one started, takes its heading line as its own and carries the sibling's heading line in its text;
 * its heading is both headings joined by ' · ' (a preamble contributes none). One forward pass.
 */
function kn_section_merge_small(string $text, array $nodes, int $min): array
{
    $n = count($nodes);
    $gone = [];
    for ($i = 0; $i < $n - 1; $i++) {
        $nd = $nodes[$i];
        $next = $nodes[$i + 1];
        if ($next['parent'] !== $nd['parent']) {
            continue; // either the next is its child, or it has no following sibling
        }
        if (mb_strlen(trim(substr($text, $nd['body_from'], $nd['to'] - $nd['body_from'])), 'UTF-8') >= $min) {
            continue;
        }
        $heading = $nd['root'] ? $next['heading'] : kn_truncate_words($nd['heading'] . ' · ' . $next['heading'], 200);
        $nodes[$i + 1]['from'] = $nd['from'];
        $nodes[$i + 1]['body_from'] = $nd['body_from'];
        $nodes[$i + 1]['heading'] = $heading;
        $nodes[$i + 1]['base'] = $heading;
        $gone[$i] = true;
    }
    if ($gone === []) {
        return $nodes;
    }
    $remap = [];
    $out = [];
    foreach ($nodes as $i => $nd) {
        if (isset($gone[$i])) {
            continue;
        }
        $remap[$i] = count($out);
        $out[] = $nd;
    }
    foreach ($out as $k => $nd) {
        $out[$k]['parent'] = $nd['parent'] === null ? null : $remap[$nd['parent']];
    }
    return $out;
}

/** A section whose text is over $max characters becomes parts '… (part k of n)'; its children hang on part 1. */
function kn_section_split_large(string $text, array $nodes, int $target, int $max, bool $rowsKind): array
{
    $out = [];
    $remap = [];
    foreach ($nodes as $i => $nd) {
        $remap[$i] = count($out);
        $body = trim(substr($text, $nd['body_from'], $nd['to'] - $nd['body_from']));
        if (mb_strlen($body, 'UTF-8') <= $max) {
            $nd['parent'] = $nd['parent'] === null ? null : $remap[$nd['parent']];
            $out[] = $nd;
            continue;
        }
        $ranges = kn_split_ranges($text, $nd['body_from'], $nd['to'], $target, $max);
        $n = count($ranges);
        $partKind = ($rowsKind || $nd['kind'] === 'sheet') ? 'rows' : 'part';
        foreach ($ranges as $k => [$a, $b]) {
            $out[] = [
                'kind' => $k === 0 && $n === 1 ? $nd['kind'] : $partKind,
                'level' => $nd['level'],
                'heading' => $n === 1 ? $nd['heading'] : $nd['heading'] . ' (part ' . ($k + 1) . ' of ' . $n . ')',
                'base' => $nd['base'],
                'from' => $k === 0 ? $nd['from'] : $a,
                'body_from' => $k === 0 ? $nd['body_from'] : $a,
                'to' => $b,
                'parent' => $nd['parent'] === null ? null : $remap[$nd['parent']],
                'root' => $nd['root'],
            ];
        }
    }
    return $out;
}

/**
 * Cut text[$from, $to) into contiguous byte ranges of about $target characters, none over $max: at blank lines first,
 * then line breaks, then sentence ends, then spaces, then (a run with no space at all) every $max characters.
 */
function kn_split_ranges(string $text, int $from, int $to, int $target, int $max): array
{
    $total = mb_strlen(substr($text, $from, $to - $from), 'UTF-8');
    if (mb_strlen(trim(substr($text, $from, $to - $from)), 'UTF-8') <= $max) {
        return [[$from, $to]];
    }
    $units = kn_split_units($text, $from, $to, $max, 0, $target);
    $n = (int) ceil($total / $target);
    $ideal = $total / max(1, $n);
    $pieces = [];
    $start = $from;
    $cur = 0;
    $endAt = $from;
    $ink = false;
    foreach ($units as [$a, $b, $c]) {
        if ($ink && ($cur + $c > $max || $cur + $c / 2 > $ideal)) {
            $pieces[] = [$start, $endAt, $cur];
            $start = $a;
            $cur = 0;
            $ink = false;
        }
        $cur += $c;
        $endAt = $b;
        $ink = $ink || trim(substr($text, $a, $b - $a)) !== '';
    }
    $pieces[] = [$start, $endAt, $cur];
    // a runt at the end joins the piece before it when both fit
    $k = count($pieces);
    if ($k > 1 && $pieces[$k - 1][2] < $ideal / 4 && $pieces[$k - 2][2] + $pieces[$k - 1][2] <= $max) {
        $pieces[$k - 2][1] = $pieces[$k - 1][1];
        $pieces[$k - 2][2] += $pieces[$k - 1][2];
        array_pop($pieces);
    }
    return array_map(static fn($p) => [$p[0], $p[1]], $pieces);
}

/** [byte_from, byte_to, chars] units tiling the range, each at most $max characters, finest boundary needed. */
function kn_split_units(string $text, int $from, int $to, int $max, int $level, int $target = 4000): array
{
    $seg = substr($text, $from, $to - $from);
    $patterns = ["/\n[ \t]*\n\s*/", "/\n/", '/(?<=[.!?。！？])\s+/u', '/\s+/u'];
    if ($level >= count($patterns)) {
        $units = [];
        $pos = $from;
        foreach (mb_str_split($seg, max(1, min($max, $target)), 'UTF-8') as $chunk) {
            $units[] = [$pos, $pos + strlen($chunk), mb_strlen($chunk, 'UTF-8')];
            $pos += strlen($chunk);
        }
        return $units;
    }
    $cuts = [];
    if (preg_match_all($patterns[$level], $seg, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$sep, $off]) {
            $cuts[] = $off + strlen($sep); // the separator stays with the unit before it
        }
    }
    $units = [];
    $pos = 0;
    $cuts[] = strlen($seg);
    foreach ($cuts as $c) {
        if ($c <= $pos) {
            continue;
        }
        $chars = mb_strlen(substr($seg, $pos, $c - $pos), 'UTF-8');
        if ($chars > $max) {
            array_push($units, ...kn_split_units($text, $from + $pos, $from + $c, $max, $level + 1, $target));
        } else {
            $units[] = [$from + $pos, $from + $c, $chars];
        }
        $pos = $c;
    }
    return $units;
}

/**
 * Headings of a PDF's text (pdftotext gives no structure): a numbered line ("1.", "2.3", "2.3.1 Title"), "Chapter 4",
 * "Part II", "Section 5", "Article 3" after a blank line; a short (≤ 70) ALL-CAPS line between blank lines; a short
 * line without closing punctuation between blank lines (one level under the last numbered or capitals heading).
 * Returns heading hints in BYTES.
 */
function kn_pdf_headings(string $text): array
{
    $lines = explode("\n", $text);
    $n = count($lines);
    $heads = [];
    $off = 0;
    $lastExplicit = 0;
    $prevBlank = true;
    for ($i = 0; $i < $n; $i++) {
        $raw = $lines[$i];
        $t = trim($raw);
        $start = $off;
        $off += strlen($raw) + 1;
        $isBlank = $t === '';
        $nextBlank = $i === $n - 1 || trim($lines[$i + 1]) === '';
        $wasPrevBlank = $prevBlank;
        $prevBlank = $isBlank;
        if ($isBlank || !$wasPrevBlank) {
            continue;
        }
        $chars = mb_strlen($t, 'UTF-8');
        if ($chars > 90 || preg_match('/\S\s{3,}\S/u', $t) || preg_match('/^(page\s*)?\d+(\s*(of|\/)\s*\d+)?$/i', $t)) {
            continue;
        }
        $lead = strlen($raw) - strlen(ltrim($raw));
        $level = null;
        if (preg_match('/^((?:\d{1,3}\.)*\d{1,3})\.?\s+\p{Lu}/u', $t, $m) && !preg_match('/[.,;:]$/', $t)) {
            $level = min(9, substr_count(rtrim($m[1], '.'), '.') + 1);
            $lastExplicit = $level;
        } elseif (preg_match('/^(chapter|part)\s+(\d+|[ivxlc]+)\b/i', $t) && !preg_match('/[.,;]$/', $t)) {
            $level = 1;
            $lastExplicit = 1;
        } elseif (preg_match('/^(section|article)\s+\d+(\.\d+)*\b/i', $t) && !preg_match('/[.,;]$/', $t)) {
            $level = 2;
            $lastExplicit = 2;
        } elseif ($nextBlank && $chars <= 70 && preg_match('/\p{L}.*\p{L}/u', $t) && $t === mb_strtoupper($t, 'UTF-8') && !str_ends_with($t, '.')) {
            $level = 1;
            $lastExplicit = 1;
        } elseif ($nextBlank && $chars <= 70 && preg_match('/^\p{Lu}/u', $t) && !preg_match('/[.,;:!?]$/u', $t) && str_word_count($t) <= 10) {
            $level = min(9, $lastExplicit + 1);
        }
        if ($level !== null) {
            $heads[] = ['kind' => 'heading', 'level' => $level, 'heading' => $t, 'from' => $start + $lead, 'to' => $start + strlen($raw)];
        }
    }
    return $heads;
}

/** At most $max characters, cut at a word boundary, an ellipsis when cut. */
function kn_truncate_words(string $s, int $max): string
{
    if (mb_strlen($s, 'UTF-8') <= $max) {
        return $s;
    }
    $cut = mb_substr($s, 0, $max - 1, 'UTF-8');
    $sp = mb_strrpos($cut, ' ', 0, 'UTF-8');
    if ($sp !== false && $sp > $max / 2) {
        $cut = mb_substr($cut, 0, $sp, 'UTF-8');
    }
    return rtrim($cut, " ›·") . '…';
}

/** The MaluDB document title of a section: its heading path (the source title when it has none), at most 200 characters. */
function kn_section_title_for_engine(array $section, string $sourceTitle): string
{
    $path = kn_one_line((string) ($section['heading_path'] ?? ''));
    if ($path === '') {
        $path = kn_one_line($sourceTitle) ?: 'Untitled';
    }
    return kn_truncate_words($path, 200);
}

/** The metadata posted with a section's MaluDB document — how evidence maps back to a section and a page. */
function kn_section_metadata(int $baseId, int $sourceId, int $sectionId, array $section): array
{
    return [
        'base' => $baseId,
        'source' => $sourceId,
        'section' => $sectionId,
        'page' => isset($section['page_no']) ? (int) $section['page_no'] : null,
        'heading_path' => (string) ($section['heading_path'] ?? ''),
        'position' => (int) ($section['position'] ?? 0),
    ];
}
