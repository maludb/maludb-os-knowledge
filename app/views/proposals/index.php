<?php /** The Archivist's proposals for a base (screen `proposals`). Data: b, rows, f, counts, may, estimates, tabs, tz, notice */
$id = (int) $b['base_id'];
$status = $f['status'];
$here = '/bases/' . $id . '/proposals'; ?>
<?= view('shared/header.php', ['id' => 'proposals', 'title' => 'Proposals', 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $id], ['Proposals', null]], 'back' => back_link()]) ?>
<div class="main-content" id="proposals-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('bases/partials/base-tabs.php', ['tabs' => $tabs, 'active' => 'proposals']) ?>
    <div class="fs-12 text-muted mb-3" id="proposals-intro">The Archivist looks over this base every Monday and proposes what to do about what it finds. Nothing happens until you accept it — and what you dismiss is not proposed again for 90 days.</div>
    <?= view('proposals/partials/filters.php', ['here' => $here, 'f' => $f, 'counts' => $counts, 'base_picker' => false]) ?>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-muted" id="proposals-none"><?= $status === 'proposed' ? 'Nothing is proposed right now.' : 'No ' . e($status) . ' proposal' . ($f['kind'] !== '' ? ' of that kind' : '') . '.' ?></div></div>
    <?php endif; ?>
    <div class="row g-3" id="proposals-list">
        <?php foreach ($rows as $p): ?><div class="col-12 col-xl-6"><?= view('proposals/partials/card.php', ['p' => $p, 'may' => $may, 'est' => $estimates[(int) $p['base_id']] ?? null, 'tz' => $tz, 'uniq' => false, 'show_base' => false]) ?></div><?php endforeach; ?>
    </div>
</div>
