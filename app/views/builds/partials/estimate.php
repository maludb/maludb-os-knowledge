<?php /** What a build would do now, against the month (builds). Data: b, est, calls, blocker, may */
$id = (int) $b['base_id'];
$used = (int) $est['month_used_chars']; $limit = max(1, (int) $est['month_limit_chars']);
$pct = min(100, (int) round(100 * $used / $limit)); ?>
<div class="card mb-3" id="build-estimate"><div class="card-header"><h5 class="card-title mb-0">A build now</h5></div><div class="card-body">
    <div class="row g-2 mb-2 fs-12">
        <div class="col-6 col-md-3"><div class="text-muted">Sections</div><div class="fs-5 fw-semibold" id="build-estimate-sections"><?= (int) $est['sections'] ?></div><div class="text-muted"><?= (int) $est['queued_sections'] ?> new · <?= (int) $est['stale_sections'] ?> stale · <?= (int) $est['failed_sections'] ?> failed</div></div>
        <div class="col-6 col-md-3"><div class="text-muted">Characters</div><div class="fs-5 fw-semibold" id="build-estimate-chars"><?= number_format((int) $est['characters']) ?></div></div>
        <div class="col-6 col-md-3"><div class="text-muted">Engine calls</div><div class="fs-5 fw-semibold" id="build-estimate-calls">about <?= (int) $calls ?></div></div>
        <div class="col-6 col-md-3"><div class="text-muted">This month</div><div class="fs-6 fw-semibold" id="build-estimate-month"><?= number_format($used) ?> of <?= number_format($limit) ?></div></div>
    </div>
    <div class="progress mb-2" style="height: 8px" role="progressbar" aria-label="This month's text" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar<?= $pct >= 80 ? ' bg-warning' : '' ?>" style="width: <?= $pct ?>%"></div></div>
    <div class="fs-12 mb-3" id="build-estimate-fit"><?php if (!empty($est['within_limit'])): ?>Within this month's limit.<?php else: $fits = max(0, (int) $est['remaining_chars']); ?>Stops when the limit is reached: <?= number_format($fits) ?> of <?= number_format((int) $est['characters']) ?> characters fit.<?php endif; ?></div>
    <?php if ($may['run']): ?>
        <form method="post" action="/builds/start.php" hx-post="/builds/start.php" hx-target="#flash" class="m-0" id="build-start-form">
            <?= csrf_field() ?><input type="hidden" name="base" value="<?= $id ?>">
            <button type="submit" class="btn btn-primary btn-touch w-100" id="build-start" <?= $blocker !== null ? 'disabled aria-describedby="build-start-why"' : '' ?>>Start the build</button>
            <?php if ($blocker !== null): ?><div class="fs-12 text-muted mt-1" id="build-start-why"><?= e($blocker) ?></div><?php endif; ?>
        </form>
    <?php endif; ?>
    <div class="fs-12 text-muted mt-2">New sources are built on their own: auto-extract is <?= !empty($b['auto_extract']) ? 'on' : 'off' ?> (<?= hx_link('/bases/' . $id . '/settings', 'the base\'s settings') ?>).</div>
</div></div>
