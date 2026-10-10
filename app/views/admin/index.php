<?php /** The admin area (screen `admin`). Data: pages (admin_index: id, title, url, icon, blurb, allowed, state, warn) */ ?>
<?= view('shared/header.php', ['id' => 'admin', 'title' => 'Admin', 'crumbs' => [['Home', '/'], ['Admin', null]], 'back' => back_link()]) ?>
<div class="main-content" id="admin-content">
    <div class="fs-12 text-muted mb-3">Everything that runs Knowledge, in one place. Hiring the agents, granting access and approving connections between applications are the operating system's.</div>
    <div class="row g-3" id="admin-pages">
    <?php foreach ($pages as $p): $id = (string) $p['id']; ?>
        <div class="col-12 col-md-6 col-xl-4"><div class="card h-100 mb-0<?= $p['allowed'] ? '' : ' opacity-75' ?>" id="admin-page-<?= e($id) ?>"><div class="card-body d-flex gap-3 align-items-start">
            <span class="avatar-text avatar-md rounded flex-shrink-0"><i class="<?= e($p['icon']) ?>"></i></span>
            <div class="min-w-0">
                <div class="fw-semibold fs-14"><?= $p['allowed'] ? hx_link(with_back($p['url'], '/admin/'), e($p['title']), 'text-dark stretched-link', 'id="admin-page-' . e($id) . '-link"') : e($p['title']) ?></div>
                <div class="fs-12 text-muted"><?= e($p['blurb']) ?></div>
                <div class="fs-12 mt-1 <?= !empty($p['warn']) ? 'text-danger fw-semibold' : '' ?>" id="admin-page-<?= e($id) ?>-state"><?= e($p['state']) ?></div>
                <?php if (!$p['allowed']): ?><div class="fs-11 text-muted">Another role's page.</div><?php endif; ?>
            </div>
        </div></div></div>
    <?php endforeach; ?>
    </div>
</div>
