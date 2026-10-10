<?php /** Every key of every base (screen `admin-keys`; keys.all). Data: keys (all_keys rows), f (base, status), tz, base_label, notice */ ?>
<?= view('shared/header.php', ['id' => 'admin-keys', 'title' => 'Every key', 'crumbs' => [['Home', '/'], ['Admin', '/admin/'], ['Keys', null]], 'back' => back_link() ?? ['/admin/', 'Admin']]) ?>
<div class="main-content" id="admin-keys-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-3">The keys outside systems ask the bases with. A key is minted on its base's Keys screen and shown once; here the admin sees them all and can revoke any.</div>
    <form method="get" action="/admin/keys" class="row g-2 align-items-end mb-3" id="admin-keys-filter">
        <div class="col-12 col-md-5"><label class="form-label fs-12 text-muted" id="admin-keys-filter-base-label">Base</label>
            <?= picker_field(['id' => 'admin-keys-filter-base', 'name' => 'base', 'source' => 'base', 'value' => $f['base'], 'label' => $base_label, 'labelledby' => 'admin-keys-filter-base-label', 'placeholder' => 'Every base', 'title' => 'A knowledge base']) ?></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="admin-keys-filter-status">Status</label>
            <select name="status" id="admin-keys-filter-status" class="form-select btn-touch"><option value="">Any</option><?php foreach (KEY_STATUSES as $k => [$w]): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="admin-keys-go">Show</button></div>
    </form>
    <?php if ($keys === []): ?>
        <div class="card"><div class="card-body text-muted" id="admin-keys-none">No key<?= $f['base'] || $f['status'] !== '' ? ' with that filter' : ' has been minted yet' ?>.</div></div>
    <?php else: ?>
        <div class="card d-none d-md-block"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="admin-keys-table">
            <thead><tr><th>Base</th><th>Key</th><th>Area</th><th>Per minute</th><th>Per day</th><th>Today</th><th>Last used</th><th>Status</th><th>Minted</th><th></th></tr></thead>
            <tbody><?php foreach ($keys as $k): ?><?= view('admin/partials/key-row.php', ['k' => $k, 'tz' => $tz]) ?><?php endforeach; ?></tbody></table></div></div>
        <div class="d-md-none" id="admin-keys-cards"><?php foreach ($keys as $k): ?><?= view('admin/partials/key-row.php', ['k' => $k, 'tz' => $tz, 'card' => true]) ?><?php endforeach; ?></div>
    <?php endif; ?>
</div>
