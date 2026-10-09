<?php /** An area as a card (areas). Data: a, may, here */
$aid = (int) $a['focus_area_id']; $first = trim((string) strtok((string) $a['description'], "\n")); ?>
<div class="card h-100 mb-0<?= $a['status'] === 'archived' ? ' opacity-75' : '' ?>" id="area-card-<?= $aid ?>"><div class="card-body d-flex flex-column">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="fw-semibold fs-14 min-w-0 text-truncate"><?= hx_link(with_back('/areas/' . $aid, $here), e($a['name']), 'text-dark', 'id="area-card-' . $aid . '-link"') ?></div>
        <?php if ($a['status'] !== 'active'): ?><?= chip(area_status_chip($a['status'])) ?><?php endif; ?>
    </div>
    <?php if ($first !== ''): ?><div class="fs-12 text-muted text-truncate"><?= e($first) ?></div><?php endif; ?>
    <div class="d-flex flex-wrap gap-1 mt-2 fs-12"><?php foreach (array_slice($a['seed_terms_list'], 0, 8) as $t): ?><?= chip([$t, 'light']) ?><?php endforeach; ?></div>
    <div class="fs-12 text-muted mt-auto pt-2"><?= (int) $a['source_count'] ?> source<?= (int) $a['source_count'] === 1 ? '' : 's' ?> · <?= count($a['example_questions_list']) ?> example question<?= count($a['example_questions_list']) === 1 ? '' : 's' ?>
        <?php if ($may['curate'] && $a['status'] === 'active'): ?> · <?= chip(area_link_chip($a), 'area-card-' . $aid . '-linked') ?><?php endif; ?></div>
</div></div>
