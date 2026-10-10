<?php
declare(strict_types=1);

/**
 * The admin's reads (slice 1: the settings; slice 9: the index, the home's admin region, purge, the model, usage, every base, the agents). KN_SETTINGS_FIELDS is the one table the
 * three forms, their handlers and their refusals read: column => [form, label, type, min, max, hint]. The bounds are the CHECKs of
 * db/005 shown to the person; the database refuses anything outside them (kn_settings_<column>_check → that field's sentence).
 * Sizes are entered in MB (or KB) and stored in bytes — `scale` says by how much; an agent may send the column itself in bytes.
 */
require_once dirname(__DIR__) . '/bases/queries.php';              // BASE_STATUSES
require_once dirname(__DIR__) . '/connections/queries.php';       // kn_manifest(), declared_shares(), declared_reads()

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

// ---------------------------------------------------------------------------------------------------------------------
// Slice 9 (docs/build-specs/home-admin.md): the admin area's reads. Every one goes through an mcp_* view (the admin's set is all) or the
// engine module; the model page is the only one that speaks to the engine, and it never shows a secret.
// ---------------------------------------------------------------------------------------------------------------------

/** The database's current month as 'YYYY-MM' (the month ai_usage counts in). */
function usage_this_month(PDO $pdo): string
{
    return (string) one_value($pdo, "SELECT to_char(date_trunc('month', now()), 'YYYY-MM')");
}

/**
 * The admin region of the home: usage this month by base (when the caller holds costs.read), failed builds (7 days), pending and failed
 * dispatches, open proposals. Each a short list with its count; nothing is a person's or a source's words.
 */
function admin_home(PDO $pdo, bool $costs = true): array
{
    $usage = null;
    if ($costs) {
        $u = usage_by_base($pdo, usage_this_month($pdo), null);
        $rows = array_values(array_filter($u['rows'], static fn (array $r): bool => $r['calls'] > 0 || $r['chars'] > 0));
        usort($rows, static fn (array $a, array $b): int => [$b['pct'], $b['chars']] <=> [$a['pct'], $a['chars']]);
        $usage = ['month' => $u['month'], 'rows' => array_slice($rows, 0, 8), 'totals' => $u['totals'], 'warn_pct' => (int) one_value($pdo, 'SELECT limit_warn_pct FROM kn_settings WHERE id = 1')];
    }
    $failed = $pdo->query("SELECT b.build_id, b.base_id, x.name AS base_name, b.error, b.finished_at FROM mcp_builds b JOIN mcp_bases x ON x.base_id = b.base_id
                            WHERE b.status = 'failed' AND b.finished_at >= now() - interval '7 days' ORDER BY b.finished_at DESC, b.build_id DESC LIMIT 10")->fetchAll();
    $counts = $pdo->query("SELECT status, count(*) AS n FROM mcp_agent_dispatches WHERE status IN ('sent', 'awaiting_approval', 'failed', 'refused') GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $latest = $pdo->query("SELECT dispatch_id, agent_name, kind, status, base_id, detail, created_at FROM mcp_agent_dispatches WHERE status IN ('failed', 'refused') ORDER BY created_at DESC, dispatch_id DESC LIMIT 5")->fetchAll();
    $props = $pdo->query("SELECT p.proposal_id, p.base_id, b.name AS base_name, p.kind, p.title FROM mcp_archivist_proposals p JOIN mcp_bases b ON b.base_id = p.base_id
                           WHERE p.status = 'proposed' ORDER BY p.created_at DESC, p.proposal_id DESC LIMIT 5")->fetchAll();
    $nProps = (int) one_value($pdo, "SELECT count(*) FROM mcp_archivist_proposals WHERE status = 'proposed'");
    return [
        'usage' => $usage,
        'failed_builds' => $failed,
        'dispatches' => ['pending' => (int) ($counts['sent'] ?? 0) + (int) ($counts['awaiting_approval'] ?? 0), 'failed' => (int) ($counts['failed'] ?? 0) + (int) ($counts['refused'] ?? 0), 'latest_failed' => $latest],
        'open_proposals' => ['count' => $nProps, 'latest' => $props],
    ];
}

/** How many questions a purge of N days would remove (the ones behind an active pin stay). */
function purge_preview(PDO $pdo, int $days): int
{
    return (int) one_value($pdo, "SELECT count(*) FROM questions q WHERE q.created_at < now() - make_interval(days => :d)
                                   AND NOT EXISTS (SELECT 1 FROM pinned_answers p WHERE p.question_id = q.id AND p.status = 'active')", ['d' => $days]);
}

/** The admin's pages, each with its one-line state: id => [title, url, right, icon, blurb]. The index adds the state. */
const ADMIN_PAGES = [
    'admin-settings'  => ['Settings',     '/admin/settings',    'settings.manage',                      'feather-sliders',  'The application\'s settings, limits and retention.'],
    'templates'       => ['Templates',    '/admin/templates',   'settings.manage',                      'feather-layers',   'The guidelines a new base starts with.'],
    'admin-model'     => ['Model',        '/admin/model',       'model.manage',                         'feather-cpu',      'The engine\'s model and embedding model.'],
    'admin-usage'     => ['Usage',        '/admin/usage',       'costs.read',                           'feather-bar-chart-2', 'Text read and engine calls, by base and month.'],
    'admin-bases'     => ['Bases',        '/admin/bases',       'settings.manage',                      'feather-book-open', 'Every base, the private ones marked.'],
    'admin-keys'      => ['Keys',         '/admin/keys',        'keys.all',                             'feather-key',      'Every API key of every base.'],
    'admin-agents'    => ['Agents',       '/admin/agents',      'agents.settings',                      'feather-users',    'The expert and the Archivist.'],
    'connections'     => ['Connections',  '/admin/connections', 'settings.manage|agents.settings',      'feather-share-2',  'What is shared with the sibling applications.'],
    'admin-proposals' => ['Proposals',    '/admin/proposals',   'settings.manage|agents.settings',      'feather-inbox',    'What the Archivist proposed, on every base.'],
    'dispatches'      => ['Dispatches',   '/admin/dispatches',  'settings.manage|agents.settings',      'feather-send',     'Every request made of the agents.'],
    'admin-purge'     => ['Purge',        '/admin/purge',       'retention.manage',                     'feather-trash-2',  'Remove questions older than a number of days.'],
];

/** The pages of the admin index: id, title, url, icon, blurb, whether this person may open it, and its state in a sentence. */
function admin_index(PDO $pdo): array
{
    $n = static fn (int $c, string $one, ?string $many = null): string => $c . ' ' . ($c === 1 ? $one : ($many ?? $one . 's'));
    $s = find_kn_settings($pdo);
    $bases = $pdo->query("SELECT count(*) AS total, count(*) FILTER (WHERE visibility = 'private') AS private FROM mcp_bases WHERE archived_at IS NULL")->fetch();
    $keys = $pdo->query('SELECT count(*) FILTER (WHERE is_live AND expires_at IS NULL) AS live, count(*) FILTER (WHERE is_live AND expires_at IS NOT NULL) AS expiring FROM mcp_keys')->fetch();
    $agents = (string) (one_value($pdo, 'SELECT display_name FROM mcp_members WHERE member_id = :m', ['m' => (int) ($s['expert_member_id'] ?? 0)]) ?: '');
    $archivist = (string) (one_value($pdo, 'SELECT display_name FROM mcp_members WHERE member_id = :m', ['m' => (int) ($s['archivist_member_id'] ?? 0)]) ?: '');
    $dis = $pdo->query("SELECT count(*) FILTER (WHERE status IN ('failed', 'refused')) AS failed, count(*) FILTER (WHERE status IN ('sent', 'awaiting_approval')) AS waiting FROM mcp_agent_dispatches")->fetch();
    $props = (int) one_value($pdo, "SELECT count(*) FROM mcp_archivist_proposals WHERE status = 'proposed'");
    $days = (int) ($s['question_retention_days'] ?? 365);
    $chars = (int) one_value($pdo, "SELECT COALESCE(sum(chars), 0) FROM mcp_ai_usage WHERE kind = 'extract' AND month = date_trunc('month', now())::date");
    $tpl = (int) one_value($pdo, 'SELECT count(*) FROM mcp_templates WHERE is_active');
    $state = [
        'admin-settings'  => 'Questions kept ' . $days . ' days · warn at ' . (int) ($s['limit_warn_pct'] ?? 80) . ' % of the limit',
        'templates'       => $n($tpl, 'template'),
        'admin-model'     => ($s['model_alias'] ?? '') !== '' ? 'Model ' . $s['model_alias'] : 'The engine\'s own model',
        'admin-usage'     => number_format($chars) . ' characters read this month',
        'admin-bases'     => $n((int) $bases['total'], 'base') . ' · ' . (int) $bases['private'] . ' private',
        'admin-keys'      => $n((int) $keys['live'], 'live key') . ((int) $keys['expiring'] > 0 ? ' · ' . $n((int) $keys['expiring'], 'key') . ' expiring' : ''),
        'admin-agents'    => ($agents !== '' ? 'Expert: ' . $agents : 'No expert named') . ' · ' . ($archivist !== '' ? 'Archivist: ' . $archivist : 'No Archivist named'),
        'connections'     => $n(count(declared_shares()), 'share') . ' offered · ' . $n(count(declared_reads()), 'read') . ' declared',
        'admin-proposals' => $n($props, 'open proposal'),
        'dispatches'      => (int) $dis['failed'] > 0 ? $n((int) $dis['failed'], 'dispatch', 'dispatches') . ' failed' : ((int) $dis['waiting'] > 0 ? $n((int) $dis['waiting'], 'dispatch', 'dispatches') . ' waiting' : 'Nothing failed'),
        'admin-purge'     => $n(purge_preview($pdo, $days), 'question') . ' older than ' . $days . ' days',
    ];
    $out = [];
    foreach (ADMIN_PAGES as $id => [$title, $url, $right, $icon, $blurb]) {
        $out[] = ['id' => $id, 'title' => $title, 'url' => $url, 'icon' => $icon, 'blurb' => $blurb, 'allowed' => nav_has_right($right), 'state' => $state[$id] ?? '', 'warn' => in_array($id, ['dispatches'], true) && (int) $dis['failed'] > 0];
    }
    return $out;
}

/**
 * Usage of a month ('YYYY-MM') by base and kind (mcp_ai_usage: the admin sees every base). $baseId narrows to one. Rows, one per base:
 * [base_id, name, archived, limit_mb, limit_chars, kinds => {extract, answer, embed => {calls, input_tokens, output_tokens, chars}}, calls, input_tokens, output_tokens, chars (extract — what the limit counts), pct].
 * Totals sum the rows by kind.
 */
function usage_by_base(PDO $pdo, string $month, ?int $baseId): array
{
    $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 ? $month : usage_this_month($pdo);
    $st = $pdo->prepare('SELECT b.base_id, b.name, b.archived_at, b.monthly_text_mb_limit, u.kind, u.calls, u.input_tokens, u.output_tokens, u.chars
                           FROM mcp_bases b LEFT JOIN mcp_ai_usage u ON u.base_id = b.base_id AND u.month = CAST(:m AS date)
                          WHERE (CAST(:b AS bigint) IS NULL OR b.base_id = :b) ORDER BY b.name, b.base_id');
    $st->execute(['m' => $month . '-01', 'b' => $baseId]);
    $zero = static fn (): array => ['calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'chars' => 0];
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $id = (int) $r['base_id'];
        $rows[$id] ??= ['base_id' => $id, 'name' => $r['name'], 'archived' => $r['archived_at'] !== null, 'limit_mb' => (int) $r['monthly_text_mb_limit'], 'limit_chars' => (int) $r['monthly_text_mb_limit'] * 1048576,
                        'kinds' => ['extract' => $zero(), 'answer' => $zero(), 'embed' => $zero()], 'calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'chars' => 0, 'pct' => 0];
        if ($r['kind'] !== null) {
            $k = ['calls' => (int) $r['calls'], 'input_tokens' => (int) $r['input_tokens'], 'output_tokens' => (int) $r['output_tokens'], 'chars' => (int) $r['chars']];
            $rows[$id]['kinds'][$r['kind']] = $k;
            $rows[$id]['calls'] += $k['calls'];
            $rows[$id]['input_tokens'] += $k['input_tokens'];
            $rows[$id]['output_tokens'] += $k['output_tokens'];
        }
    }
    $totals = ['extract' => $zero(), 'answer' => $zero(), 'embed' => $zero(), 'all' => $zero()];
    foreach ($rows as &$r) {
        $r['chars'] = $r['kinds']['extract']['chars'];
        $r['pct'] = $r['limit_chars'] > 0 ? (int) floor($r['chars'] * 100 / $r['limit_chars']) : 0;
        foreach ($r['kinds'] as $kind => $k) {
            foreach ($k as $f => $v) {
                $totals[$kind][$f] += $v;
                $totals['all'][$f] += $v;
            }
        }
    }
    unset($r);
    $rows = array_values(array_filter($rows, static fn (array $r): bool => !$r['archived'] || $r['calls'] > 0 || $r['chars'] > 0));
    return ['month' => $month, 'rows' => $rows, 'totals' => $totals];
}

/** The last twelve months as 'YYYY-MM', newest first (the month picker). */
function usage_months(PDO $pdo): array
{
    return array_map('strval', $pdo->query("SELECT to_char(date_trunc('month', now()) - make_interval(months => g), 'YYYY-MM') FROM generate_series(0, 11) g ORDER BY g")->fetchAll(PDO::FETCH_COLUMN));
}

/** Every base (mcp_bases — the admin's set is all), filtered. $f: visibility (private | internal), status, archived (also the archived ones). With the questions of the last 30 days. */
function admin_bases(PDO $pdo, array $f): array
{
    $where = [];
    $args = [];
    if (in_array($f['visibility'] ?? '', ['private', 'internal'], true)) {
        $where[] = 'b.visibility = :v';
        $args['v'] = $f['visibility'];
    }
    if (isset(BASE_STATUSES[$f['status'] ?? ''])) {
        $where[] = 'b.status = :s';
        $args['s'] = $f['status'];
    }
    if (empty($f['archived'])) {
        $where[] = 'b.archived_at IS NULL';
    }
    $st = $pdo->prepare("SELECT b.*, (SELECT count(*) FROM mcp_questions q WHERE q.base_id = b.base_id AND q.created_at >= now() - interval '30 days') AS questions_30d
                           FROM mcp_bases b" . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY b.archived_at IS NOT NULL, b.name, b.base_id LIMIT 500');
    $st->execute($args);
    return $st->fetchAll();
}

/** The agent members of the mirror: name, roles here, status, last activity, and their dispatches by status. */
function agents_here(PDO $pdo): array
{
    $rows = $pdo->query('SELECT m.member_id, m.display_name, m.roles, m.status, m.job_title, (SELECT max(a.occurred_at) FROM mcp_activity_log a WHERE a.actor_member_id = m.member_id) AS last_active
                           FROM mcp_members m WHERE m.is_agent ORDER BY m.display_name, m.member_id')->fetchAll();
    $dis = [];
    foreach ($pdo->query('SELECT agent_member_id, status, count(*) AS n FROM mcp_agent_dispatches GROUP BY agent_member_id, status')->fetchAll() as $d) {
        $dis[(int) $d['agent_member_id']][$d['status']] = (int) $d['n'];
    }
    foreach ($rows as &$r) {
        $r['roles'] = pg_text_array((string) $r['roles']);
        $r['dispatches'] = $dis[(int) $r['member_id']] ?? [];
    }
    unset($r);
    return $rows;
}

/** A cron line in words for the shapes the manifests use: "45 6 * * 1" → "Mondays at 06:45"; anything else is shown as written. */
function cron_words(string $cron): string
{
    $f = preg_split('/\s+/', trim($cron)) ?: [];
    if (count($f) !== 5 || !ctype_digit($f[0]) || !ctype_digit($f[1]) || $f[2] !== '*' || $f[3] !== '*') {
        return $cron;
    }
    $at = sprintf('%02d:%02d', (int) $f[1], (int) $f[0]);
    $names = ['Sundays', 'Mondays', 'Tuesdays', 'Wednesdays', 'Thursdays', 'Fridays', 'Saturdays', 'Sundays'];
    if ($f[4] === '*') {
        return 'Every day at ' . $at;
    }
    if ($f[4] === '1-5') {
        return 'Weekdays at ' . $at;
    }
    if (ctype_digit($f[4]) && (int) $f[4] <= 7) {
        return $names[(int) $f[4]] . ' at ' . $at;
    }
    return $cron;
}

/** The agents maludb-os.json declares: key, the job description's first line, the duty and its schedule in words, the skill and tool counts. */
function declared_agents(): array
{
    $out = [];
    foreach (kn_manifest()['agents'] ?? [] as $a) {
        $file = APP_ROOT . '/' . ltrim((string) ($a['job_description'] ?? ''), '/');
        $first = is_file($file) ? trim((string) (preg_split('/\R/', (string) file_get_contents($file), 2)[0] ?? '')) : '';
        $tools = 0;
        foreach ((array) ($a['tool_grants'] ?? []) as $list) {
            $tools += count((array) $list);
        }
        $duty = isset($a['duty']['name']) ? ['name' => (string) $a['duty']['name'], 'schedule' => cron_words((string) ($a['duty']['schedule_cron'] ?? '')), 'cron' => (string) ($a['duty']['schedule_cron'] ?? '')] : null;
        $out[] = ['key' => (string) $a['key'], 'job' => ltrim($first, "# \t"), 'duty' => $duty, 'skills' => count((array) ($a['skills'] ?? [])), 'tools' => $tools, 'hired_on_install' => (bool) ($a['hired_on_install'] ?? false)];
    }
    return $out;
}

/** The last Monday note sent from here (the log's notification.send): when, which base, to how many. Null when none. */
function last_monday_note(PDO $pdo): ?array
{
    $r = $pdo->query("SELECT occurred_at, base_id, after FROM mcp_activity_log WHERE action = 'notification.send' ORDER BY activity_id DESC LIMIT 1")->fetch();
    if ($r === false) {
        return null;
    }
    $a = json_decode((string) $r['after'], true) ?: [];
    return ['at' => $r['occurred_at'], 'base_id' => $r['base_id'] === null ? null : (int) $r['base_id'], 'recipients' => (int) ($a['recipients'] ?? 0)];
}

/** What a namespace's config shows the admin: names and numbers, the secret's NAME, the prompt's size — never a credential. */
function model_safe_config(array $c): array
{
    $tpl = is_string($c['prompt_template'] ?? null) ? (string) $c['prompt_template'] : null;
    return [
        'provider_name' => $c['provider_name'] ?? null, 'provider_kind' => $c['provider_kind'] ?? null, 'model_identifier' => $c['model_identifier'] ?? null, 'base_url' => $c['base_url'] ?? null,
        'embedding_model' => $c['embedding_model'] ?? null, 'secret_name' => $c['secret_ref'] ?? ($c['secret_name'] ?? null),
        'prompt_chars' => $tpl === null ? null : mb_strlen($tpl), 'prompt_sha256' => $tpl === null ? null : substr(hash('sha256', $tpl), 0, 16),
    ];
}

/**
 * The model page's facts: the business's connection (kb:0), the engine's health, the admin's last recorded choice and — for a chosen base — what its
 * namespace holds. Each part is ok or says why not in the engine's words; nothing here throws.
 */
function model_state(PDO $pdo, ?int $baseId): array
{
    require_once APP_ROOT . '/app/knowledge/compile.php';
    $conn = kn_compile_connection();
    $health = kn_engine_available();
    $s = find_kn_settings($pdo);
    $ns = null;
    if ($baseId !== null) {
        $r = kn_engine_get_namespace_config('kb:' . $baseId);
        $cfg = $r['ok'] ? ($r['data']['config'] ?? null) : null;
        $ns = ['base_id' => $baseId, 'ok' => $r['ok'], 'code' => $r['code'], 'error' => $r['ok'] ? null : kn_engine_error_message($r['code']), 'config' => is_array($cfg) ? model_safe_config($cfg) : null];
    }
    $reference = null;
    if ($conn['ok']) {
        $reference = model_safe_config($conn['connection'] + ['secret_ref' => $conn['connection']['secret_name']]);
    }
    return [
        'connection' => ['ok' => $conn['ok'], 'code' => $conn['code'], 'error' => $conn['ok'] ? null : ($conn['code'] === 'model_not_configured' ? MODEL_NOT_CONFIGURED_WORDS : kn_engine_error_message($conn['code'])), 'config' => $reference],
        'health' => ['ok' => $health['ok'], 'code' => $health['code'], 'message' => $health['ok'] ? 'The engine answers.' : kn_engine_error_message($health['code'])],
        'recorded' => ['model_alias' => $s['model_alias'] ?? null, 'embedding_model' => $s['embedding_model'] ?? null],
        'namespace' => $ns,
    ];
}

const MODEL_NOT_CONFIGURED_WORDS = 'No model is configured for the engine yet (the reference namespace kb:0 holds none). The owner configures it once — deploy/ROOT_STEPS.sh.';

/**
 * The base's compiled extraction prompt with a chosen section in place of {{chunk}} — what the model WOULD be sent. MaluDB cannot yet run a dry
 * extraction (M5), so this is the prompt, not a result. Null when the section is not one the caller may read.
 */
function model_test(PDO $pdo, int $sectionId): ?array
{
    require_once APP_ROOT . '/app/knowledge/compile.php';
    $x = $pdo->prepare("SELECT x.section_id, x.base_id, x.heading_path, x.text, x.source_title FROM mcp_sections x WHERE x.section_id = :s AND x.status <> 'forgotten'");
    $x->execute(['s' => $sectionId]);
    $sec = $x->fetch();
    if ($sec === false) {
        return null;
    }
    $p = kn_compile_prompt($pdo, (int) $sec['base_id']);
    return ['base_id' => (int) $sec['base_id'], 'section_id' => $sectionId, 'heading_path' => $sec['heading_path'], 'text' => str_replace('{{chunk}}', (string) $sec['text'], $p['prompt_template']),
            'sha256' => $p['sha256'], 'lines' => $p['lines']];
}
