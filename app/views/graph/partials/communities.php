<?php /** The communities region (or, while MaluDB has none, the connected subjects). Data: b, o, here */
$id = (int) $b['base_id']; $err = $o['errors']; ?>
<div class="card mb-3" id="graph-communities"><div class="card-body">
    <h6 class="mb-2">Communities</h6>
    <?php if (isset($err['communities'])): ?>
        <?= view('graph/partials/engine-error.php', ['region' => 'communities', 'code' => $err['communities']]) ?>
    <?php elseif ($o['communities'] !== []): ?>
        <div class="row g-3">
            <?php foreach ($o['communities'] as $c): ?>
                <div class="col-12 col-md-6 col-lg-4"><div class="card h-100 border"><div class="card-body d-flex flex-column">
                    <div class="fw-semibold text-break mb-1"><?= e($c['label'] !== '' ? $c['label'] : 'Community ' . (int) $c['community_id']) ?></div>
                    <div class="fs-12 text-muted mb-2"><?= (int) $c['size'] ?> member<?= (int) $c['size'] === 1 ? '' : 's' ?></div>
                    <?= hx_link(with_back(graph_url($id, 'community', ['community' => (int) $c['community_id']]), $here), 'Open', 'btn btn-light btn-touch mt-auto', 'id="graph-community-' . (int) $c['community_id'] . '"') ?>
                </div></div></div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="text-muted fs-13 mb-2" id="graph-communities-empty">MaluDB has not grouped this base's subjects into communities yet; it will when community detection is added. The most connected subjects are shown instead.</p>
        <?= view('graph/partials/connected.php', ['b' => $b, 'o' => $o, 'here' => $here]) ?>
    <?php endif; ?>
</div></div>
