<?php /** A base's counts at a glance (base-view). Data: o (kn_base_overview) */
$src = $o['sources'] ?? []; $sec = $o['sections'] ?? [];
$cells = [
    ['Sources', (int) ($src['total'] ?? 0), ((int) ($src['pending'] ?? 0)) > 0 ? (int) $src['pending'] . ' being read' : (((int) ($src['failed'] ?? 0)) > 0 ? (int) $src['failed'] . ' failed' : '')],
    ['Sections', (int) ($sec['total'] ?? 0), ((int) ($sec['stale'] ?? 0)) > 0 ? (int) $sec['stale'] . ' to rebuild' : ''],
    ['Characters', number_format((int) ($sec['characters'] ?? 0)), ''],
    ['Subjects', (int) ($o['subjects_count'] ?? 0), (int) ($o['statements_count'] ?? 0) . ' statements'],
]; ?>
<div class="row g-2 mb-3" id="base-counts">
    <?php foreach ($cells as $i => [$label, $value, $note]): ?>
        <div class="col-6 col-md-3"><div class="card mb-0 h-100"><div class="card-body p-3" id="base-count-<?= strtolower($label) ?>">
            <div class="fs-11 text-muted text-uppercase"><?= e($label) ?></div>
            <div class="fs-5 fw-semibold"><?= e((string) $value) ?></div>
            <?php if ($note !== ''): ?><div class="fs-11 text-muted"><?= e($note) ?></div><?php endif; ?>
        </div></div></div>
    <?php endforeach; ?>
</div>
