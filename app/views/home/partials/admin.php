<?php /** The admin's cards (home-admin; settings.manage): usage this month by base, failed builds (7 days), pending or failed dispatches, open proposals — each links to its page. Data: a (admin_home), may, tz */
$warn = (int) ($a['usage']['warn_pct'] ?? 80); ?>
<div id="home-admin">
<?php if ($a['usage'] !== null): ?>
    <div class="card mb-3" id="home-usage">
        <div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Usage this month</h5><?= hx_link('/admin/usage', 'Usage', 'fs-12 fw-semibold', 'id="home-usage-link"') ?></div>
        <div class="card-body">
        <?php if ($a['usage']['rows'] === []): ?><div class="fs-12 text-muted" id="home-usage-none">Text read and engine calls, by base, appear here.</div><?php endif; ?>
        <?php foreach ($a['usage']['rows'] as $r): ?>
            <div class="d-flex justify-content-between gap-2 mb-1 fs-12" id="home-usage-<?= (int) $r['base_id'] ?>"><span class="text-truncate"><?= e($r['name']) ?></span>
                <span class="text-nowrap text-muted"><?= number_format($r['calls']) ?> calls · <?= number_format($r['input_tokens'] + $r['output_tokens']) ?> tokens · <?= chip(usage_chip($r['pct'], $warn)) ?></span></div>
        <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
    <div class="card mb-3" id="home-failed-builds">
        <div class="card-header"><h5 class="card-title mb-0">Failed builds (7 days)</h5></div>
        <div class="card-body">
        <?php if ($a['failed_builds'] === []): ?><div class="fs-12 text-muted" id="home-failed-builds-none">No build failed in the last week.</div><?php endif; ?>
        <?php foreach ($a['failed_builds'] as $b): ?>
            <div class="mb-1 fs-12" id="home-failed-build-<?= (int) $b['build_id'] ?>"><?= hx_link('/builds/' . (int) $b['build_id'], e($b['base_name']), 'text-dark') ?> <span class="text-muted">· <?= e(format_ts($b['finished_at'], $tz, 'M j, g:i A')) ?></span></div>
        <?php endforeach; ?>
        </div>
    </div>
    <div class="card mb-3" id="home-dispatches">
        <div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Dispatches</h5><?= hx_link('/admin/dispatches', 'Dispatches', 'fs-12 fw-semibold', 'id="home-dispatches-link"') ?></div>
        <div class="card-body fs-12">
        <?php if ($a['dispatches']['pending'] === 0 && $a['dispatches']['failed'] === 0): ?><div class="text-muted" id="home-dispatches-none">No request to an agent is waiting or failed.</div><?php else: ?>
            <div id="home-dispatches-counts"><?= (int) $a['dispatches']['pending'] ?> waiting · <?= (int) $a['dispatches']['failed'] ?> failed</div>
            <?php foreach ($a['dispatches']['latest_failed'] as $d): ?><div class="text-muted" id="home-dispatch-<?= (int) $d['dispatch_id'] ?>"><?= e((string) $d['agent_name']) ?> · <?= e(DISPATCH_KIND_WORDS[$d['kind']] ?? $d['kind']) ?> · <?= e($d['status']) ?></div><?php endforeach; ?>
        <?php endif; ?>
        </div>
    </div>
    <div class="card mb-3" id="home-proposals">
        <div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Open proposals</h5><?= hx_link('/admin/proposals', 'Proposals', 'fs-12 fw-semibold', 'id="home-proposals-link"') ?></div>
        <div class="card-body fs-12">
        <?php if ($a['open_proposals']['count'] === 0): ?><div class="text-muted" id="home-proposals-none">The Archivist has nothing open to propose.</div><?php else: ?>
            <div id="home-proposals-count"><?= (int) $a['open_proposals']['count'] ?> open</div>
            <?php foreach ($a['open_proposals']['latest'] as $p): ?><div class="text-muted text-break"><?= e((string) $p['base_name']) ?> · <?= e($p['title']) ?></div><?php endforeach; ?>
        <?php endif; ?>
        </div>
    </div>
</div>
