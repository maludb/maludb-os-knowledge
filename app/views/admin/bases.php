<?php /** Every base (screen `admin-bases`). Data: rows (admin_bases), f (visibility, status, archived), tz */ ?>
<?= view('shared/header.php', ['id' => 'admin-bases', 'title' => 'Every base', 'crumbs' => [['Home', '/'], ['Admin', '/admin/'], ['Bases', null]], 'back' => back_link() ?? ['/admin/', 'Admin']]) ?>
<div class="main-content" id="admin-bases-content">
    <div class="fs-12 text-muted mb-3">The admin sees every base, private ones too. Opening a private base you are not a member of is logged.</div>
    <form method="get" action="/admin/bases" class="row g-2 align-items-end mb-3" id="admin-bases-filter">
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="admin-bases-visibility">Visibility</label>
            <select name="visibility" id="admin-bases-visibility" class="form-select btn-touch"><option value="">Any</option><?php foreach (['private' => 'Private', 'internal' => 'Internal'] as $k => $w): ?><option value="<?= e($k) ?>" <?= $f['visibility'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="admin-bases-status">Status</label>
            <select name="status" id="admin-bases-status" class="form-select btn-touch"><option value="">Any</option><?php foreach (array_keys(BASE_STATUSES) as $k): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $k)) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="admin-bases-archived"><input type="checkbox" class="form-check-input mt-0" name="archived" value="1" id="admin-bases-archived" <?= $f['archived'] ? 'checked' : '' ?>>With archived</label></div>
        <div class="col-6 col-md-3"><button type="submit" class="btn btn-light btn-touch w-100" id="admin-bases-go">Show</button></div>
    </form>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-muted" id="admin-bases-none">No base with that filter.</div></div>
    <?php else: ?>
        <div class="card d-none d-md-block"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="admin-bases-table">
            <thead><tr><th>Base</th><th>Owner</th><th>Visibility</th><th>Status</th><th class="text-end">Sources</th><th class="text-end">Sections</th><th class="text-end">Questions (30 days)</th><th>Last built</th></tr></thead>
            <tbody><?php foreach ($rows as $b): ?><?= view('admin/partials/base-row.php', ['b' => $b, 'tz' => $tz]) ?><?php endforeach; ?></tbody></table></div></div>
        <div class="d-md-none" id="admin-bases-cards"><?php foreach ($rows as $b): ?><?= view('admin/partials/base-row.php', ['b' => $b, 'tz' => $tz, 'card' => true]) ?><?php endforeach; ?></div>
    <?php endif; ?>
</div>
