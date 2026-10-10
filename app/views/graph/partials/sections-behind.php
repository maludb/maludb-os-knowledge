<?php /** The sections behind a subject. Data: id (base), r (graph_subject), here */ ?>
<div class="card mb-3" id="subject-sections"><div class="card-body">
        <h6 class="mb-2">The sections behind it</h6>
        <?php if ($r['sections'] === []): ?>
            <div class="text-muted fs-13" id="subject-sections-empty">No section of this base is tied to this subject.</div>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($r['sections'] as $i => $x): ?>
                    <?= hx_link(with_back('/sections/' . (int) $x['section_id'], $here), '<span class="d-flex flex-wrap align-items-center gap-2 w-100"><i class="feather-file-text text-secondary"></i><span class="text-break">' . e($x['heading_path']) . '</span>'
                        . '<span class="ms-auto fs-12 text-muted text-break">' . e($x['source']['title']) . '</span>' . ($x['page'] !== null ? '<span class="badge bg-light text-dark border">p. ' . (int) $x['page'] . '</span>' : '') . '</span>',
                        'list-group-item list-group-item-action kn-row-link', 'id="subject-section-' . ($i + 1) . '"') ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div></div>
