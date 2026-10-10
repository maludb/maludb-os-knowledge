<?php /** Purge questions now (screen `admin-purge`; retention.manage). Data: days, would (how many a purge would remove), retention (the setting), purged (a landing's count|null) */ ?>
<?= view('shared/header.php', ['id' => 'admin-purge', 'title' => 'Purge questions', 'crumbs' => [['Home', '/'], ['Admin', '/admin/'], ['Settings', '/admin/settings'], ['Purge', null]], 'back' => back_link() ?? ['/admin/settings', 'Settings']]) ?>
<div class="main-content" id="admin-purge-content">
    <?php if ($purged !== null): ?><div class="alert alert-success mb-3" role="status" id="notice-banner"><?= $purged === 0 ? 'Nothing was old enough to purge.' : 'Purged ' . (int) $purged . ' question' . ($purged === 1 ? '' : 's') . '.' ?></div><?php endif; ?>
    <div class="card mb-3"><div class="card-body">
        <form method="get" action="/admin/purge" class="row g-2 align-items-end" id="admin-purge-days-form">
            <div class="col-8 col-md-4"><label class="form-label fs-12 text-muted" for="admin-purge-days">Questions older than (days)</label><input type="number" name="days" id="admin-purge-days" class="form-control btn-touch" min="1" max="3650" value="<?= (int) $days ?>"></div>
            <div class="col-4 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="admin-purge-count-btn">Count</button></div>
        </form>
        <div class="fs-12 text-muted mt-2">The worker already purges questions older than the retention setting (<?= (int) $retention ?> days) once a day. This purges before its time.</div>
    </div></div>
    <div class="card" id="purge-confirm"><div class="card-body">
        <div class="fw-semibold mb-1" id="admin-purge-would"><?= $would === 0 ? 'No question is older than ' . (int) $days . ' days.' : (int) $would . ' question' . ($would === 1 ? ' is' : 's are') . ' older than ' . (int) $days . ' days and would be removed, with their citations and ratings.' ?></div>
        <div class="fs-12 text-muted mb-3">An answer behind an active pin is kept — the pin holds its words.</div>
        <?php if ($would > 0): ?>
        <form method="post" action="/admin/purge.php" hx-post="/admin/purge.php" hx-target="#flash" hx-confirm="Remove <?= (int) $would ?> question<?= $would === 1 ? '' : 's' ?> older than <?= (int) $days ?> days for good?">
            <?= csrf_field() ?><input type="hidden" name="days" value="<?= (int) $days ?>"><input type="hidden" name="confirm" value="yes">
            <button type="submit" class="btn btn-danger btn-touch w-100" id="admin-purge-btn"><i class="feather-trash-2 me-1"></i>Purge <?= (int) $would ?> question<?= $would === 1 ? '' : 's' ?></button>
        </form>
        <?php endif; ?>
    </div></div>
</div>
