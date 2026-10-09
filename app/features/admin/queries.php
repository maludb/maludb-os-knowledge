<?php
declare(strict_types=1);

/**
 * The admin's reads (slice 1: the settings; slice 9 adds the admin index, the model, usage). KN_SETTINGS_FIELDS is the one table the
 * three forms, their handlers and their refusals read: column => [form, label, type, min, max, hint]. The bounds are the CHECKs of
 * db/005 shown to the person; the database refuses anything outside them (kn_settings_<column>_check → that field's sentence).
 * Sizes are entered in MB (or KB) and stored in bytes — `scale` says by how much; an agent may send the column itself in bytes.
 */
const KN_SETTINGS_FIELDS = [
    'business_name'           => ['form' => 'settings', 'label' => 'Business name', 'type' => 'text', 'max' => 200, 'hint' => 'Shown in the header and the mail; blank uses the kernel\'s.'],
    'url_fetch_allowed'       => ['form' => 'settings', 'label' => 'Fetching a web page as a source', 'type' => 'bool', 'hint' => 'One page per URL, public addresses only.'],
    'evidence_threshold'      => ['form' => 'settings', 'label' => 'Evidence threshold', 'type' => 'decimal', 'min' => 0, 'max' => 1, 'step' => '0.001', 'hint' => 'Below it a question is answered "nothing on this".'],
    'max_evidence'            => ['form' => 'settings', 'label' => 'Evidence per answer', 'type' => 'int', 'min' => 1, 'max' => 50, 'hint' => 'How many sections an answer may rely on.'],
    'max_question_chars'      => ['form' => 'settings', 'label' => 'Longest question (characters)', 'type' => 'int', 'min' => 100, 'max' => 20000],
    'conversation_turns'      => ['form' => 'settings', 'label' => 'Conversation turns remembered', 'type' => 'int', 'min' => 0, 'max' => 20],
    'pin_review_months'       => ['form' => 'settings', 'label' => 'Review a pinned answer every', 'type' => 'choice', 'choices' => [1 => '1 month', 3 => '3 months', 6 => '6 months', 12 => '12 months']],
    'section_target_chars'    => ['form' => 'settings', 'label' => 'Section target (characters)', 'type' => 'int', 'min' => 500, 'max' => 20000, 'hint' => 'Text without headings is cut near here.'],
    'section_max_chars'       => ['form' => 'settings', 'label' => 'Section maximum (characters)', 'type' => 'int', 'min' => 1000, 'max' => 50000, 'hint' => 'A longer section is split; at least the target.'],
    'limit_warn_pct'          => ['form' => 'settings', 'label' => '"Limit nearly spent" at (%)', 'type' => 'int', 'min' => 1, 'max' => 100],
    'timezone'                => ['form' => 'settings', 'label' => 'Time zone', 'type' => 'timezone'],
    'default_monthly_text_mb' => ['form' => 'limits', 'label' => 'Monthly text per base (MB)', 'type' => 'int', 'min' => 1, 'max' => 100000, 'hint' => 'A new base starts with this limit.'],
    'max_source_bytes'        => ['form' => 'limits', 'label' => 'Largest file (MB)', 'type' => 'int', 'scale' => 1048576, 'field' => 'max_source_mb', 'min' => 1, 'max' => 2048],
    'url_fetch_max_bytes'     => ['form' => 'limits', 'label' => 'Largest fetched page (KB)', 'type' => 'int', 'scale' => 1024, 'field' => 'url_fetch_max_kb', 'min' => 64, 'max' => 102400],
    'key_rate_per_minute'     => ['form' => 'limits', 'label' => 'Key calls per minute', 'type' => 'int', 'min' => 1, 'max' => 100000],
    'key_rate_per_day'        => ['form' => 'limits', 'label' => 'Key calls per day', 'type' => 'int', 'min' => 1, 'max' => 10000000],
    'key_rotation_overlap_hours' => ['form' => 'limits', 'label' => 'A rotated key keeps working for (hours)', 'type' => 'int', 'min' => 0, 'max' => 168],
    'question_retention_days' => ['form' => 'retention', 'label' => 'Keep questions for (days)', 'type' => 'int', 'min' => 1, 'max' => 3650, 'hint' => 'Older questions and their answers are purged by the worker.'],
];

/** Each form, its right, its action and its handler. */
const KN_SETTINGS_FORMS = [
    'settings'  => ['Settings',  'settings.manage',  'settings_save', '/admin/settings/save.php',      'settings.save'],
    'limits'    => ['Limits',    'limits.manage',    'limits_save',   '/admin/settings/limits.php',    'limits.save'],
    'retention' => ['Retention', 'retention.manage', 'retention_set', '/admin/settings/retention.php', 'retention.set'],
];

/** The settings as the caller may see them (mcp_settings: the model columns for admins only). */
function find_kn_settings(PDO $pdo): array
{
    $r = $pdo->query('SELECT * FROM mcp_settings')->fetch();
    return $r === false ? [] : $r;
}

/** The fields of one form, in order. */
function kn_settings_fields(string $form): array
{
    return array_filter(KN_SETTINGS_FIELDS, static fn (array $f): bool => $f['form'] === $form);
}

/** A stored value as its form shows it (bytes as MB or KB, a boolean as yes/no). */
function kn_setting_display(string $col, mixed $value): string
{
    $f = KN_SETTINGS_FIELDS[$col];
    if ($value === null) {
        return '';
    }
    if ($f['type'] === 'bool') {
        return $value === true || $value === 't' || $value === '1' || $value === 1 ? 'yes' : 'no';
    }
    if (isset($f['scale'])) {
        $v = (int) $value / $f['scale'];
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
    if ($f['type'] === 'decimal') {
        return rtrim(rtrim((string) $value, '0'), '.') ?: '0';
    }
    return (string) $value;
}

/** The bounds of a field in words ("from 1 to 50"), for the form and the refusal. */
function kn_setting_bounds(string $col): string
{
    $f = KN_SETTINGS_FIELDS[$col];
    return isset($f['min']) ? 'from ' . $f['min'] . ' to ' . number_format((float) $f['max'], 0, '.', ',') : '';
}
