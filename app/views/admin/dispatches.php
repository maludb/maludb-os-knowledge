<?php /** Every request to the agents (screen `dispatches`). Data: list (rows, total, page, pages), f (status, kind, base), may (retry, run_link), tz, notice, base_label */
$here = '/admin/dispatches';
$qs = static fn (array $over): string => '?' . http_build_query(array_filter($over + ['status' => $f['status'], 'kind' => $f['kind'], 'base' => $f['base']], static fn ($v): bool => $v !== '' && $v !== null)); ?>
<?= view('shared/header.php', ['id' => 'dispatches', 'title' => 'Agent dispatches', 'crumbs' => [['Home', '/'], ['Admin', '/admin/'], ['Dispatches', null]], 'back' => back_link() ?? ['/admin/', 'Admin']]) ?>
<div class="main-content" id="dispatches-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-3">Every request Knowledge has made of its agents. The worker calls the expert through the operating system; a call that fails is tried again a few minutes later, five times at most.</div>
    <form method="get" action="<?= e($here) ?>" class="row g-2 align-items-end mb-3" id="dispatches-filter">
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="dispatches-filter-status">Status</label>
            <select name="status" id="dispatches-filter-status" class="form-select btn-touch"><option value="">Any status</option>
                <?php foreach (['sent' => 'Queued or running', 'answered' => 'Answered', 'awaiting_approval' => 'Waiting for approval', 'refused' => 'Refused', 'failed' => 'Failed'] as $k => $w): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="dispatches-filter-kind">Kind</label>
            <select name="kind" id="dispatches-filter-kind" class="form-select btn-touch"><option value="">Any kind</option>
                <?php foreach (DISPATCH_KIND_WORDS as $k => $w): ?><option value="<?= e($k) ?>" <?= $f['kind'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" id="dispatches-filter-base-label">Base</label>
            <?= picker_field(['id' => 'dispatches-filter-base', 'name' => 'base', 'source' => 'base', 'value' => $f['base'], 'label' => $base_label, 'labelledby' => 'dispatches-filter-base-label', 'placeholder' => 'Every base', 'title' => 'A knowledge base']) ?></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="dispatches-filter-go">Show</button></div>
    </form>
    <?php if ($list['rows'] === []): ?>
        <div class="card"><div class="card-body text-muted" id="dispatches-none">No dispatch<?= $f['status'] !== '' || $f['kind'] !== '' || $f['base'] ? ' with that filter' : ' yet' ?>.</div></div>
    <?php else: ?>
        <div class="card d-none d-md-block"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="dispatches-table">
            <thead><tr><th>Agent</th><th>Kind</th><th>Base</th><th>Acting</th><th>Status</th><th>Tries</th><th>Next try</th><th>Run</th><th>Created</th><th>Answered</th><th>Reply</th><th></th></tr></thead>
            <tbody><?php foreach ($list['rows'] as $d): ?><?= view('admin/partials/dispatch-row.php', ['d' => $d, 'may' => $may, 'tz' => $tz]) ?><?php endforeach; ?></tbody></table></div></div>
        <div class="d-md-none" id="dispatches-cards"><?php foreach ($list['rows'] as $d): ?><?= view('admin/partials/dispatch-row.php', ['d' => $d, 'may' => $may, 'tz' => $tz, 'card' => true]) ?><?php endforeach; ?></div>
        <?php if ($list['pages'] > 1): ?>
            <div class="d-flex justify-content-between align-items-center mt-3 fs-12" id="dispatches-pages">
                <?= $list['page'] > 1 ? hx_link($here . $qs(['page' => $list['page'] - 1]), '<i class="feather-arrow-left me-1"></i>Newer', 'btn btn-light btn-touch', 'id="dispatches-newer"') : '<span></span>' ?>
                <span class="text-muted">Page <?= (int) $list['page'] ?> of <?= (int) $list['pages'] ?> · <?= (int) $list['total'] ?> dispatches</span>
                <?= $list['page'] < $list['pages'] ? hx_link($here . $qs(['page' => $list['page'] + 1]), 'Older<i class="feather-arrow-right ms-1"></i>', 'btn btn-light btn-touch', 'id="dispatches-older"') : '<span></span>' ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
