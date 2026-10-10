<?php /** A build's progress (build-view, and the running card on builds). Data: p (kn_build_progress), poll (bool), tz, cancel (bool: Cancel shown while live — inside the polled region, so it leaves with the 286). While queued or running it polls every 5 s while visible; the partial answers 286 once finished. */
$live = in_array($p['status'], ['queued', 'running'], true);
$attrs = $poll && $live ? ' hx-get="/builds/' . (int) $p['build_id'] . '/progress" hx-trigger="every 5s [document.visibilityState==\'visible\']" hx-swap="outerHTML"' : ''; ?>
<div id="build-progress"<?= $attrs ?>>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <?= chip(build_status_chip((string) $p['status']), 'build-progress-status') ?>
        <span class="fs-12 text-muted" id="build-progress-counts"><?= (int) $p['items_done'] ?> done · <?= (int) $p['items_failed'] ?> failed · <?= (int) $p['items_queued'] ?> queued · <?= (int) $p['items_running'] ?> running · <?= (int) $p['items_skipped'] ?> skipped of <?= (int) $p['items_total'] ?></span>
    </div>
    <div class="progress mb-2" style="height: 10px" role="progressbar" aria-label="Progress" aria-valuenow="<?= (int) $p['percent'] ?>" aria-valuemin="0" aria-valuemax="100">
        <div class="progress-bar<?= $live ? ' progress-bar-striped progress-bar-animated' : '' ?>" id="build-progress-bar" style="width: <?= (int) $p['percent'] ?>%"></div>
    </div>
    <div class="fs-12 text-muted" id="build-progress-time"><?= (int) $p['percent'] ?>%<?= $p['elapsed_seconds'] !== null ? ' · ' . e(build_duration((int) $p['elapsed_seconds'])) . ' so far' : '' ?><?= $p['eta_seconds'] !== null ? ' · about ' . e(build_duration((int) $p['eta_seconds'])) . ' left' : '' ?></div>
    <?php if ($live && !empty($cancel)): ?>
        <form method="post" action="/builds/cancel.php" hx-post="/builds/cancel.php" hx-target="#flash" hx-confirm="Cancel this build? The section in hand finishes in the engine." class="mt-3 mb-0" id="build-cancel-form">
            <?= csrf_field() ?><input type="hidden" name="build" value="<?= (int) $p['build_id'] ?>"><button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="build-cancel">Cancel the build</button>
        </form>
    <?php endif; ?>
    <?php if (($p['error'] ?? null) !== null): ?><div class="alert alert-<?= $p['status'] === 'done' ? 'warning' : 'danger' ?> py-2 fs-12 mt-2 mb-0" id="build-progress-error"><?= e(build_error_words($p['error'], (int) $p['items_skipped'] + (int) $p['items_queued'])) ?></div><?php endif; ?>
</div>
