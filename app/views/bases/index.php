<?php /** The bases I may read as cards (screen `base-list`). Data: bases, filters, may (create, admin, settings), here, tz, notice */
$action = ($may['settings'] ? hx_link('/admin/settings', '<i class="feather-sliders me-1"></i>Settings', 'btn btn-light btn-touch me-2', 'id="base-list-settings-link"') : '')
    . ($may['create'] ? hx_link('/bases/new', '<i class="feather-plus me-1"></i>Create a knowledge base', 'btn btn-primary btn-touch', 'id="base-list-add-btn"') : ''); ?>
<?= view('shared/header.php', ['id' => 'base-list', 'title' => 'Knowledge bases', 'crumbs' => [['Home', '/'], ['Bases', null]], 'action' => $action]) ?>
<div class="main-content" id="base-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/bases" hx-get="/bases" hx-target="#page-content" hx-swap="innerHTML" hx-trigger="change, submit, keyup changed delay:400ms from:#base-filter-q" hx-push-url="true" id="base-filters" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-5"><label class="form-label fs-12 text-muted" for="base-filter-q">Name</label><input type="search" name="q" id="base-filter-q" class="form-control btn-touch" value="<?= e($filters['q']) ?>" maxlength="80"></div>
        <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="base-filter-status">Status</label>
            <select name="status" id="base-filter-status" class="form-select btn-touch"><option value="">Any</option><?php foreach (BASE_STATUSES as $s => $_): ?><option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e(base_status_chip($s)[0]) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="base-filter-mine"><input type="checkbox" class="form-check-input mt-0" name="mine" value="1" id="base-filter-mine" <?= $filters['mine'] ? 'checked' : '' ?>>Mine</label></div>
        <div class="col-6 col-md-2"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="base-filter-archived"><input type="checkbox" class="form-check-input mt-0" name="archived" value="1" id="base-filter-archived" <?= $filters['archived'] ? 'checked' : '' ?>>Archived</label></div>
        <noscript><div class="col-12"><button type="submit" class="btn btn-light btn-touch w-100">Apply</button></div></noscript>
    </div></form>
    <div class="row g-3" id="base-list">
        <?php if ($bases === []): ?>
            <div class="col-12"><div class="card"><div class="card-body text-center py-4" id="base-list-empty">
                <?php if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['mine'] || $filters['archived']): ?>
                    <div class="text-muted fs-12">No knowledge base matches these filters.</div>
                <?php elseif ($may['create']): ?>
                    <div class="fw-semibold mb-1">No knowledge base yet</div>
                    <div class="fs-12 text-muted mb-3">Create one, add documents, ask.</div>
                    <?= hx_link('/bases/new', '<i class="feather-plus me-1"></i>Create a knowledge base', 'btn btn-primary btn-touch', 'id="base-list-empty-add-btn"') ?>
                <?php else: ?>
                    <div class="text-muted fs-12">No knowledge base has been shared with you yet.</div>
                <?php endif; ?>
            </div></div></div>
        <?php endif; ?>
        <?php foreach ($bases as $b): ?><div class="col-12 col-md-6 col-xl-4"><?= view('bases/partials/base-card.php', ['b' => $b, 'here' => $here, 'tz' => $tz, 'admin' => $may['admin']]) ?></div><?php endforeach; ?>
    </div>
</div>
