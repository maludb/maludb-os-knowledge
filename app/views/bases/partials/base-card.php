<?php /** A base as a card (base-list). Data: b (find_bases row), here, tz, admin (the caller is the admin) */
$bid = (int) $b['base_id'];
$adminView = !empty($admin) && $b['visibility'] === 'private' && empty($b['i_belong']);
$sources = (int) $b['source_count']; $sections = (int) $b['section_count']; ?>
<div class="card h-100 mb-0" id="base-card-<?= $bid ?>"><div class="card-body d-flex flex-column">
    <div class="d-flex align-items-start justify-content-between gap-2">
        <div class="fw-semibold fs-14 min-w-0 text-truncate"><?= hx_link(with_back('/bases/' . $bid, $here), e($b['name']), 'text-dark', 'id="base-card-' . $bid . '-link"') ?></div>
        <?= chip(base_level_chip((string) $b['my_level']), 'base-card-' . $bid . '-level') ?>
    </div>
    <?php if (($b['description'] ?? '') !== ''): ?><div class="fs-12 text-muted text-truncate"><?= e($b['description']) ?></div><?php endif; ?>
    <div class="mt-2 d-flex flex-wrap gap-1 fs-12">
        <?= chip(base_status_chip((string) $b['status']), 'base-card-' . $bid . '-status') ?>
        <?= chip(base_visibility_chip((string) $b['visibility']), 'base-card-' . $bid . '-visibility') ?>
        <?php if ($b['archived_at'] !== null): ?><?= chip(['archived', 'dark'], 'base-card-' . $bid . '-archived') ?><?php endif; ?>
        <?php if ((int) $b['stale_count'] > 0): ?><?= chip(['needs a rebuild', 'warning'], 'base-card-' . $bid . '-stale') ?><?php endif; ?>
        <?php if ($adminView): ?><?= chip(['admin view', 'danger'], 'base-card-' . $bid . '-admin-view') ?><?php endif; ?>
    </div>
    <div class="fs-12 text-muted mt-2"><?= $sources ?> source<?= $sources === 1 ? '' : 's' ?> · <?= $sections ?> section<?= $sections === 1 ? '' : 's' ?></div>
    <?php if ($b['running_build_id'] !== null): ?>
        <div class="progress mt-2" style="height: 6px" id="base-card-<?= $bid ?>-building" role="progressbar" aria-label="Building"><div class="progress-bar progress-bar-striped progress-bar-animated w-100"></div></div>
    <?php endif; ?>
    <div class="fs-11 text-muted mt-auto pt-2">Last built <?= $b['last_built_at'] !== null ? e(format_ts($b['last_built_at'], $tz, 'M j, Y')) : 'never' ?> · owner <?= e((string) $b['owner_name']) ?></div>
</div></div>
