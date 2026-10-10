<?php /** A base of the admin's list (a row at 1280, a card at 375). Data: b (admin_bases row), tz, card (bool). The id of both is admin-base-row-{id} / admin-base-card-{id}. */
$bid = (int) $b['base_id'];
$prefix = !empty($card) ? 'admin-base-card-' : 'admin-base-row-';
$lock = $b['visibility'] === 'private' ? '<i class="feather-lock me-1" title="Private" aria-label="Private"></i>' : '';
$name = hx_link(with_back('/bases/' . $bid, '/admin/bases'), e($b['name']), 'text-dark fw-semibold', 'id="' . $prefix . $bid . '-link"');
$vis = chip(base_visibility_chip((string) $b['visibility']), $prefix . $bid . '-visibility');
$status = chip(base_status_chip((string) $b['status']), $prefix . $bid . '-status') . ($b['archived_at'] !== null ? ' ' . chip(['archived', 'dark'], $prefix . $bid . '-archived') : '');
if (!empty($card)): ?>
    <div class="card mb-2" id="admin-base-card-<?= $bid ?>"><div class="card-body p-3">
        <div class="d-flex justify-content-between gap-2 mb-1"><span class="text-break"><?= $lock ?><?= $name ?></span><span class="text-nowrap"><?= $vis ?></span></div>
        <div class="fs-12 text-muted mb-1">Owner <?= e((string) $b['owner_name']) ?></div>
        <div class="d-flex flex-wrap gap-1 mb-1"><?= $status ?></div>
        <div class="fs-12"><?= (int) $b['source_count'] ?> sources · <?= (int) $b['section_count'] ?> sections · <?= (int) $b['questions_30d'] ?> questions in 30 days</div>
        <div class="fs-11 text-muted">Last built <?= $b['last_built_at'] !== null ? e(format_ts($b['last_built_at'], $tz, 'M j, Y')) : 'never' ?></div>
    </div></div>
<?php else: ?>
    <tr id="admin-base-row-<?= $bid ?>" class="<?= $b['archived_at'] !== null ? 'text-muted' : '' ?>">
        <td class="text-break"><?= $lock ?><?= $name ?></td><td><?= e((string) $b['owner_name']) ?></td><td><?= $vis ?></td><td class="text-nowrap"><?= $status ?></td>
        <td class="text-end"><?= (int) $b['source_count'] ?></td><td class="text-end"><?= (int) $b['section_count'] ?></td><td class="text-end"><?= (int) $b['questions_30d'] ?></td>
        <td class="text-nowrap"><?= $b['last_built_at'] !== null ? e(format_ts($b['last_built_at'], $tz, 'M j, Y')) : 'never' ?></td>
    </tr>
<?php endif; ?>
