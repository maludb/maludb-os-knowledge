<?php
declare(strict_types=1);

/** The vocabulary — the reads of slice 3 (mcp_vocabulary_terms: the curators'), the CSV parser (pure). */

const TERM_TYPE_PATTERN = '/^[a-z][a-z_]{0,39}$/';          // divergence 2: no digits, so every saved type can be seeded
const VOCABULARY_IMPORT_MAX_ROWS = 500;
const VOCABULARY_IMPORT_MAX_BYTES = 1048576;

/** The base's terms; $f: q (name or alias), never (only the never-met). Each with never_met. */
function find_terms(PDO $pdo, int $baseId, array $f): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_vocabulary_terms WHERE base_id = :b ORDER BY lower(canonical_name), term_id');
    $st->execute(['b' => $baseId]);
    $rows = $st->fetchAll();
    $never = [];
    $u = $pdo->prepare('SELECT term_id FROM kn_unused_terms(:b)');
    $u->execute(['b' => $baseId]);
    foreach ($u->fetchAll() as $r) {
        $never[(int) $r['term_id']] = true;
    }
    $q = mb_strtolower(trim((string) ($f['q'] ?? '')));
    $out = [];
    foreach ($rows as $r) {
        $r['aliases_list'] = pg_text_array((string) $r['aliases']);
        $r['never_met'] = isset($never[(int) $r['term_id']]);
        if (!empty($f['never']) && !$r['never_met']) {
            continue;
        }
        if ($q !== '' && !str_contains(mb_strtolower($r['canonical_name']), $q) && array_filter($r['aliases_list'], static fn ($a) => str_contains(mb_strtolower($a), $q)) === []) {
            continue;
        }
        $out[] = $r;
    }
    return $out;
}

function find_term(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_vocabulary_terms WHERE term_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['aliases_list'] = pg_text_array((string) $r['aliases']);
    return $r;
}

/** The subject types a term may take: the template's, then the base's terms' own, in the compile order. */
function base_subject_types(PDO $pdo, int $baseId): array
{
    $tpl = pg_text_array((string) one_value($pdo, 'SELECT t.subject_types FROM mcp_bases b JOIN mcp_templates t ON t.template_key = b.template WHERE b.base_id = :b', ['b' => $baseId]));
    $st = $pdo->prepare('SELECT DISTINCT subject_type FROM mcp_vocabulary_terms WHERE base_id = :b AND subject_type IS NOT NULL ORDER BY subject_type');
    $st->execute(['b' => $baseId]);
    $own = array_values(array_diff(array_column($st->fetchAll(), 'subject_type'), $tpl));
    return array_merge($tpl, $own);
}

/**
 * A vocabulary CSV → rows and refusals, writing nothing. The header names canonical_name (or term, name), aliases (split on ; or |),
 * subject_type (or type), description — case-insensitive, any order; the delimiter by the first line (, ; or tab), RFC quoting. At most 500
 * rows and 1 MB (else one refusal of the whole). ['rows' => [line => [canonical_name, aliases, subject_type, description]], 'errors' => [line => reason], 'fatal' => ?string].
 */
function parse_vocabulary_csv(string $csv): array
{
    if (strlen($csv) > VOCABULARY_IMPORT_MAX_BYTES) {
        return ['rows' => [], 'errors' => [], 'fatal' => 'The CSV is over 1 MB.'];
    }
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', str_replace(["\r\n", "\r"], "\n", $csv)) ?? '';
    $first = strtok($csv, "\n") ?: '';
    $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    arsort($counts);
    $delim = (string) array_key_first($counts);
    $h = fopen('php://memory', 'r+');
    fwrite($h, $csv);
    rewind($h);
    $header = fgetcsv($h, 0, $delim, '"', '');
    if ($header === false || $header === [null]) {
        return ['rows' => [], 'errors' => [], 'fatal' => 'The CSV is empty.'];
    }
    $map = [];
    foreach ($header as $i => $name) {
        $k = strtolower(trim((string) $name));
        $col = match ($k) { 'canonical_name', 'term', 'name' => 'canonical_name', 'aliases', 'alias' => 'aliases', 'subject_type', 'type' => 'subject_type', 'description' => 'description', default => null };
        if ($col !== null && !isset($map[$col])) {
            $map[$col] = $i;
        }
    }
    if (!isset($map['canonical_name'])) {
        return ['rows' => [], 'errors' => [], 'fatal' => 'The header must name canonical_name (or term, or name).'];
    }
    $rows = [];
    $errors = [];
    $line = 1;
    $seen = [];
    while (($cells = fgetcsv($h, 0, $delim, '"', '')) !== false) {
        $line++;
        if ($cells === [null] || implode('', array_map('strval', $cells)) === '') {
            continue;
        }
        if (count($rows) + count($errors) >= VOCABULARY_IMPORT_MAX_ROWS) {
            return ['rows' => [], 'errors' => [], 'fatal' => 'At most ' . VOCABULARY_IMPORT_MAX_ROWS . ' rows at a time.'];
        }
        $get = static fn (string $col): string => isset($map[$col]) ? trim((string) ($cells[$map[$col]] ?? '')) : '';
        $name = $get('canonical_name');
        $aliases = $get('aliases') === '' ? [] : array_values(array_filter(array_map('trim', preg_split('/[;|]/', $get('aliases')) ?: []), static fn ($a) => $a !== ''));
        $type = $get('subject_type');
        $desc = $get('description');
        $reason = term_refusal($name, $aliases, $type, $desc);
        if ($reason === null && isset($seen[mb_strtolower($name)])) {
            $reason = 'The term ' . $name . ' is already on line ' . $seen[mb_strtolower($name)] . '.';
        }
        if ($reason !== null) {
            $errors[$line] = $reason;
            continue;
        }
        $seen[mb_strtolower($name)] = $line;
        $rows[$line] = ['canonical_name' => $name, 'aliases' => $aliases, 'subject_type' => $type === '' ? null : $type, 'description' => $desc === '' ? null : $desc];
    }
    fclose($h);
    return ['rows' => $rows, 'errors' => $errors, 'fatal' => null];
}

/** Why a term would be refused, or null: the form's and the import's one rule. */
function term_refusal(string $name, array $aliases, string $type, string $desc): ?string
{
    if ($name === '' || mb_strlen($name) > 200) {
        return 'A term is 1 to 200 characters.';
    }
    if (count($aliases) > 20) {
        return 'At most 20 aliases.';
    }
    foreach ($aliases as $a) {
        if (mb_strlen($a) > 200) {
            return 'An alias is up to 200 characters.';
        }
    }
    if ($type !== '' && preg_match(TERM_TYPE_PATTERN, $type) !== 1) {
        return 'A type is lower-case letters and underscores, starting with a letter (no digits), up to 40.';
    }
    if (mb_strlen($desc) > 2000) {
        return 'A description is up to 2,000 characters.';
    }
    return null;
}

/** Aliases cleaned: trimmed, de-duplicated case-insensitively, never the canonical name. */
function clean_aliases(array $aliases, string $canonical): array
{
    $out = [];
    $seen = [mb_strtolower($canonical) => true];
    foreach ($aliases as $a) {
        $a = trim((string) $a);
        $k = mb_strtolower($a);
        if ($a !== '' && !isset($seen[$k])) {
            $seen[$k] = true;
            $out[] = $a;
        }
    }
    return $out;
}
