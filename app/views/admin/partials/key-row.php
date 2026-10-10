<?php /** A key in the admin's list (a row at 1280, a card at 375). Data: k (all_keys row), tz, card (bool). Revoke posts slice 7's key_revoke and lands back on /admin/keys. */
$kid = (int) $k['key_id'];
$prefix = !empty($card) ? 'admin-key-card-' : 'admin-key-row-';
$st = key_status($k);
$chip = chip(key_status_chip($k), $prefix . $kid . '-status');
$last = $k['last_used_at'] === null ? 'never' : format_ts($k['last_used_at'], $tz, 'M j, g:i A');
$base = hx_link('/bases/' . (int) $k['base_id'] . '/keys', e((string) $k['base_name']), 'text-dark', 'id="' . $prefix . $kid . '-base"');
$revoke = $st === 'revoked' ? '' : '<form method="post" action="/keys/revoke.php" hx-post="/keys/revoke.php" hx-target="#flash" hx-confirm="Revoke the key ' . e($k['label']) . '? Anything using it stops working at once." class="m-0" id="' . $prefix . $kid . '-revoke-form">'
    . csrf_field() . '<input type="hidden" name="api_key" value="' . $kid . '"><input type="hidden" name="return_to" value="/admin/keys"><button type="submit" class="btn btn-light btn-touch text-danger" id="' . $prefix . $kid . '-revoke"><i class="feather-x me-1"></i>Revoke</button></form>';
$today = '<span class="' . (key_count_near_limit($k) ? 'text-danger fw-semibold' : '') . '">' . number_format((int) $k['calls_today']) . '</span>';
if (!empty($card)): ?>
    <div class="card mb-2" id="admin-key-card-<?= $kid ?>"><div class="card-body p-3">
        <div class="d-flex justify-content-between gap-2 mb-1"><span class="fw-semibold text-break"><i class="feather-key me-1"></i><?= e($k['label']) ?></span><span class="text-nowrap"><?= $chip ?></span></div>
        <div class="fs-12 mb-1"><?= $base ?> · <?= e($k['area_name'] ?? 'Whole base') ?></div>
        <div class="fs-12 text-muted mb-2"><?= (int) $k['rate_per_minute'] ?> a minute · <?= (int) $k['rate_per_day'] ?> a day · today <?= $today ?> · last used <?= e($last) ?><br>Minted by <?= e((string) ($k['minted_by_name'] ?? '—')) ?>, <?= e(format_ts($k['created_at'], $tz, 'M j, Y')) ?></div>
        <?php if ($revoke !== ''): ?><div><?= $revoke ?></div><?php endif; ?>
    </div></div>
<?php else: ?>
    <tr id="admin-key-row-<?= $kid ?>" class="<?= $st === 'revoked' || $st === 'expired' ? 'text-muted' : '' ?>">
        <td class="text-break"><?= $base ?></td><td class="text-break"><i class="feather-key me-1"></i><?= e($k['label']) ?></td><td><?= e($k['area_name'] ?? 'Whole base') ?></td>
        <td><?= (int) $k['rate_per_minute'] ?></td><td><?= (int) $k['rate_per_day'] ?></td><td><?= $today ?></td><td class="text-nowrap"><?= e($last) ?></td><td class="text-nowrap"><?= $chip ?></td>
        <td class="text-nowrap"><?= e((string) ($k['minted_by_name'] ?? '—')) ?><br><span class="text-muted"><?= e(format_ts($k['created_at'], $tz, 'M j, Y')) ?></span></td>
        <td><?php if ($revoke !== ''): ?><div class="d-flex justify-content-end"><?= $revoke ?></div><?php endif; ?></td>
    </tr>
<?php endif; ?>
