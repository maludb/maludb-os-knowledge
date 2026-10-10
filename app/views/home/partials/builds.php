<?php /** Builds running (home-builds; curators). Data: builds (home_builds: build_id, base_id, base_name, status, percent, eta_seconds), poll. While any is queued or running the region polls every 5 s while visible;
 * the endpoint answers 286 once none is, which stops it. */
$attrs = !empty($poll) ? ' hx-get="/home/builds" hx-trigger="every 5s [document.visibilityState==\'visible\']" hx-swap="outerHTML"' : ''; ?>
<div class="card mb-3" id="home-builds"<?= $attrs ?>>
    <div class="card-header"><h5 class="card-title mb-0">Builds running</h5></div>
    <div class="card-body">
    <?php if ($builds === []): ?>
        <div class="fs-12 text-muted" id="home-builds-none">No build is running. A build you start on one of your bases appears here with its progress.</div>
    <?php else: ?>
        <?php foreach ($builds as $b): $bid = (int) $b['build_id']; ?>
        <div class="mb-3" id="home-build-<?= $bid ?>">
            <div class="d-flex justify-content-between gap-2 mb-1"><span class="fw-semibold fs-13 text-truncate"><?= hx_link('/builds/' . $bid, e($b['base_name']), 'text-dark', 'id="home-build-' . $bid . '-link"') ?></span><?= chip(build_status_chip((string) $b['status']), 'home-build-' . $bid . '-status') ?></div>
            <div class="progress mb-1" style="height: 8px" role="progressbar" aria-label="Progress" aria-valuenow="<?= (int) $b['percent'] ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width: <?= (int) $b['percent'] ?>%"></div></div>
            <div class="fs-11 text-muted" id="home-build-<?= $bid ?>-time"><?= (int) $b['percent'] ?> %<?= $b['eta_seconds'] !== null ? ' · about ' . e(build_duration((int) $b['eta_seconds'])) . ' left' : '' ?></div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>
</div>
