<?php /** A dispatch of the table (a row at 1280, a card at 375). Data: d, may (retry, run_link), tz, card (bool) */
$did = (int) $d['dispatch_id'];
$prefix = !empty($card) ? 'dispatch-card-' : 'dispatch-row-';
$chip = chip(dispatch_status_chip($d), $prefix . $did . '-status');
$kind = DISPATCH_KIND_WORDS[$d['kind']] ?? $d['kind'];
$base = $d['base_id'] !== null ? hx_link('/bases/' . (int) $d['base_id'], e((string) ($d['base_name'] ?? 'Base ' . $d['base_id'])), 'kn-row-link') : '<span class="text-muted">—</span>';
$ts = static fn (?string $t): string => $t === null ? '—' : e(format_ts($t, $tz, 'M j, g:i A'));
$run = '—';
if ($d['run_id'] !== null) {
    $url = !empty($may['run_link']) ? os_run_url((int) $d['run_id']) : null;
    $run = $url !== null ? '<a href="' . e($url) . '" target="_blank" rel="noopener" id="' . $prefix . $did . '-run">#' . (int) $d['run_id'] . '</a>' : '#' . (int) $d['run_id'];
}
$retry = '';
if (!empty($may['retry']) && in_array($d['status'], ['failed', 'refused'], true) && in_array($d['kind'], DISPATCH_WORKER_KINDS, true)) {
    $retry = '<form method="post" action="/admin/dispatches/retry.php" hx-post="/admin/dispatches/retry.php" hx-target="#flash" class="m-0" id="' . $prefix . $did . '-retry-form">' . csrf_field()
        . '<input type="hidden" name="dispatch" value="' . $did . '"><button type="submit" class="btn btn-light btn-touch" id="' . $prefix . $did . '-retry"><i class="feather-rotate-cw me-1"></i>Retry</button></form>';
}
$words = trim((string) ($d['reply_excerpt'] ?? ''));
$detail = trim((string) ($d['detail'] ?? ''));
if (!empty($card)): ?>
    <div class="card mb-2" id="dispatch-card-<?= $did ?>"><div class="card-body p-3">
        <div class="d-flex justify-content-between gap-2 mb-1"><span class="fw-semibold"><i class="feather-cpu me-1"></i><?= e((string) $d['agent_name']) ?> · <?= e($kind) ?></span><span class="text-nowrap"><?= $chip ?></span></div>
        <div class="fs-12 mb-1"><?= $base ?> · for <?= e((string) ($d['acting_name'] ?? '—')) ?></div>
        <div class="fs-12 text-muted mb-1">Try <?= (int) $d['attempts'] ?> · created <?= $ts($d['created_at']) ?><?= $d['status'] === 'sent' ? ' · next ' . $ts($d['next_attempt_at']) : ' · answered ' . $ts($d['answered_at']) ?> · run <?= $run ?></div>
        <?php if ($detail !== ''): ?><div class="fs-12 text-break mb-1" id="dispatch-card-<?= $did ?>-detail"><?= e($detail) ?></div><?php endif; ?>
        <?php if ($words !== ''): ?><div class="fs-12 text-muted text-break kn-prewrap mb-1" id="dispatch-card-<?= $did ?>-reply"><?= e($words) ?></div><?php endif; ?>
        <?php if ($retry !== ''): ?><div class="mt-2"><?= $retry ?></div><?php endif; ?>
    </div></div>
<?php else: ?>
    <tr id="dispatch-row-<?= $did ?>">
        <td class="text-nowrap"><?= e((string) $d['agent_name']) ?></td>
        <td class="text-nowrap"><?= e($kind) ?></td>
        <td><?= $base ?></td>
        <td class="text-nowrap"><?= e((string) ($d['acting_name'] ?? '—')) ?></td>
        <td class="text-nowrap"><?= $chip ?></td>
        <td><?= (int) $d['attempts'] ?></td>
        <td class="text-nowrap"><?= $d['status'] === 'sent' ? $ts($d['next_attempt_at']) : '—' ?></td>
        <td class="text-nowrap"><?= $run ?></td>
        <td class="text-nowrap"><?= $ts($d['created_at']) ?></td>
        <td class="text-nowrap"><?= $ts($d['answered_at']) ?></td>
        <td class="text-break" style="max-width: 22rem"><?php if ($detail !== ''): ?><div id="dispatch-row-<?= $did ?>-detail"><?= e($detail) ?></div><?php endif; ?><?php if ($words !== ''): ?><div class="text-muted kn-prewrap" id="dispatch-row-<?= $did ?>-reply"><?= e($words) ?></div><?php endif; ?></td>
        <td><?= $retry ?></td>
    </tr>
<?php endif; ?>
