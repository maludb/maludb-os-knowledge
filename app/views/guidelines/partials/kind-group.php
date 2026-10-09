<?php /** One kind's guidelines (guidelines). Data: kind, rows, base_id, may */
[$label, $line] = GUIDELINE_KINDS[$kind]; ?>
<section class="mb-4" id="guidelines-kind-<?= e($kind) ?>">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <h6 class="fw-bold mb-0"><?= e($label) ?></h6>
        <?php if (in_array($kind, GUIDELINE_SHAPING, true)): ?><?= chip(['shapes the graph', 'warning']) ?><?php endif; ?>
    </div>
    <div class="fs-12 text-muted mb-2"><?= e($line) ?></div>
    <?php if ($rows === []): ?><div class="fs-12 text-muted mb-2" id="guidelines-kind-<?= e($kind) ?>-empty">None.</div><?php endif; ?>
    <?php foreach ($rows as $g): ?><?= view('guidelines/partials/guideline-card.php', ['g' => $g, 'may' => $may]) ?><?php endforeach; ?>
    <?php if ($may['manage']): ?><?= hx_link('/guidelines/new?base=' . (int) $base_id . '&kind=' . $kind, '<i class="feather-plus me-1"></i>Add a ' . strtolower($label) . ' guideline', 'btn btn-light btn-touch', 'id="guidelines-add-' . e($kind) . '"') ?><?php endif; ?>
</section>
