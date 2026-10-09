<?php
declare(strict_types=1);

/**
 * How a base's guidelines compile into the engine's input (docs/build-specs/guidelines-compile.md). One module turns a base's five kinds of
 * guideline, its vocabulary, its template, a focus area and a source's areas into exactly what MaluDB is handed: the namespace's
 * `prompt_template`, the hints and projects of each ingested section, the terms seeded before a build, and the `system` string M1 receives.
 * Deterministic: the same rows give the same bytes and the same SHA-256 — rows are sorted here, never taken in the database's order; no
 * clock, no locale, no randomness. No model is called; nothing here writes a table. The loaders read the base tables through the writer's
 * connection (the worker has no member): authorization is the caller's. The pure cores (`*_from`) take arrays; the proof drives them.
 *
 * The engine's connection is the namespace config of the reference namespace kb:0 (divergence 1): every base copies it and names the stored
 * secret — a credential is never posted from here.
 */
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/extract/text.php';

const KN_COMPILE_HEADER = "KNOWLEDGE BASE kb:%d — extraction instructions (compiled by Knowledge from the base's guidelines; template %s v%d)";
const KN_COMPILE_PREAMBLE = 'You read ONE section of a document from this knowledge base and extract a knowledge graph from it: the subjects the section is about, and the statements it makes about them. Follow every line below. Lines starting EXTRACT say what to keep, SUBJECT TYPE the types a subject may have, TERM and ALIAS the base\'s vocabulary, VOCABULARY NOTE how to read it, EXCLUDE what never to extract.';
const KN_COMPILE_OUTPUT = <<<'TXT'
OUTPUT
Return ONLY JSON of the form {"candidate_edges":[{"subject_text":"","subject_type":"","verb_text":"","predicate":[{"attr_name":"","value_text":""}],"source_span":"","confidence":0.0}]}.
- subject_text: the subject's name as the text gives it; when the text uses an ALIAS, the TERM's canonical name instead.
- subject_type: one of the SUBJECT TYPE values; other when none fits.
- verb_text: a small canonical verb ("covers", not "is_covered_by").
- predicate: the statement's details as attributes — dates, durations, amounts, limits, versions, owners, status — one attr_name and value_text each.
- source_span: the sentence the statement comes from, copied VERBATIM from the text: the same characters, no paraphrase, no ellipsis, no added words. It is the passage an answer will quote, and a quote that is not in the text is thrown away.
- confidence: from 0 to 1.
- Never an edge from text an EXCLUDE line rules out, and never an edge whose source_span is not in the text.
- When the section states nothing worth keeping, return {"candidate_edges":[]}.
TXT;
/** The caps, in characters (mb_strlen of the block's finished lines joined by newlines). */
const KN_COMPILE_CAPS = ['extract' => 6000, 'vocabulary_note' => 2000, 'term' => 8000, 'exclude' => 4000, 'template' => 24000];
const KN_COMPILE_MAX_TERM_LINES = 500;
const KN_COMPILE_MAX_TYPES = 40;
const KN_COMPILE_ANSWER_CAPS = ['answering' => 4000, 'audience' => 1500, 'exclude' => 1200, 'terms' => 600, 'total' => 8000];

// ---------------------------------------------------------------------------------------------------------------------
// Normalising a person's words
// ---------------------------------------------------------------------------------------------------------------------

/** CRLF/CR → LF; controls (but \t \n), DEL, C1 and BOM removed; tab → space; trailing spaces trimmed; 3+ newlines → 2; NFC; trimmed; {{ }} neutralised. */
function kn_compile_text(string $s): string
{
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{0080}-\x{009F}\x{FEFF}]/u', '', $s);
    $s = str_replace("\t", ' ', $s);
    $s = (string) preg_replace('/[ ]+$/m', '', $s);
    $s = (string) preg_replace("/\n{3,}/", "\n\n", $s);
    $s = trim(kn_nfc($s));
    return str_replace(['{{', '}}'], ['{ {', '} }'], $s);
}

/** A normalised body as sentences: per line, a leading bullet or number stripped, split at an end mark followed by a capital, digit, quote or parenthesis. */
function kn_compile_lines(string $body): array
{
    $out = [];
    foreach (explode("\n", kn_compile_text($body)) as $line) {
        $line = (string) preg_replace('/^\s*(?:[-*•]|\d{1,3}[.)])\s+/u', '', $line);
        foreach (preg_split('/(?<=[.!?])\s+(?=[\p{Lu}\p{N}"“(])/u', $line) ?: [] as $sentence) {
            $sentence = trim($sentence);
            if ($sentence !== '') {
                $out[] = $sentence;
            }
        }
    }
    return $out;
}

/** A text cut to at most $max characters at a word boundary, with "…" when cut. */
function kn_compile_cut(string $s, int $max): string
{
    if (mb_strlen($s) <= $max) {
        return $s;
    }
    $cut = mb_substr($s, 0, $max - 1);
    $space = mb_strrpos($cut, ' ');
    if ($space !== false && $space > (int) ($max / 2)) {
        $cut = mb_substr($cut, 0, $space);
    }
    return rtrim($cut, " ,;:-—") . '…';
}

/** One line of a person's words: normalised and every run of whitespace a single space. */
function kn_compile_one_line(string $s): string
{
    return trim((string) preg_replace('/\s+/u', ' ', kn_compile_text($s)));
}

/** The active guidelines of a kind, in (position, id) order. */
function kn_compile_kind(array $guidelines, string $kind): array
{
    $rows = array_values(array_filter($guidelines, static fn (array $g): bool => $g['kind'] === $kind && !empty($g['is_active'])));
    usort($rows, static fn (array $a, array $b): int => [(int) $a['position'], (int) ($a['id'] ?? PHP_INT_MAX)] <=> [(int) $b['position'], (int) ($b['id'] ?? PHP_INT_MAX)]);
    return $rows;
}

/** Whole lines dropped from the end of a block until it fits: [lines, dropped]. */
function kn_compile_fit(array $lines, int $cap): array
{
    $dropped = 0;
    while ($lines !== [] && mb_strlen(implode("\n", $lines)) > $cap) {
        array_pop($lines);
        $dropped++;
    }
    return [$lines, $dropped];
}

/** The terms in the prompt's order (lower(canonical_name), id), each with its written aliases; the conflicts; alias ⇒ canonical. */
function kn_compile_terms_ordered(array $terms): array
{
    usort($terms, static fn (array $a, array $b): int => [mb_strtolower((string) $a['canonical_name']), (int) ($a['id'] ?? 0)] <=> [mb_strtolower((string) $b['canonical_name']), (int) ($b['id'] ?? 0)]);
    $claimed = [];
    $conflicts = [];
    $map = [];
    $out = [];
    foreach ($terms as $t) {
        $canon = kn_compile_one_line((string) $t['canonical_name']);
        $seen = [];
        $written = [];
        foreach ((array) ($t['aliases'] ?? []) as $alias) {
            $alias = kn_compile_one_line((string) $alias);
            $k = mb_strtolower($alias);
            if ($alias === '' || $k === mb_strtolower($canon) || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            if (isset($claimed[$k])) {
                $conflicts[] = ['alias' => $alias, 'kept_for' => $claimed[$k], 'dropped_for' => $canon];
                continue;
            }
            $claimed[$k] = $canon;
            $written[] = $alias;
            $map[$alias] = $canon;
        }
        $out[] = ['id' => (int) ($t['id'] ?? 0), 'canonical_name' => $canon, 'aliases' => $written, 'subject_type' => ($t['subject_type'] ?? '') === '' ? null : (string) $t['subject_type'],
                  'description' => kn_compile_one_line((string) ($t['description'] ?? ''))];
    }
    return ['terms' => $out, 'conflicts' => $conflicts, 'aliases' => $map];
}

// ---------------------------------------------------------------------------------------------------------------------
// Loading
// ---------------------------------------------------------------------------------------------------------------------

/** Everything compile reads for a base (base tables; authorization is the caller's). */
function kn_compile_inputs(PDO $pdo, int $baseId): array
{
    $st = $pdo->prepare('SELECT b.id, b.namespace, b.language, b.template, t.version, t.subject_types FROM knowledge_bases b JOIN base_templates t ON t.key = b.template WHERE b.id = :b');
    $st->execute(['b' => $baseId]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if ($b === false) {
        throw new DomainException('Not found.');
    }
    $g = $pdo->prepare('SELECT id, kind, title, body, position, is_active FROM guidelines WHERE base_id = :b');
    $g->execute(['b' => $baseId]);
    $guidelines = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'kind' => $r['kind'], 'title' => $r['title'], 'body' => $r['body'],
        'position' => (int) $r['position'], 'is_active' => $r['is_active'] === true || $r['is_active'] === 't'], $g->fetchAll(PDO::FETCH_ASSOC));
    $v = $pdo->prepare('SELECT id, canonical_name, aliases, subject_type, description FROM vocabulary_terms WHERE base_id = :b');
    $v->execute(['b' => $baseId]);
    $terms = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'canonical_name' => $r['canonical_name'], 'aliases' => kn_compile_pg_array((string) $r['aliases']),
        'subject_type' => $r['subject_type'], 'description' => $r['description']], $v->fetchAll(PDO::FETCH_ASSOC));
    return ['base_id' => (int) $b['id'], 'namespace' => (string) $b['namespace'], 'language' => (string) $b['language'],
        'template' => ['key' => (string) $b['template'], 'version' => (int) $b['version'], 'subject_types' => kn_compile_pg_array((string) $b['subject_types'])],
        'guidelines' => $guidelines, 'terms' => $terms];
}

/** A text[] literal as a list (quoted elements unquoted). */
function kn_compile_pg_array(string $literal): array
{
    $literal = trim($literal);
    if ($literal === '' || $literal === '{}') {
        return [];
    }
    $out = [];
    foreach (str_getcsv(substr($literal, 1, -1), ',', '"', '\\') as $v) {
        if ($v !== null && $v !== '' && $v !== 'NULL') {
            $out[] = $v;
        }
    }
    return $out;
}

/** The inputs with a draft guideline in place of its row (by guideline_id) or added. */
function kn_compile_with_draft(array $in, array $draft): array
{
    if ($draft === []) {
        return $in;
    }
    $row = ['id' => isset($draft['guideline_id']) ? (int) $draft['guideline_id'] : PHP_INT_MAX, 'kind' => (string) $draft['kind'], 'title' => (string) ($draft['title'] ?? ''),
            'body' => (string) $draft['body'], 'is_active' => (bool) ($draft['is_active'] ?? true), 'position' => isset($draft['position']) ? (int) $draft['position'] : null];
    foreach ($in['guidelines'] as $i => $g) {
        if (isset($draft['guideline_id']) && (int) $g['id'] === (int) $draft['guideline_id']) {
            $row['position'] ??= (int) $g['position'];
            $in['guidelines'][$i] = $row;
            return $in;
        }
    }
    $row['position'] ??= 1000000;                               // a new draft sorts last in its kind
    $in['guidelines'][] = $row;
    return $in;
}

// ---------------------------------------------------------------------------------------------------------------------
// The extraction prompt
// ---------------------------------------------------------------------------------------------------------------------

function kn_compile_prompt_from(array $in): array
{
    $g = $in['guidelines'] ?? [];
    $truncated = [];
    $lines = ['extract' => 0, 'subject_type' => 0, 'vocabulary_note' => 0, 'term' => 0, 'alias' => 0, 'exclude' => 0];
    $blocks = [];
    $blocks['header'] = [sprintf(KN_COMPILE_HEADER, (int) $in['base_id'], $in['template']['key'], (int) $in['template']['version']), KN_COMPILE_PREAMBLE,
        'Language: ' . $in['language'] . ' — write every name as the text writes it.'];
    // EXTRACT
    $extract = [];
    foreach (kn_compile_kind($g, 'extraction') as $row) {
        foreach (kn_compile_lines((string) $row['body']) as $s) {
            $extract[] = 'EXTRACT: ' . $s;
        }
    }
    [$extract, $d] = kn_compile_fit($extract, KN_COMPILE_CAPS['extract']);
    if ($d > 0) { $truncated['extract'] = $d; }
    $blocks['extract'] = $extract;
    // SUBJECT TYPE
    $types = [];
    foreach ((array) ($in['template']['subject_types'] ?? []) as $t) {
        if (!in_array($t, $types, true)) { $types[] = (string) $t; }
    }
    $termTypes = [];
    foreach ($in['terms'] ?? [] as $t) {
        $tt = (string) ($t['subject_type'] ?? '');
        if ($tt !== '' && !in_array($tt, $types, true) && !in_array($tt, $termTypes, true)) { $termTypes[] = $tt; }
    }
    sort($termTypes, SORT_STRING);
    $types = array_merge($types, $termTypes);
    if (count($types) > KN_COMPILE_MAX_TYPES) {
        $truncated['subject_type'] = count($types) - KN_COMPILE_MAX_TYPES;
        $types = array_slice($types, 0, KN_COMPILE_MAX_TYPES);
    }
    $blocks['subject_type'] = array_map(static fn (string $t): string => 'SUBJECT TYPE: ' . $t, $types);
    // Vocabulary: notes, then terms with their aliases
    $notes = [];
    foreach (kn_compile_kind($g, 'vocabulary') as $row) {
        foreach (kn_compile_lines((string) $row['body']) as $s) {
            $notes[] = 'VOCABULARY NOTE: ' . $s;
        }
    }
    [$notes, $d] = kn_compile_fit($notes, KN_COMPILE_CAPS['vocabulary_note']);
    if ($d > 0) { $truncated['vocabulary_note'] = $d; }
    $voc = kn_compile_terms_ordered($in['terms'] ?? []);
    $groups = [];
    foreach ($voc['terms'] as $t) {
        $line = 'TERM: ' . $t['canonical_name'] . ($t['subject_type'] !== null ? ' (' . $t['subject_type'] . ')' : '')
            . ($t['description'] !== '' ? ' — ' . kn_compile_cut($t['description'], 160) : '');
        $groups[] = array_merge([$line], array_map(static fn (string $a): string => 'ALIAS: ' . $a . ' => ' . $t['canonical_name'], $t['aliases']));
    }
    $droppedTerms = 0;
    while ($groups !== [] && (count($groups) > KN_COMPILE_MAX_TERM_LINES || mb_strlen(implode("\n", array_merge(...$groups))) > KN_COMPILE_CAPS['term'])) {
        array_pop($groups);                                 // a TERM dropped takes its ALIAS lines with it
        $droppedTerms++;
    }
    $blocks['vocabulary'] = array_merge($notes, $groups === [] ? [] : array_merge(...$groups));
    // EXCLUDE
    $exclude = [];
    foreach (kn_compile_kind($g, 'exclusion') as $row) {
        foreach (kn_compile_lines((string) $row['body']) as $s) {
            $exclude[] = 'EXCLUDE: ' . $s;
        }
    }
    [$exclude, $d] = kn_compile_fit($exclude, KN_COMPILE_CAPS['exclude']);
    if ($d > 0) { $truncated['exclude'] = $d; }
    $blocks['exclude'] = $exclude;
    $blocks['output'] = explode("\n", KN_COMPILE_OUTPUT);
    // the whole template before the chunk: terms dropped from the end first
    $assemble = static fn (array $blocks): string => implode("\n\n", array_map(static fn (array $b): string => implode("\n", $b), array_filter($blocks, static fn (array $b): bool => $b !== [])));
    while ($groups !== [] && mb_strlen($assemble($blocks)) > KN_COMPILE_CAPS['template']) {
        array_pop($groups);
        $droppedTerms++;
        $blocks['vocabulary'] = array_merge($notes, $groups === [] ? [] : array_merge(...$groups));
    }
    if ($droppedTerms > 0) { $truncated['term'] = $droppedTerms; }
    $kept = array_slice($voc['terms'], 0, count($groups));
    $template = $assemble($blocks) . "\n\nText:\n{{chunk}}";
    $lines['extract'] = count($blocks['extract']);
    $lines['subject_type'] = count($blocks['subject_type']);
    $lines['vocabulary_note'] = count($notes);
    $lines['term'] = count($groups);
    $lines['alias'] = array_sum(array_map(static fn (array $t): int => count($t['aliases']), $kept));
    $lines['exclude'] = count($blocks['exclude']);
    return ['prompt_template' => $template, 'sha256' => hash('sha256', $template), 'chars' => mb_strlen($template), 'lines' => $lines,
            'truncated' => $truncated, 'conflicts' => $voc['conflicts'], 'aliases' => $voc['aliases']];
}

function kn_compile_prompt(PDO $pdo, int $baseId, array $draft = []): array
{
    return kn_compile_prompt_from(kn_compile_with_draft(kn_compile_inputs($pdo, $baseId), $draft));
}

// ---------------------------------------------------------------------------------------------------------------------
// The vocabulary as the engine gets it
// ---------------------------------------------------------------------------------------------------------------------

function kn_compile_vocabulary_from(array $terms): array
{
    $voc = kn_compile_terms_ordered($terms);
    $out = array_map(static fn (array $t): array => ['canonical_name' => $t['canonical_name'], 'aliases' => $t['aliases'],
        'subject_type' => $t['subject_type'] ?? 'other', 'description' => $t['description']], $voc['terms']);
    return ['terms' => $out, 'aliases' => $voc['aliases'], 'conflicts' => $voc['conflicts'],
            'sha256' => hash('sha256', (string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))];
}

function kn_compile_vocabulary(PDO $pdo, int $baseId): array
{
    return kn_compile_vocabulary_from(kn_compile_inputs($pdo, $baseId)['terms']);
}

// ---------------------------------------------------------------------------------------------------------------------
// The answer system string
// ---------------------------------------------------------------------------------------------------------------------

/** Paragraph bodies fitted to a cap: whole bodies dropped from the end; one longer than the cap cut at a word with "…". */
function kn_compile_fit_paragraphs(array $bodies, int $cap, array &$truncated, string $key): array
{
    $out = [];
    foreach ($bodies as $i => $body) {
        $next = $out === [] ? $body : implode("\n\n", $out) . "\n\n" . $body;
        if (mb_strlen($next) <= $cap) {
            $out[] = $body;
            continue;
        }
        if ($out === []) {
            $out[] = kn_compile_cut($body, $cap);              // one guideline longer than its block: cut at a word
            $truncated[$key] = ($truncated[$key] ?? 0) + count($bodies) - $i;
        } else {
            $truncated[$key] = ($truncated[$key] ?? 0) + count($bodies) - $i;
        }
        break;                                                  // whole guidelines dropped from the end
    }
    return $out;
}

function kn_compile_answer_system_from(array $in, ?array $area = null): array
{
    $g = $in['guidelines'] ?? [];
    $truncated = [];
    $blocks = ['KNOWLEDGE BASE kb:' . (int) $in['base_id'] . ' — how to answer (compiled by Knowledge from the base\'s guidelines)'];
    $answering = kn_compile_fit_paragraphs(array_values(array_filter(array_map(static fn (array $r): string => kn_compile_text((string) $r['body']), kn_compile_kind($g, 'answering')))),
        KN_COMPILE_ANSWER_CAPS['answering'], $truncated, 'answering');
    if ($answering !== []) {
        $blocks[] = "HOW TO ANSWER\n" . implode("\n\n", $answering);
    }
    $audience = kn_compile_fit_paragraphs(array_values(array_filter(array_map(static fn (array $r): string => kn_compile_text((string) $r['body']), kn_compile_kind($g, 'audience')))),
        KN_COMPILE_ANSWER_CAPS['audience'], $truncated, 'audience');
    if ($audience !== []) {
        $blocks[] = "WHO IS ASKING\n" . implode("\n\n", $audience);
    }
    $leave = [];
    foreach (kn_compile_kind($g, 'exclusion') as $row) {
        foreach (kn_compile_lines((string) $row['body']) as $s) {
            $leave[] = '- ' . $s;
        }
    }
    [$leave, $d] = kn_compile_fit($leave, KN_COMPILE_ANSWER_CAPS['exclude']);
    if ($d > 0) { $truncated['exclude'] = $d; }
    if ($leave !== []) {
        $blocks[] = "LEAVE OUT OF ANSWERS\n" . implode("\n", $leave);
    }
    if ($area !== null && ($area['name'] ?? '') !== '') {
        $desc = kn_compile_one_line((string) ($area['description'] ?? ''));
        $blocks[] = 'FOCUS AREA: ' . kn_compile_one_line((string) $area['name']) . ($desc !== '' ? ' — ' . kn_compile_cut($desc, 600) : '');
    }
    $voc = kn_compile_terms_ordered($in['terms'] ?? []);
    $items = array_map(static fn (array $t): string => $t['canonical_name'] . ($t['aliases'] !== [] ? ' (also: ' . implode(', ', $t['aliases']) . ')' : ''), $voc['terms']);
    $dropped = 0;
    while ($items !== [] && mb_strlen('TERMS: ' . implode('; ', $items) . '.') > KN_COMPILE_ANSWER_CAPS['terms']) {
        array_pop($items);
        $dropped++;
    }
    if ($dropped > 0) { $truncated['terms'] = $dropped; }
    if ($items !== []) {
        $blocks[] = 'TERMS: ' . implode('; ', $items) . '.';
    }
    $system = implode("\n\n", $blocks);
    if (mb_strlen($system) > KN_COMPILE_ANSWER_CAPS['total']) {
        $system = kn_compile_cut($system, KN_COMPILE_ANSWER_CAPS['total']);
        $truncated['total'] = 1;
    }
    return ['system' => $system, 'sha256' => hash('sha256', $system), 'chars' => mb_strlen($system), 'truncated' => $truncated];
}

function kn_compile_answer_system(PDO $pdo, int $baseId, ?int $focusAreaId = null, array $draft = []): array
{
    $in = kn_compile_with_draft(kn_compile_inputs($pdo, $baseId), $draft);
    $area = null;
    if ($focusAreaId !== null) {
        $st = $pdo->prepare("SELECT name, description FROM focus_areas WHERE id = :a AND base_id = :b AND status = 'active'");
        $st->execute(['a' => $focusAreaId, 'b' => $baseId]);
        $area = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return kn_compile_answer_system_from($in, $area);
}

// ---------------------------------------------------------------------------------------------------------------------
// Hints and projects of one section
// ---------------------------------------------------------------------------------------------------------------------

/** The canonical names to tag a section with: terms (or their written aliases) found as whole words, then the areas' seed terms; first occurrence order. */
function kn_compile_section_hints(array $vocabulary, array $seedTerms, string $text, int $max = 20): array
{
    $voc = isset($vocabulary['terms']) ? $vocabulary : kn_compile_vocabulary_from($vocabulary);
    $at = static function (string $needle) use ($text): ?int {
        if ($needle === '') { return null; }
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/iu', $text, $m, PREG_OFFSET_CAPTURE) !== 1) { return null; }
        return mb_strlen(substr($text, 0, (int) $m[0][1]));
    };
    $found = [];
    foreach ($voc['terms'] as $t) {
        $pos = null;
        foreach (array_merge([$t['canonical_name']], $t['aliases']) as $w) {
            $p = $at($w);
            if ($p !== null && ($pos === null || $p < $pos)) { $pos = $p; }
        }
        if ($pos !== null) { $found[] = [$pos, $t['canonical_name']]; }
    }
    foreach ($seedTerms as $seed) {
        $seed = kn_compile_one_line((string) $seed);
        $p = $at($seed);
        if ($p !== null) { $found[] = [$p, $seed]; }
    }
    usort($found, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    $out = [];
    $seen = [];
    foreach ($found as [, $name]) {
        $k = mb_strtolower($name);
        if (!isset($seen[$k])) {
            $seen[$k] = true;
            $out[] = $name;
        }
    }
    return array_slice($out, 0, $max);
}

/** The engine project names of a source's active, engine-linked areas, by area position then id. */
function kn_compile_projects(PDO $pdo, int $sourceId): array
{
    $st = $pdo->prepare("SELECT b.namespace, a.name FROM source_focus_areas sfa JOIN focus_areas a ON a.id = sfa.focus_area_id JOIN knowledge_bases b ON b.id = a.base_id
                          WHERE sfa.source_id = :s AND a.status = 'active' AND a.maludb_project_id IS NOT NULL ORDER BY a.position, a.id");
    $st->execute(['s' => $sourceId]);
    return array_map(static fn (array $r): string => kn_engine_project_name((string) $r['namespace'], (string) $r['name']), $st->fetchAll(PDO::FETCH_ASSOC));
}

// ---------------------------------------------------------------------------------------------------------------------
// The engine: the reference connection, sending, ensuring
// ---------------------------------------------------------------------------------------------------------------------

/** The business's engine connection: kb:0's namespace config (never a credential — the stored secret by name). */
function kn_compile_connection(): array
{
    $r = kn_engine_get_namespace_config('kb:0');
    if (!$r['ok']) {
        return ['ok' => false, 'code' => $r['code'] ?? 'engine_error', 'connection' => null, 'error' => $r['error'] ?? null];
    }
    $c = $r['data']['config'] ?? null;
    if (!is_array($c) || ($c['model_identifier'] ?? '') === '' || ($c['base_url'] ?? '') === '' || ($c['embedding_model'] ?? '') === '' || ($c['secret_ref'] ?? '') === '') {
        return ['ok' => false, 'code' => 'model_not_configured', 'connection' => null, 'error' => 'The engine connection (kb:0) is not configured.'];
    }
    return ['ok' => true, 'code' => null, 'error' => null, 'connection' => [
        'provider_name' => (string) (($c['provider_name'] ?? '') !== '' ? $c['provider_name'] : 'knowledge-ledger'),
        'provider_kind' => (string) (($c['provider_kind'] ?? '') !== '' ? $c['provider_kind'] : 'cloud_api'),
        'model_alias' => (string) (($c['extraction_alias'] ?? ($c['model_alias'] ?? '')) !== '' ? ($c['extraction_alias'] ?? $c['model_alias']) : 'knowledge-extract'),
        'model_identifier' => (string) $c['model_identifier'], 'base_url' => (string) $c['base_url'], 'embedding_model' => (string) $c['embedding_model'],
        'secret_name' => (string) $c['secret_ref'], 'context_length' => isset($c['context_length']) && is_int($c['context_length']) ? $c['context_length'] : null,
    ]];
}

/** SHA-256 of the prompt the engine stores for a namespace; null when none or unreachable. */
function kn_compile_engine_sha(string $namespace): ?string
{
    $r = kn_engine_get_namespace_config($namespace);
    $tpl = $r['ok'] ? ($r['data']['config']['prompt_template'] ?? null) : null;
    return is_string($tpl) && $tpl !== '' ? hash('sha256', $tpl) : null;
}

/** Compile and configure the base's namespace with the reference connection (secret by name, never a token). */
function kn_compile_send(PDO $pdo, int $baseId): array
{
    try {
        $in = kn_compile_inputs($pdo, $baseId);
    } catch (DomainException) {
        return ['ok' => false, 'code' => 'not_found', 'sha256' => null, 'error' => 'No such base.'];
    }
    $p = kn_compile_prompt_from($in);
    $conn = kn_compile_connection();
    if (!$conn['ok']) {
        return ['ok' => false, 'code' => $conn['code'], 'sha256' => $p['sha256'], 'error' => $conn['error']];
    }
    $cfg = array_filter($conn['connection'], static fn ($v): bool => $v !== null) + ['prompt_template' => $p['prompt_template']];
    $r = kn_engine_config_namespace($in['namespace'], $cfg);
    return ['ok' => $r['ok'], 'code' => $r['ok'] ? null : ($r['code'] ?? 'engine_error'), 'sha256' => $p['sha256'], 'error' => $r['ok'] ? null : ($r['error'] ?? null)];
}

/** Send only when the engine does not hold the compiled words. */
function kn_compile_ensure(PDO $pdo, int $baseId): array
{
    $p = kn_compile_prompt($pdo, $baseId);
    $ns = 'kb:' . $baseId;
    if (kn_compile_engine_sha($ns) === $p['sha256']) {
        return ['ok' => true, 'code' => null, 'sha256' => $p['sha256'], 'sent' => false];
    }
    $r = kn_compile_send($pdo, $baseId);
    return ['ok' => $r['ok'], 'code' => $r['code'], 'sha256' => $r['sha256'], 'sent' => $r['ok'], 'error' => $r['error'] ?? null];
}
