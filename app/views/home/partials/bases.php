<?php /** My bases (home-bases): cards. Data: bases, may, tz. The region refreshes when a build it polls for finishes (the event baseChanged), and a card of an empty base says Add documents. */
$count = count($bases); ?>
<div id="home-bases" hx-get="/home/builds?region=bases" hx-trigger="baseChanged from:body" hx-swap="outerHTML">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0" id="home-bases-title">My bases</h5>
        <?php if ($may['create']): ?><a href="/bases/new" class="btn btn-light btn-touch" id="home-create-base-btn" hx-get="/bases/new" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="/bases/new"><i class="feather-plus me-1"></i>Create a knowledge base</a><?php endif; ?>
    </div>
    <div class="row g-3" id="home-bases-list">
    <?php foreach ($bases as $b): $bid = (int) $b['base_id']; $empty = (int) $b['source_count'] === 0; ?>
        <div class="col-md-6 col-xl-4"><div class="card h-100 mb-0" id="home-base-<?= $bid ?>"><div class="card-body d-flex flex-column">
            <div class="d-flex align-items-start justify-content-between gap-2">
                <div class="fw-semibold fs-14 min-w-0 text-truncate"><?= hx_link(with_back('/bases/' . $bid, '/'), e($b['name']), 'text-dark', 'id="home-base-' . $bid . '-link"') ?></div>
                <?= chip(base_level_chip((string) $b['my_level']), 'home-base-' . $bid . '-level') ?>
            </div>
            <?php if (($b['description'] ?? '') !== ''): ?><div class="fs-12 text-muted text-truncate"><?= e($b['description']) ?></div><?php endif; ?>
            <div class="mt-2 d-flex flex-wrap gap-1 fs-12">
                <?= chip(base_status_chip((string) $b['status']), 'home-base-' . $bid . '-status') ?>
                <span class="badge bg-soft-secondary text-dark"><?= (int) $b['source_count'] ?> source<?= (int) $b['source_count'] === 1 ? '' : 's' ?> · <?= (int) $b['section_count'] ?> section<?= (int) $b['section_count'] === 1 ? '' : 's' ?></span>
                <?php if ((int) $b['stale_count'] > 0): ?><?= chip(['needs a rebuild', 'warning'], 'home-base-' . $bid . '-stale') ?><?php endif; ?>
            </div>
            <div class="fs-11 text-muted mt-2">Last built <?= $b['last_built_at'] !== null ? e(format_ts($b['last_built_at'], $tz, 'M j, Y')) : 'never' ?></div>
            <?php if ($empty && ($b['my_level'] === 'own' || $b['my_level'] === 'curate')): ?>
                <div class="mt-auto pt-2"><?= hx_link('/sources/new?base=' . $bid, '<i class="feather-plus me-1"></i>Add documents', 'btn btn-primary btn-touch w-100', 'id="home-base-' . $bid . '-add"') ?></div>
            <?php endif; ?>
        </div></div></div>
    <?php endforeach; ?>
    </div>
</div>
