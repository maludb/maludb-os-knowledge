<?php /** The most connected region. Data: b, o, here */
$id = (int) $b['base_id']; ?>
<div class="card mb-3" id="graph-god-nodes"><div class="card-body">
    <h6 class="mb-2">Most connected</h6>
    <?php if (isset($o['errors']['god_nodes'])): ?>
        <?= view('graph/partials/engine-error.php', ['region' => 'god-nodes', 'code' => $o['errors']['god_nodes']]) ?>
    <?php elseif ($o['god_nodes'] === []): ?>
        <div class="text-muted fs-13" id="graph-god-nodes-empty">Nothing is connected yet — the most connected subjects and sections appear once a build has read the sources.</div>
    <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($o['god_nodes'] as $i => $n): [$icon, $colour] = graph_kind($n['kind']);
                $url = $n['kind'] === 'subject' ? graph_subject_url($id, $n['name']) : '/sections/' . (int) $n['id']; ?>
                <?= hx_link(with_back($url, $here), '<span class="d-flex align-items-center gap-2 w-100"><i class="' . $icon . ' text-' . $colour . '"></i><span class="text-break">' . e($n['name']) . '</span>'
                    . ($n['kind'] === 'section' ? '<span class="badge bg-light text-dark border">section</span>' : '') . '<span class="ms-auto fs-12 text-muted">' . (int) ($n['degree'] ?? 0) . ' link' . ((int) ($n['degree'] ?? 0) === 1 ? '' : 's') . '</span></span>',
                    'list-group-item list-group-item-action kn-row-link', 'id="graph-god-node-' . ($i + 1) . '"') ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div></div>
