<?php /** The pages of a source, each linking to the first section on it (source-view). Data: pages */ ?>
<div class="card mb-3" id="source-pages"><div class="card-header"><h5 class="card-title mb-0">Pages</h5></div><div class="card-body d-flex flex-wrap gap-2">
    <?php foreach ($pages as $p): ?>
        <?php if ($p['first_section_id'] !== null): ?>
            <?= hx_link('/sections/' . (int) $p['first_section_id'], 'p. ' . (int) $p['page_no'], 'btn btn-light btn-touch', 'id="source-page-' . (int) $p['page_no'] . '"') ?>
        <?php else: ?>
            <span class="btn btn-light btn-touch disabled" id="source-page-<?= (int) $p['page_no'] ?>">p. <?= (int) $p['page_no'] ?></span>
        <?php endif; ?>
    <?php endforeach; ?>
</div></div>
