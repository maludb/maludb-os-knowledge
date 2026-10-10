<?php /** A key of the base's table (a row at 1280, a card at 375 — both rendered, CSS shows one). Data: k, may, tz, card (bool) */
$kid = (int) $k['key_id'];
$prefix = !empty($card) ? 'key-card-' : 'key-';
$st = key_status($k);
$statusChip = chip(key_status_chip($k));
$ends = $st === 'expiring' ? ' <span class="fs-11 text-muted">until ' . e(format_ts($k['expires_at'], $tz, 'M j, g:i A')) . '</span>' : '';
$near = key_count_near_limit($k);
$today = '<span class="' . ($near ? 'text-danger fw-semibold' : '') . '" id="' . $prefix . $kid . '-today">' . number_format((int) $k['calls_today']) . '</span>';
$last = $k['last_used_at'] === null ? 'never' : format_ts($k['last_used_at'], $tz, 'M j, g:i A');
$area = $k['area_name'] ?? null;
$from = $k['rotated_from'] !== null ? ' <a class="fs-11" href="#key-' . (int) $k['rotated_from'] . '">rotated from ' . e((string) ($k['rotated_from_label'] ?? 'an older key')) . '</a>' : '';
$actions = '';
if ($may['edit'] && $st !== 'revoked') {
    $actions .= hx_link('/keys/' . $kid . '/edit', 'Edit', 'btn btn-light btn-touch', 'id="' . $prefix . $kid . '-edit"');
}
if ($may['edit'] && $may['mint'] && $st === 'live') {                       // an archived base is rotated no key (it answers nothing)
    $actions .= '<form method="post" action="/keys/rotate.php" hx-post="/keys/rotate.php" hx-target="#flash" hx-confirm="Rotate the key ' . e($k['label']) . '? You get a new key now; the old one keeps working for ' . (int) $may['overlap_hours'] . ' hours and then stops." class="m-0" id="' . $prefix . $kid . '-rotate-form">'
        . csrf_field() . '<input type="hidden" name="api_key" value="' . $kid . '"><button type="submit" class="btn btn-light btn-touch" id="' . $prefix . $kid . '-rotate"><i class="feather-refresh-cw me-1"></i>Rotate</button></form>';
}
if ($may['edit'] && $st !== 'revoked') {
    $actions .= '<form method="post" action="/keys/revoke.php" hx-post="/keys/revoke.php" hx-target="#flash" hx-confirm="Revoke the key ' . e($k['label']) . '? Anything using it stops working at once." class="m-0" id="' . $prefix . $kid . '-revoke-form">'
        . csrf_field() . '<input type="hidden" name="api_key" value="' . $kid . '"><button type="submit" class="btn btn-light btn-touch text-danger" id="' . $prefix . $kid . '-revoke"><i class="feather-x me-1"></i>Revoke</button></form>';
}
if (!empty($card)): ?>
    <div class="card mb-2" id="key-card-<?= $kid ?>"><div class="card-body p-3">
        <div class="d-flex justify-content-between gap-2 mb-1"><span class="fw-semibold text-break"><i class="feather-key me-1"></i><?= e($k['label']) ?></span><span class="text-nowrap"><?= $statusChip ?></span></div>
        <div class="fs-12 text-muted mb-2"><?= e($area ?? 'Whole base') ?> · <?= (int) $k['rate_per_minute'] ?> a minute · <?= (int) $k['rate_per_day'] ?> a day<?= $ends ?><?= $from ?></div>
        <div class="fs-12 mb-2">Today <?= $today ?> · last used <?= e($last) ?><br><span class="text-muted">Minted by <?= e((string) ($k['minted_by_name'] ?? '—')) ?>, <?= e(format_ts($k['created_at'], $tz, 'M j, Y')) ?></span></div>
        <?php if ($actions !== ''): ?><div class="d-flex flex-wrap gap-2"><?= $actions ?></div><?php endif; ?>
    </div></div>
<?php else: ?>
    <tr id="key-<?= $kid ?>" class="<?= $st === 'revoked' || $st === 'expired' ? 'text-muted' : '' ?>">
        <td class="text-break"><i class="feather-key me-1"></i><?= e($k['label']) ?><?= $from !== '' ? '<br>' . $from : '' ?></td>
        <td class="fs-12"><?= e($area ?? 'Whole base') ?></td>
        <td class="fs-12"><?= (int) $k['rate_per_minute'] ?></td>
        <td class="fs-12"><?= (int) $k['rate_per_day'] ?></td>
        <td class="fs-12"><?= $today ?></td>
        <td class="fs-12 text-nowrap"><?= e($last) ?></td>
        <td class="text-nowrap"><?= $statusChip ?><?= $ends ?></td>
        <td class="fs-12"><?= e((string) ($k['minted_by_name'] ?? '—')) ?><br><span class="text-muted"><?= e(format_ts($k['created_at'], $tz, 'M j, Y')) ?></span></td>
        <td><?php if ($actions !== ''): ?><div class="d-flex gap-2 justify-content-end"><?= $actions ?></div><?php endif; ?></td>
    </tr>
<?php endif; ?>
