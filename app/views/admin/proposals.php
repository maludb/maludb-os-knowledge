<?php /** Every base's proposals (screen `admin-proposals`). Data: rows, estimates, f, tz, notice, base_label */
$here = '/admin/proposals'; ?>
<?= view('shared/header.php', ['id' => 'admin-proposals', 'title' => 'Proposals', 'crumbs' => [['Home', '/'], ['Admin', '/admin/'], ['Proposals', null]], 'back' => back_link() ?? ['/admin/', 'Admin']]) ?>
<div class="main-content" id="admin-proposals-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-3">The Archivist's proposals for every base. Accepting or dismissing is each base's curators' to do — and the admin's.</div>
    <?= view('proposals/partials/filters.php', ['here' => $here, 'f' => $f, 'counts' => [], 'base_picker' => true, 'base_label' => $base_label, 'extra' => ['base' => $f['base']]]) ?>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="admin-proposals-none">No <?= e($f['status']) ?> proposal<?= $f['kind'] !== '' || $f['base'] ? ' with that filter' : '' ?>.</div></div><?php endif; ?>
    <div class="row g-3" id="admin-proposals-list">
        <?php foreach ($rows as $p): ?><div class="col-12 col-xl-6"><?= view('proposals/partials/card.php', ['p' => $p, 'may' => ['decide' => true], 'est' => $estimates[(int) $p['base_id']] ?? null, 'tz' => $tz, 'uniq' => true, 'show_base' => true]) ?></div><?php endforeach; ?>
    </div>
</div>
