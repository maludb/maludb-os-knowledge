<?php /** The connected subjects (the communities region's stand-in). Data: b, o, here */
$id = (int) $b['base_id']; ?>
<div id="graph-connected">
    <?php if (isset($o['errors']['connected'])): ?>
        <?= view('graph/partials/engine-error.php', ['region' => 'connected', 'code' => $o['errors']['connected']]) ?>
    <?php elseif ($o['connected'] === []): ?>
        <div class="text-muted fs-13" id="graph-connected-empty">No subjects in the graph yet — they appear once a build has read the sources.</div>
    <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($o['connected'] as $i => $n): ?>
                <?= hx_link(with_back(graph_subject_url($id, $n['name']), $here), '<span class="d-flex align-items-center gap-2 w-100"><i class="feather-circle text-primary"></i><span class="text-break">' . e($n['name']) . '</span><span class="ms-auto fs-12 text-muted">' . (int) $n['degree'] . ' link' . ((int) $n['degree'] === 1 ? '' : 's') . '</span></span>',
                    'list-group-item list-group-item-action kn-row-link', 'id="graph-connected-' . ($i + 1) . '"') ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
