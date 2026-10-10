<?php /** What the base could not answer (screen `gaps`). Data: b, gaps, down, since, tabs, tz */
$id = (int) $b['base_id']; ?>
<?= view('shared/header.php', ['id' => 'gaps', 'title' => 'Gaps', 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $id], ['Questions', '/bases/' . $id . '/questions'], ['Gaps', null]], 'back' => back_link()]) ?>
<div class="main-content" id="gaps-content">
    <?= view('bases/partials/base-tabs.php', ['tabs' => $tabs, 'active' => 'questions']) ?>
    <nav class="kn-chips mb-3" id="gaps-since" aria-label="Since">
        <?php foreach (['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'] as $d => $w): ?>
            <?= hx_link('/bases/' . $id . '/gaps?since=' . $d, e($w), 'btn btn-touch ' . ($since === $d ? 'btn-primary' : 'btn-light'), 'id="gaps-since-' . $d . '"') ?>
        <?php endforeach; ?>
    </nav>
    <h6 class="mb-2">What the base could not answer</h6>
    <?php if ($gaps === []): ?><div class="card mb-3"><div class="card-body text-muted" id="gaps-empty">Every question in this time had an answer or its passages.</div></div><?php endif; ?>
    <div class="row g-3 mb-4" id="gaps-cards"><?php foreach ($gaps as $g): ?><?= view('questions/partials/gap-card.php', ['g' => $g, 'b' => $b, 'tz' => $tz]) ?><?php endforeach; ?></div>
    <h6 class="mb-2">Thumbs down</h6>
    <div class="card" id="gaps-thumbs-down"><div class="list-group list-group-flush">
        <?php if ($down === []): ?><div class="list-group-item text-muted fs-13">No thumbs down in this time.</div><?php endif; ?>
        <?php foreach ($down as $f): ?><?= view('questions/partials/thumbs-down-row.php', ['f' => $f, 'tz' => $tz]) ?><?php endforeach; ?>
    </div></div>
</div>
