<?php /** One base's usage (a row at 1280, a card at 375). Data: r (usage_by_base row), warn, card (bool) */
$bid = (int) $r['base_id'];
$prefix = !empty($card) ? 'usage-card-' : 'usage-row-';
$bar = '<div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1" style="height: 8px; min-width: 70px" role="progressbar" aria-label="Against the monthly limit" aria-valuenow="' . min(100, (int) $r['pct']) . '" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-'
     . ($r['pct'] >= 100 ? 'danger' : ($r['pct'] >= $warn ? 'warning' : 'success')) . '" style="width: ' . min(100, (int) $r['pct']) . '%"></div></div>' . chip(usage_chip((int) $r['pct'], $warn), $prefix . $bid . '-pct') . '</div>';
$k = $r['kinds'];
if (!empty($card)): ?>
    <div class="card mb-2" id="usage-card-<?= $bid ?>"><div class="card-body p-3">
        <div class="fw-semibold mb-1 text-break"><?= hx_link('/bases/' . $bid, e($r['name']), 'text-dark') ?><?= $r['archived'] ? ' <span class="badge bg-dark text-white">archived</span>' : '' ?></div>
        <div class="fs-12 mb-1"><?= number_format((int) $r['chars']) ?> of <?= number_format((int) $r['limit_chars']) ?> characters (<?= (int) $r['limit_mb'] ?> MB)</div>
        <div class="mb-2"><?= $bar ?></div>
        <div class="fs-12 text-muted"><?= number_format($k['extract']['calls']) ?> read · <?= number_format($k['answer']['calls']) ?> answer · <?= number_format($k['embed']['calls']) ?> embed calls · <?= number_format($r['input_tokens']) ?> in / <?= number_format($r['output_tokens']) ?> out tokens</div>
    </div></div>
<?php else: ?>
    <tr id="usage-row-<?= $bid ?>">
        <td class="text-break"><?= hx_link('/bases/' . $bid, e($r['name']), 'text-dark') ?><?= $r['archived'] ? ' <span class="badge bg-dark text-white">archived</span>' : '' ?></td>
        <td class="text-end text-nowrap"><?= number_format((int) $r['chars']) ?></td>
        <td style="min-width: 12rem"><?= $bar ?><div class="fs-11 text-muted"><?= (int) $r['limit_mb'] ?> MB a month</div></td>
        <td class="text-end"><?= number_format($k['extract']['calls']) ?></td><td class="text-end"><?= number_format($k['answer']['calls']) ?></td><td class="text-end"><?= number_format($k['embed']['calls']) ?></td>
        <td class="text-end"><?= number_format($r['input_tokens']) ?></td><td class="text-end"><?= number_format($r['output_tokens']) ?></td>
    </tr>
<?php endif; ?>
