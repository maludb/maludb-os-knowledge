<?php /** One community (screen `community-view`). Data: b, c (graph_community), here, back */
$id = (int) $b['base_id']; $cm = $c['community']; $label = (string) ($cm['label'] ?? '') !== '' ? (string) $cm['label'] : 'Community ' . (int) $cm['community_id']; ?>
<?= view('shared/header.php', ['id' => 'community-view', 'title' => $label, 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $id], ['Graph', '/bases/' . $id . '/graph'], [$label, null]],
    'back' => $back ?? ['/bases/' . $id . '/graph', 'the graph']]) ?>
<div class="main-content" id="community-view-content">
    <div class="card mb-3" id="community-head"><div class="card-body">
        <h5 class="mb-1 text-break" id="community-label"><?= e($label) ?></h5>
        <?php if (isset($c['errors']['engine'])): ?>
            <?= view('graph/partials/engine-error.php', ['region' => 'community', 'code' => $c['errors']['engine']]) ?>
        <?php else: ?>
            <div class="fs-12 text-muted mb-3" id="community-size"><?= (int) $cm['size'] ?> member<?= (int) $cm['size'] === 1 ? '' : 's' ?></div>
            <?= hx_link(graph_ask_url($id, $label), '<i class="feather-message-circle me-1"></i>Ask about this', 'btn btn-primary btn-touch', 'id="community-ask"') ?>
        <?php endif; ?>
    </div></div>
<?php if (!isset($c['errors']['engine'])): ?>
    <div class="card mb-3" id="community-members"><div class="card-body">
        <h6 class="mb-2">Members</h6>
        <?php if ($c['members'] === []): ?>
            <div class="text-muted fs-13" id="community-members-empty">No member of this community is in this base's documents.</div>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($c['members'] as $i => $n): [$icon, $colour] = graph_kind($n['kind']);
                    $url = $n['kind'] === 'subject' ? graph_subject_url($id, $n['name']) : '/sections/' . (int) $n['id']; ?>
                    <?= hx_link(with_back($url, $here), '<span class="d-flex align-items-center gap-2 w-100"><i class="' . $icon . ' text-' . $colour . '"></i><span class="text-break">' . e($n['name']) . '</span></span>',
                        'list-group-item list-group-item-action kn-row-link', 'id="community-member-' . ($i + 1) . '"') ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($c['more']): ?><div class="fs-12 text-muted mt-2" id="community-members-more">and more — only the first 40 subjects were checked against this base.</div><?php endif; ?>
    </div></div>
<?php endif; ?>
</div>
