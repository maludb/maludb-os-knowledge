<?php /** A subject's neighbours (confined). Data: id (base), r (graph_subject), here */ ?>
<div class="card mb-3" id="subject-neighbours"><div class="card-body">
        <h6 class="mb-2">Neighbours</h6>
        <?php if ($r['neighbours'] === []): ?>
            <div class="text-muted fs-13" id="subject-neighbours-empty">No other subject of this base is linked to this one.</div>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($r['neighbours'] as $i => $n): ?>
                    <?= hx_link(with_back(graph_subject_url($id, $n['name']), $here), '<span class="d-flex align-items-center gap-2 w-100"><i class="feather-circle text-primary"></i><span class="text-break">' . e($n['name']) . '</span>'
                        . ($n['verb'] !== null ? '<span class="fs-12 text-muted">' . e($n['verb']) . '</span>' : '') . '<span class="ms-auto fs-12 text-muted">' . (int) $n['documents'] . ' document' . ((int) $n['documents'] === 1 ? '' : 's') . '</span></span>',
                        'list-group-item list-group-item-action kn-row-link', 'id="subject-neighbour-' . ($i + 1) . '"') ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($r['neighbours_more']): ?><div class="fs-12 text-muted mt-2" id="subject-neighbours-more">and more — only the first 40 were checked against this base.</div><?php endif; ?>
    </div></div>
