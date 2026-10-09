<?php /** A base's sources (screen `sources`). Data: b, sources, pending, filters, may (add, curate), areas, tabs, tz, here, notice */
$id = (int) $b['base_id']; ?>
<?= view('shared/header.php', ['id' => 'sources', 'title' => 'Sources', 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $id], ['Sources', null]],
    'back' => back_link(),
    'action' => $may['add'] ? hx_link('/sources/new?base=' . $id, '<i class="feather-upload me-1"></i>Add documents', 'btn btn-primary btn-touch', 'id="sources-add-btn"') : '']) ?>
<div class="main-content" id="sources-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('bases/partials/base-tabs.php', ['tabs' => $tabs, 'active' => 'sources']) ?>
    <form method="get" action="/bases/<?= $id ?>/sources" hx-get="/bases/<?= $id ?>/sources" hx-target="#page-content" hx-swap="innerHTML" hx-trigger="change, submit, keyup changed delay:400ms from:#sources-filter-q" hx-push-url="true" id="sources-filters" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="sources-filter-q">Title</label><input type="search" name="q" id="sources-filter-q" class="form-control btn-touch" maxlength="80" value="<?= e($filters['q']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="sources-filter-kind">Kind</label>
            <select name="kind" id="sources-filter-kind" class="form-select btn-touch"><option value="">Any</option><?php foreach (SOURCE_KINDS as $k => [$w]): ?><option value="<?= e($k) ?>" <?= $filters['kind'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="sources-filter-status">Status</label>
            <select name="status" id="sources-filter-status" class="form-select btn-touch"><option value="">Live</option><?php foreach (SOURCE_STATUSES as $k => [$w]): ?><option value="<?= e($k === 'forgotten' ? 'removed' : $k) ?>" <?= $filters['status'] === $k || ($k === 'forgotten' && $filters['status'] === 'removed') ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="sources-filter-area">Focus area</label>
            <select name="area" id="sources-filter-area" class="form-select btn-touch"><option value="">Any</option><?php foreach ($areas as $aid => $a): ?><option value="<?= (int) $aid ?>" <?= (int) $filters['area'] === (int) $aid ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
        <noscript><div class="col-12"><button type="submit" class="btn btn-light btn-touch w-100">Apply</button></div></noscript>
    </div></form>
    <?= view('sources/partials/sources-status.php', ['b' => $b, 'sources' => $sources, 'pending' => $pending, 'filters' => $filters, 'curate' => $may['curate'], 'tz' => $tz, 'here' => $here]) ?>
</div>
