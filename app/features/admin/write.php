<?php
declare(strict_types=1);

/**
 * The admin's writes (slice 1: the three settings forms). Each form writes only its own columns (KN_SETTINGS_FIELDS); the CHECKs of
 * db/005 are the referee. Also kn_settings_request(): the one reader of a settings form's request, used by the three handlers.
 */
require_once __DIR__ . '/queries.php';

/** The two forms slice 9 adds, written by their own pages (not the settings screen's three): the columns each owns. */
const KN_SETTINGS_EXTRA = ['agents' => ['expert_member_id', 'archivist_member_id'], 'model' => ['model_alias', 'embedding_model']];

/** Write a form's columns ($fields: column => stored value). Returns the diff of that form's columns (none of them is secret). */
function save_kn_settings(PDO $pdo, string $form, array $fields): array
{
    $cols = KN_SETTINGS_EXTRA[$form] ?? array_keys(kn_settings_fields($form));
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

// ---------------------------------------------------------------------------------------------------------------------
// Slice 9 (docs/build-specs/home-admin.md): purge now, the model, the two agents.
// ---------------------------------------------------------------------------------------------------------------------

/** Remove the questions older than N days (the ones behind an active pin stay) with their citations and feedback; answers how many went. */
function purge_questions(PDO $pdo, int $days, int $by): int
{
    return (int) one_value($pdo, 'SELECT kn_purge_questions(make_interval(days => :d))', ['d' => $days]);
}

/** Name the expert and the Archivist ($fields: expert_member_id, archivist_member_id — an agent member or null; slice 5's trigger refuses a human). Answers the diff. */
function save_agents(PDO $pdo, array $fields): array
{
    return save_kn_settings($pdo, 'agents', $fields);
}

/**
 * Change the model (docs/build-specs/home-admin.md "model_config_save"): the reference namespace kb:0 first — the business's connection with the
 * model id and the embedding model replaced, the secret by NAME (never a token) and its own prompt kept. Refused → nothing else changes. Accepted →
 * the choice is recorded, every base's namespace is sent the new connection (a failure is counted, never fatal: the worker's kn_compile_ensure()
 * sends it again before that base's next build), and an embedding change marks every base's sections stale (a rebuild is a curator's to start).
 * $fields: model_alias (the provider's model id), embedding_model — a left-out one keeps the connection's. Answers
 * ['ok', 'code', 'error', 'field', 'before', 'after', 'bases_sent', 'bases_failed' => [base => code], 'rebuild_marked'].
 */
function save_model_config(PDO $pdo, array $fields, bool $rebuildConfirmed, int $by): array
{
    require_once APP_ROOT . '/app/knowledge/compile.php';
    $fail = static fn (string $code, string $error, ?string $field = null): array => ['ok' => false, 'code' => $code, 'error' => $error, 'field' => $field, 'before' => [], 'after' => [],
                                                                                    'bases_sent' => 0, 'bases_failed' => [], 'rebuild_marked' => 0];
    $conn = kn_compile_connection();
    if (!$conn['ok']) {
        return $fail($conn['code'], $conn['code'] === 'model_not_configured' ? MODEL_NOT_CONFIGURED_WORDS : kn_engine_error_message($conn['code']));
    }
    $c = $conn['connection'];
    $model = array_key_exists('model_alias', $fields) ? (string) $fields['model_alias'] : $c['model_identifier'];
    $embedding = array_key_exists('embedding_model', $fields) ? (string) $fields['embedding_model'] : $c['embedding_model'];
    if (trim($model) === '' || mb_strlen($model) > 200) {
        return $fail('invalid_argument', 'The model id is required (up to 200 characters).', 'model_alias');
    }
    if (trim($embedding) === '' || mb_strlen($embedding) > 200) {
        return $fail('invalid_argument', 'The embedding model is required (up to 200 characters).', 'embedding_model');
    }
    $embeddingChanged = $embedding !== $c['embedding_model'];
    if ($embeddingChanged && !$rebuildConfirmed) {
        return $fail('rebuild_unconfirmed', 'Changing the embedding model means every base must be rebuilt — the stored passages were embedded with the old model. Tick the box to confirm.', 'rebuild_confirmed');
    }
    $ref = kn_engine_get_namespace_config('kb:0');
    $tpl = $ref['ok'] ? ($ref['data']['config']['prompt_template'] ?? null) : null;
    $cfg = array_filter($c, static fn ($v): bool => $v !== null);
    $cfg['model_identifier'] = trim($model);
    $cfg['embedding_model'] = trim($embedding);
    if (is_string($tpl) && $tpl !== '') {
        $cfg['prompt_template'] = $tpl;                           // the reference's own prompt, kept
    }
    $sent = kn_engine_config_namespace('kb:0', $cfg);             // the secret by name (secret_name); no token is ever in $cfg
    if (!$sent['ok']) {
        return $fail((string) $sent['code'], kn_engine_error_message($sent['code']), 'model_alias');
    }
    $ids = array_map('intval', $pdo->query('SELECT id FROM knowledge_bases WHERE archived_at IS NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    $pdo->beginTransaction();
    $d = save_kn_settings($pdo, 'model', ['model_alias' => trim($model), 'embedding_model' => trim($embedding)]);
    $marked = 0;
    if ($embeddingChanged) {
        foreach (array_map('intval', $pdo->query('SELECT id FROM knowledge_bases ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)) as $bid) {
            $pdo->prepare('SELECT kn_graph_shape_changed(:b)')->execute(['b' => $bid]);
            $marked++;
        }
    }
    $pdo->commit();
    $okCount = 0;
    $failed = [];
    foreach ($ids as $bid) {
        $r = kn_compile_send($pdo, $bid);
        if ($r['ok']) {
            $okCount++;
        } else {
            $failed[$bid] = (string) ($r['code'] ?? 'engine_error');
        }
    }
    return ['ok' => true, 'code' => null, 'error' => null, 'field' => null, 'before' => $d['before'], 'after' => $d['after'], 'bases_sent' => $okCount, 'bases_failed' => $failed, 'rebuild_marked' => $marked];
}
