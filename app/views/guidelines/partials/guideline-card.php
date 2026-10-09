<?php /** A guideline as a card (guidelines). Data: g, may */
$gid = (int) $g['guideline_id']; $inactive = empty($g['is_active']); ?>
<div class="card mb-2<?= $inactive ? ' opacity-50' : '' ?>" id="guideline-card-<?= $gid ?>"><div class="card-body p-3">
    <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
        <span class="fw-semibold me-1"><?= hx_link('/guidelines/' . $gid, e($g['title'] !== '' ? $g['title'] : GUIDELINE_KINDS[$g['kind']][0] . ' guideline'), 'text-dark', 'id="guideline-card-' . $gid . '-link"') ?></span>
        <?= chip(guideline_origin_chip($g)) ?>
        <?= chip(['v' . (int) $g['version_no'], 'light']) ?>
        <?php if ($inactive): ?><?= chip(['inactive', 'dark']) ?><?php endif; ?>
    </div>
    <div class="fs-12 text-muted kn-prewrap"><?= e(guideline_excerpt((string) $g['body'])) ?></div>
    <?php if ($may['manage']): ?><div class="mt-2"><?= hx_link('/guidelines/' . $gid . '/edit', 'Edit', 'btn btn-light btn-sm btn-touch', 'id="guideline-card-' . $gid . '-edit"') ?></div><?php endif; ?>
</div></div>
