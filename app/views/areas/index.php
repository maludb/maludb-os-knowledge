<?php /** A base's focus areas (screen `areas`). Data: b, areas, active, unassigned, empty, may, showArchived, tabs, here, notice */
$id = (int) $b['base_id'];
$proposed = array_values(array_filter($areas, static fn (array $a): bool => $a['status'] === 'proposed'));
$shown = array_values(array_filter($areas, static fn (array $a): bool => $a['status'] !== 'proposed')); ?>
<?= view('shared/header.php', ['id' => 'areas', 'title' => 'Focus areas', 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $id], ['Areas', null]], 'back' => back_link(),
    'action' => $may['manage'] ? hx_link('/areas/new?base=' . $id, '<i class="feather-plus me-1"></i>New area', 'btn btn-primary btn-touch', 'id="areas-add-btn"') : '']) ?>
<?php if ($may['manage'] && is_file(APP_ROOT . '/html/agents/propose-areas.php')): // slice 8: the expert is woken by a dispatch ?>
    <form method="post" action="/agents/propose-areas.php" hx-post="/agents/propose-areas.php" hx-target="#flash" class="px-3 pb-2" id="areas-propose-form">
        <?= csrf_field() ?><input type="hidden" name="base" value="<?= $id ?>">
        <button type="submit" class="btn btn-light btn-touch" id="areas-propose-btn"><i class="feather-cpu me-1"></i>Propose areas</button>
        <span class="fs-11 text-muted ms-1">The Knowledge Expert reads the graph and proposes areas; you accept or dismiss them here.</span>
    </form>
<?php endif; ?>
<div class="main-content" id="areas-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('bases/partials/base-tabs.php', ['tabs' => $tabs, 'active' => 'areas']) ?>
    <?php if ($proposed !== []): ?>
        <h6 class="fw-bold mb-2">Proposed</h6>
        <div class="row g-3 mb-3" id="areas-proposed"><?php foreach ($proposed as $a): ?><div class="col-12 col-md-6 col-xl-4"><?= view('areas/partials/proposal-card.php', ['a' => $a, 'may' => $may]) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <div class="row g-3 mb-3" id="areas-list">
        <?php if ($shown === []): ?><div class="col-12"><div class="card"><div class="card-body fs-12 text-muted" id="areas-none">Focus areas narrow a question to part of the base. None yet.</div></div></div><?php endif; ?>
        <?php foreach ($shown as $a): ?><div class="col-12 col-md-6 col-xl-4"><?= view('areas/partials/area-card.php', ['a' => $a, 'may' => $may, 'here' => $here]) ?></div><?php endforeach; ?>
    </div>
    <?php if ($may['curate']): ?>
        <?= view('areas/partials/unassigned.php', ['unassigned' => $unassigned, 'empty' => $empty, 'active' => $active, 'may' => $may, 'base_id' => $id]) ?>
        <?= hx_link('/bases/' . $id . '/areas' . ($showArchived ? '' : '?archived=1'), $showArchived ? 'Hide the archived areas' : 'Show the archived areas', 'btn btn-light btn-touch', 'id="areas-archived-toggle"') ?>
    <?php endif; ?>
</div>
