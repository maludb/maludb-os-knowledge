<?php
declare(strict_types=1);
/**
 * /admin/purge (screen `admin-purge`, retention.manage; GET) and action `question_purge` (POST; deletion — pauses for an agent; confirmed): the questions older than `days` (1 to 3,650; the
 * retention setting by default) with their citations and feedback — a question behind an active pin is kept. The page counts first; the POST needs `confirm` (yes). Logged `question.purge`
 * (older_than_days, purged). The daily retention step of the worker purges past the setting by itself, as the cron. Location /admin/purge.
 */
require_once dirname(__DIR__, 2) . '/app/features/admin/handler.php';
$pdo = db();
$retention = (int) ($pdo->query('SELECT question_retention_days FROM kn_settings WHERE id = 1')->fetchColumn() ?: 365);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    kn_handler_begin();
    require_right('retention.manage');
    $errors = [];
    $days = kn_int('days', $retention, 1, 3650, 'Days', $errors);
    if ($errors !== []) {
        kn_refuse_fields($errors);
    }
    if (!kn_yes('confirm')) {
        kn_refuse_fields(['confirm' => 'Confirm the purge: the questions older than ' . $days . ' days are removed for good.']);
    }
    $me = (int) current_member_id();
    $n = kn_guard($pdo, static function () use ($pdo, $days, $me): int {
        $pdo->beginTransaction();
        $n = purge_questions($pdo, $days, $me);
        log_activity($pdo, 'question.purge', 'settings', 1, ['after' => ['older_than_days' => $days, 'purged' => $n]]);
        $pdo->commit();
        return $n;
    });
    kn_done($n === 0 ? 'Nothing to purge' : 'Purged ' . $n . ' question' . ($n === 1 ? '' : 's'), 1, kn_land('/admin/purge?days=' . $days . '&purged=' . $n, 'purged'), 'settingsChanged', ['purged' => $n, 'older_than_days' => $days]);
}
require_right('retention.manage');
require_human();
$days = request_integer('days') ?? $retention;
$days = max(1, min(3650, $days));
$would = purge_preview($pdo, $days);
log_screen_view($pdo, 'admin-purge');
if (wants_json()) {
    respond_screen(['days' => $days, 'would_purge' => $would, 'retention_days' => $retention]);
}
$purged = request_integer('purged');
render_screen('Purge questions', view('admin/purge.php', ['days' => $days, 'would' => $would, 'retention' => $retention, 'purged' => $purged]), ['activeNav' => 'admin', 'screen' => 'admin-purge']);
