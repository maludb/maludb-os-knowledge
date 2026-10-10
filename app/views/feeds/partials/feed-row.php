<?php /** A feed of the table (a row at 1280, a card at 375 — both rendered, CSS shows one). Data: f, may, tz, card (bool) */
$fid = (int) $f['feed_id'];
$prefix = !empty($card) ? 'feed-card-' : 'feed-';
$status = chip(feed_status_chip($f), $prefix . $fid . '-status');
$last = $f['last_sync_at'] === null ? 'never' : format_ts($f['last_sync_at'], $tz, 'M j, g:i A');
$every = feed_every_words((int) $f['every_minutes']) . ($f['enabled'] ? '' : ' · paused');
$error = (string) ($f['last_error'] ?? '');
$actions = '';
if ($may['edit']) {
    $actions .= hx_link('/feeds/' . $fid . '/edit', 'Edit', 'btn btn-light btn-touch', 'id="' . $prefix . $fid . '-edit"');
    $actions .= '<form method="post" action="/feeds/sync.php" hx-post="/feeds/sync.php" hx-target="#flash" class="m-0" id="' . $prefix . $fid . '-sync-form">' . csrf_field()
        . '<input type="hidden" name="feed" value="' . $fid . '"><button type="submit" class="btn btn-light btn-touch" id="' . $prefix . $fid . '-sync"><i class="feather-refresh-cw me-1"></i>Sync now</button></form>';
}
$actions .= '<form method="post" action="/feeds/delete.php" hx-post="/feeds/delete.php" hx-target="#flash" hx-confirm="Stop following this feed? The ' . (int) $f['items'] . ' source' . ((int) $f['items'] === 1 ? '' : 's') . ' it brought will be removed." class="m-0" id="' . $prefix . $fid . '-delete-form">'
    . csrf_field() . '<input type="hidden" name="feed" value="' . $fid . '"><button type="submit" class="btn btn-light btn-touch text-danger" id="' . $prefix . $fid . '-delete"><i class="feather-trash-2 me-1"></i>Delete</button></form>';
if (!empty($card)): ?>
    <div class="card mb-2" id="feed-card-<?= $fid ?>"><div class="card-body p-3">
        <div class="d-flex justify-content-between gap-2 mb-1"><span class="fw-semibold text-break"><i class="feather-rss me-1"></i><?= e(feed_source_words($f)) ?></span><span class="text-nowrap"><?= $status ?></span></div>
        <div class="fs-12 mb-1 text-break"><?= e(feed_arguments_words($f)) ?> · <?= e($f['area_name'] ?? 'whole base') ?></div>
        <div class="fs-12 text-muted mb-1"><?= e($every) ?> · last sync <?= e($last) ?> · <?= (int) $f['items'] ?> item<?= (int) $f['items'] === 1 ? '' : 's' ?></div>
        <?php if ($error !== ''): ?><div class="fs-12 text-danger mb-1 text-break" id="feed-card-<?= $fid ?>-error"><?= e($error) ?></div><?php endif; ?>
        <div class="d-flex flex-wrap gap-2 mt-2"><?= $actions ?></div>
    </div></div>
<?php else: ?>
    <tr id="feed-<?= $fid ?>">
        <td class="text-break"><i class="feather-rss me-1"></i><?= e(feed_source_words($f)) ?><br><span class="fs-12 text-muted"><?= e(feed_arguments_words($f)) ?></span></td>
        <td class="fs-12"><?= e($f['area_name'] ?? 'Whole base') ?></td>
        <td class="fs-12"><?= e($every) ?></td>
        <td class="fs-12"><?= $status ?> <span class="text-muted"><?= e($last) ?></span><?php if ($error !== ''): ?><div class="text-danger text-break" id="feed-<?= $fid ?>-error"><?= e($error) ?></div><?php endif; ?></td>
        <td class="fs-12"><?= (int) $f['items'] ?></td>
        <td><div class="d-flex gap-2 justify-content-end"><?= $actions ?></div></td>
    </tr>
<?php endif; ?>
