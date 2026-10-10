<?php /** The pinned answers across my bases (screen `pinned`). Data: pins, base (row|null), due, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'pinned', 'title' => 'Pinned answers', 'crumbs' => [['Home', '/'], ['Pinned', null]], 'back' => back_link()]) ?>
<div class="main-content" id="pinned-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <nav class="kn-chips mb-3" id="pinned-filter" aria-label="Which">
        <?= hx_link('/pinned' . ($base !== null ? '?base=' . (int) $base['base_id'] : ''), 'All', 'btn btn-touch ' . (!$due ? 'btn-primary' : 'btn-light'), 'id="pinned-all"') ?>
        <?= hx_link('/pinned?due=1' . ($base !== null ? '&base=' . (int) $base['base_id'] : ''), 'Due for review', 'btn btn-touch ' . ($due ? 'btn-primary' : 'btn-light'), 'id="pinned-due"') ?>
    </nav>
    <?php if ($pins === []): ?><div class="card"><div class="card-body text-muted" id="pinned-empty"><?= $due ? 'No pinned answer is due for review.' : 'No answer is pinned yet. A curator pins a good answer from the question.' ?></div></div><?php endif; ?>
    <div class="row g-3" id="pinned-cards"><?php foreach ($pins as $p): ?><?= view('pinned/partials/pin-card.php', ['p' => $p, 'tz' => $tz]) ?><?php endforeach; ?></div>
</div>
