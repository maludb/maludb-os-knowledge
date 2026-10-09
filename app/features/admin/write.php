<?php
declare(strict_types=1);

/**
 * The admin's writes (slice 1: the three settings forms). Each form writes only its own columns (KN_SETTINGS_FIELDS); the CHECKs of
 * db/005 are the referee. Also kn_settings_request(): the one reader of a settings form's request, used by the three handlers.
 */
require_once __DIR__ . '/queries.php';

/** Write a form's columns ($fields: column => stored value). Returns the diff of that form's columns (none of them is secret). */
function save_kn_settings(PDO $pdo, string $form, array $fields): array
{
    $cols = array_keys(kn_settings_fields($form));
    $before = $pdo->query('SELECT ' . implode(', ', $cols) . ' FROM kn_settings WHERE id = 1')->fetch();
    $set = [];
    $args = [];
    foreach ($fields as $col => $value) {
        if (!in_array($col, $cols, true)) {
            continue;                                   // a column of another form is never written here
        }
        $set[] = "$col = :$col";
        $args[$col] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
    }
    if ($set !== []) {
        $pdo->prepare('UPDATE kn_settings SET ' . implode(', ', $set) . ' WHERE id = 1')->execute($args);
    }
    $after = $pdo->query('SELECT ' . implode(', ', $cols) . ' FROM kn_settings WHERE id = 1')->fetch();
    $norm = static fn (array $r): array => array_map(static fn ($v) => is_bool($v) ? $v : (is_numeric($v) ? $v + 0 : $v), $r);
    return kn_diff($norm($before), $norm($after));
}

/**
 * Read a settings form from the request: only that form's fields, a field left out keeps its value. Types are checked here (a
 * number is a number, a time zone is a real one); the bounds are the database's. Returns [fields (column => stored value), errors].
 */
function kn_settings_request(string $form): array
{
    $fields = [];
    $errors = [];
    foreach (kn_settings_fields($form) as $col => $f) {
        $name = $f['field'] ?? $col;
        $scale = $f['scale'] ?? 1;
        if (!req_has($name) && isset($f['field']) && req_has($col)) {
            $name = $col;                                // an agent sends the column itself, in bytes
            $scale = 1;
        }
        if (!req_has($name)) {
            continue;
        }
        $v = (string) req_val($name);
        switch ($f['type']) {
            case 'bool':
                $fields[$col] = kn_yes($name);
                break;
            case 'text':
                $fields[$col] = $v === '' ? null : mb_substr($v, 0, (int) $f['max']);
                break;
            case 'timezone':
                if (!in_array($v, timezone_identifiers_list(), true)) {
                    $errors[$name] = 'Choose a time zone from the list (such as America/Chicago or UTC).';
                } else {
                    $fields[$col] = $v;
                }
                break;
            case 'decimal':
                if (!is_numeric($v)) {
                    $errors[$name] = $f['label'] . ' is a number ' . kn_setting_bounds($col) . '.';
                } else {
                    $fields[$col] = $v;
                }
                break;
            case 'choice':
                if (!array_key_exists((int) $v, $f['choices']) || filter_var($v, FILTER_VALIDATE_INT) === false) {
                    $errors[$name] = $f['label'] . ' is ' . implode(', ', $f['choices']) . '.';
                } else {
                    $fields[$col] = (int) $v;
                }
                break;
            default:                                     // int
                if ($scale > 1 ? !is_numeric($v) : filter_var($v, FILTER_VALIDATE_INT) === false) {
                    $errors[$name] = $f['label'] . ' is a whole number ' . kn_setting_bounds($col) . '.';
                } else {
                    $fields[$col] = (int) round((float) $v * $scale);
                }
        }
    }
    return [$fields, $errors];
}

/** The database's refusals of the settings, named on the field: kn_settings_<column>_check → that field; the pair check → the maximum. */
function kn_settings_refusals(string $form): array
{
    $map = ['kn_settings_check' => ['section_max_chars', 'Section maximum must be at least the target.']];
    foreach (kn_settings_fields($form) as $col => $f) {
        $map['kn_settings_' . $col . '_check'] = [$f['field'] ?? $col, $f['label'] . ' is ' . ($f['type'] === 'choice' ? implode(', ', $f['choices']) : kn_setting_bounds($col)) . '.'];
    }
    return $map;
}
