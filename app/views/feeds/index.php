<?php /** The feeds a base follows (screen `feeds`). Data: b, feeds, may, tabs, tz, notice */
$id = (int) $b['base_id']; ?>
<?= view('shared/header.php', ['id' => 'feeds', 'title' => 'Feeds', 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $id], ['Feeds', null]], 'back' => back_link(),
    'action' => $may['follow'] ? hx_link('/feeds/new?base=' . $id, '<i class="feather-plus me-1"></i>Follow a feed', 'btn btn-primary btn-touch', 'id="feeds-add-btn"') : '']) ?>
<div class="main-content" id="feeds-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('bases/partials/base-tabs.php', ['tabs' => $tabs, 'active' => 'feeds']) ?>
    <div class="fs-12 text-muted mb-3" id="feeds-intro">A feed brings what another application publishes — a Spaces space, Help Desk's published articles — in as sources, one per page or article, kept up to date. It reads them through the operating system, and only once a super-admin has approved the connection there.</div>
    <?php if ($feeds === []): ?>
        <div class="card"><div class="card-body text-muted" id="feeds-none">This base follows no feed.<?= $may['follow'] ? '' : ' No sibling application is declared as a source of feeds.' ?></div></div>
    <?php else: ?>
        <div class="card d-none d-md-block"><div class="table-responsive"><table class="table table-hover mb-0" id="feeds-table">
            <thead><tr><th>Feed</th><th>Area</th><th>Syncs</th><th>Last sync</th><th>Items</th><th></th></tr></thead>
            <tbody><?php foreach ($feeds as $f): ?><?= view('feeds/partials/feed-row.php', ['f' => $f, 'may' => $may, 'tz' => $tz]) ?><?php endforeach; ?></tbody></table></div></div>
        <div class="d-md-none" id="feeds-cards"><?php foreach ($feeds as $f): ?><?= view('feeds/partials/feed-row.php', ['f' => $f, 'may' => $may, 'tz' => $tz, 'card' => true]) ?><?php endforeach; ?></div>
    <?php endif; ?>
</div>
